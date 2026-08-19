<?php

namespace App\Support;

use App\Models\MigrationItem;
use App\Models\StoreMigration;
use App\Services\ShopifyPlan;
use Illuminate\Support\Carbon;

/**
 * How long a migration will take, before it starts and while it runs.
 *
 * A migration of any size runs for hours, and "we will email you when it is
 * done" is not an answer to "can I close my laptop, or is this five minutes?".
 * Without a number, the only signal a merchant has is a progress bar that barely
 * moves, which reads as broken rather than slow.
 *
 * THREE THINGS MAKE THIS HONEST RATHER THAN A GUESS.
 *
 * 1. RECORDS ARE NOT INTERCHANGEABLE. A product is a productSet that runs
 *    SYNCHRONOUSLY while Shopify downloads every one of its images. A coupon is
 *    one small mutation. Each entity carries a WEIGHT, and everything below is
 *    arithmetic on weighted work rather than on record counts.
 *
 * 2. ENTITIES DO NOT ALL GET THE SAME PARALLELISM. Orders are rate limited by
 *    Shopify separately from the GraphQL point budget, so they run under a
 *    per-entity ceiling (config migration.entity_concurrency) however many
 *    workers exist. Dividing the whole run by the shop's worker count promised a
 *    speed the order phase is structurally incapable of reaching.
 *
 * 3. THE LIVE ESTIMATE MEASURES EACH ENTITY SEPARATELY, AND RECENTLY.
 *
 *    This is the part that was wrong, and it was wrong in the worst direction.
 *    The estimate used to be one number — total elapsed divided by total
 *    weighted work done — which is a CUMULATIVE average over the whole run. On a
 *    real store that is catastrophic: ten thousand products land in an hour
 *    (~166/min), then orders start and manage four a minute. The cumulative
 *    average is still dominated by the product phase, so the app cheerfully
 *    predicted minutes for a phase that had eight hours left, and then walked
 *    that prediction upward for the rest of the day. An estimate that is wrong
 *    is survivable; one that is confidently wrong and keeps moving is worse than
 *    none.
 *
 *    So pace is measured PER ENTITY, from that entity's own mapping rows, and
 *    preferentially over a recent WINDOW rather than the entity's whole life —
 *    which is also what makes it react to a store that has started throttling.
 *    Entities that have not started yet are priced from the model, calibrated by
 *    how wrong the model turned out to be on the entities we HAVE measured.
 */
class MigrationEstimate
{
    /**
     * Relative cost of one record, with a product as 1.0.
     *
     * Derived from what each entity actually does per record rather than from
     * timings on one store — a ratio survives a slow host, a stopwatch reading
     * does not.
     */
    private const WEIGHTS = [
        // productSet, synchronous, waits for Shopify to fetch every image; plus a
        // variations read for variable products.
        'product' => 1.0,
        // One small mutation, but see ORDERS_PER_MINUTE_CEILING: what an order
        // actually costs is set by Shopify's rate limiter, not by this number.
        'order' => 0.7,
        // A full review set is read from WooCommerce per product, then one
        // metafield write.
        'review' => 0.5,
        // articleCreate plus a featured image Shopify downloads.
        'article' => 0.45,
        // customerCreate with addresses; no media.
        'customer' => 0.3,
        // collectionCreate/Update, one image at most.
        'category' => 0.25,
        'coupon' => 0.2,
        'blog' => 0.2,
    ];

    private const DEFAULT_WEIGHT = 0.5;

    /**
     * Seconds one unit of weighted work takes on ONE worker, before we have
     * measured anything.
     */
    private const BASELINE_SECONDS_PER_UNIT = 2.5;

    /**
     * Orders a shop can be expected to create per minute IN TOTAL, whatever the
     * worker count.
     *
     * Shopify rate limits orderCreate separately from the GraphQL point budget
     * and reports the refusal as an ordinary userError, so the app cannot read
     * this ceiling anywhere — it can only be observed. On the store this was
     * calibrated against, 2,500 orders took about nine hours while 10,000
     * products (with images) took one; the weighted model predicted well under
     * an hour for the orders, because it costed an order at 0.7 of a product and
     * then divided by eight workers that orders are never allowed to use.
     *
     * Applied as a FLOOR on the order phase rather than as a weight, because it
     * is a throughput limit rather than a per-record cost: it does not improve
     * with more workers, which is precisely what a weight would imply. The live
     * estimate overrides it as soon as the run has measured itself.
     */
    private const ORDERS_PER_MINUTE_CEILING = 6.0;

    /**
     * How far back the live pace is measured.
     *
     * Long enough that one slow record does not move the number, short enough
     * that the estimate reacts when a phase changes character — which, on a run
     * that goes from products to orders, it violently does.
     */
    private const ROLLING_WINDOW_SECONDS = 900;

    /** Records inside the window before the rolling pace is trusted. */
    private const MIN_ROLLING_RECORDS = 8;

    /** Records over an entity's whole life before its own pace is trusted. */
    private const MIN_LIFETIME_RECORDS = 5;

    /** And enough wall-clock that per-record noise has averaged out. */
    private const MIN_SAMPLE_SECONDS = 20.0;

    /**
     * How long a live measurement is reused.
     *
     * The details page polls every three seconds, and this is two grouped
     * queries over the run's mapping rows. On a 50,000-record run that is not
     * something to do twenty times a minute per open tab.
     */
    private const SNAPSHOT_TTL_SECONDS = 15;

    /**
     * Tier assumed when there is no shop to ask — the wizard's pre-start estimate
     * on a store whose API limits have not been measured yet. Deliberately the
     * most conservative one: quoting a Plus-speed number to a Basic store is the
     * direction that loses trust.
     */
    private const DEFAULT_TIER = 'standard';

    public static function weightFor(string $entityType): float
    {
        return self::WEIGHTS[$entityType] ?? self::DEFAULT_WEIGHT;
    }

    /**
     * Workers ONE entity may actually use.
     *
     * The shop-wide number is an upper bound the order phase never reaches. Read
     * from the same config key as MigrationRunner::concurrencyFor(), so changing
     * the ceiling moves the estimate with it instead of leaving the two to
     * disagree.
     */
    public static function workersFor(string $entityType, int $workers, ?string $tier = null): int
    {
        // Resolved through ShopifyPlan so the estimate and the runner can never
        // disagree about what a shop is allowed. Reading the config directly here
        // was fine while the ceiling was one number; now that it varies by tier,
        // a raw (int) cast of the tier MAP would quietly evaluate to 1 and price
        // the order phase as fully serial.
        return max(1, min(
            max(1, $workers),
            ShopifyPlan::entityCeilingForTier($tier ?? self::DEFAULT_TIER, $entityType),
        ));
    }

    /**
     * Estimated seconds for ONE entity's records.
     *
     * Given per entity as well as in total because the wizard's summary has to
     * answer for whatever the merchant actually ticked, and the preview is
     * computed before they have ticked anything. Summing these is sound: the
     * entities run one after another, so their times add.
     */
    public static function secondsFor(string $entityType, int $count, int $workers, ?string $tier = null): int
    {
        $count = max(0, $count);

        if ($count === 0) {
            return 0;
        }

        $modelled = self::spread(
            $count * self::weightFor($entityType) * self::BASELINE_SECONDS_PER_UNIT,
            self::workersFor($entityType, $workers, $tier),
        );

        return max($modelled, self::rateLimitFloor($entityType, $count));
    }

    /**
     * A throughput ceiling the run cannot argue its way past, in seconds.
     *
     * Only orders have one today. Returning zero for everything else keeps this
     * out of the way of entities whose speed really is a function of workers.
     */
    private static function rateLimitFloor(string $entityType, int $count): int
    {
        if ($entityType !== 'order' || $count <= 0) {
            return 0;
        }

        return (int) ceil(($count / self::ORDERS_PER_MINUTE_CEILING) * 60);
    }

    /**
     * Estimate for a run that has not started, from the wizard's own counts.
     *
     * @param  array<string, int>  $countsByEntity  what will actually be migrated
     * @param  int  $workers  batches this shop may run in parallel
     * @return array{seconds: int, basis: string, workers: int}|null
     */
    public static function forPlan(array $countsByEntity, int $workers, ?string $tier = null): ?array
    {
        $seconds = 0;

        foreach ($countsByEntity as $entityType => $count) {
            $seconds += self::secondsFor((string) $entityType, (int) $count, $workers, $tier);
        }

        if ($seconds <= 0) {
            return null;
        }

        return [
            'seconds' => $seconds,
            'basis' => 'plan',
            'workers' => max(1, $workers),
        ];
    }

    /**
     * Estimate for a run in flight.
     *
     * @return array{seconds_remaining: int, basis: string, workers: int, records_per_minute: float|null}|null
     */
    public static function forRun(StoreMigration $migration, int $workers): ?array
    {
        $entities = $migration->entities;

        if ($entities->isEmpty()) {
            return null;
        }

        $remainingByEntity = [];
        $anyRemaining = false;

        foreach ($entities as $entity) {
            $total = (int) $entity->total_items;

            // Capped because processed can briefly exceed a total that planning
            // later revised down, and a negative remainder reads as "finished"
            // on a run that plainly is not.
            $processed = min((int) $entity->processed_items, $total);
            $remaining = max(0, $total - $processed);

            $remainingByEntity[$entity->entity_type] = $remaining;
            $anyRemaining = $anyRemaining || $remaining > 0;
        }

        if (! $anyRemaining) {
            return [
                'seconds_remaining' => 0,
                'basis' => 'progress',
                'workers' => self::reportedWorkers($migration, $workers),
                'records_per_minute' => null,
            ];
        }

        $measured = self::measuredPace($migration);
        $calibration = self::calibration($measured, $workers);

        $seconds = 0.0;
        $usedMeasurement = false;

        foreach ($remainingByEntity as $entityType => $remaining) {
            if ($remaining <= 0) {
                continue;
            }

            $pace = $measured[$entityType]['seconds_per_record'] ?? null;

            if ($pace !== null) {
                // This entity's own recent pace. The merchant's host, their image
                // sizes, their Shopify plan and whatever throttling is happening
                // right now are all already inside this number.
                $seconds += $remaining * $pace;
                $usedMeasurement = true;

                continue;
            }

            // Not started yet. Price it from the model, scaled by how wrong the
            // model turned out to be on the entities that HAVE run — a store
            // running at half the modelled speed will keep doing so.
            $seconds += self::secondsFor((string) $entityType, $remaining, $workers, self::tierOf($migration)) * $calibration;
        }

        return [
            'seconds_remaining' => (int) max(1, round($seconds)),
            // "progress" is a claim that the number came from this run, and the
            // UI words it differently because of that. It is only earned when an
            // entity with work LEFT measured itself.
            'basis' => $usedMeasurement ? 'progress' : 'plan',
            'workers' => self::reportedWorkers($migration, $workers),
            'records_per_minute' => self::currentRecordsPerMinute($measured),
        ];
    }

    /**
     * Workers to SHOW the merchant.
     *
     * The shop-wide ceiling is not what the run is using: during the order phase
     * a shop entitled to eight gets two, because orders are rate limited
     * separately. Reporting eight there is how "8 parallel workers" in the UI and
     * three busy processes on the server ended up describing the same run.
     */
    private static function reportedWorkers(StoreMigration $migration, int $workers): int
    {
        $active = $migration->entities
            ->first(fn ($e) => (int) $e->processed_items < (int) $e->total_items)?->entity_type;

        return $active
            ? self::workersFor($active, $workers, self::tierOf($migration))
            : max(1, $workers);
    }

    /** This shop's Shopify tier, or the conservative default when unknown. */
    private static function tierOf(StoreMigration $migration): string
    {
        return $migration->shop
            ? app(ShopifyPlan::class)->tier($migration->shop)
            : self::DEFAULT_TIER;
    }

    /**
     * Seconds per record for every entity this run has measurable progress on.
     *
     * Two grouped passes: a recent WINDOW (preferred — it reacts to a phase
     * change and to a store that has started throttling) and the entity's whole
     * life (the fallback for an entity that has just started or has gone quiet).
     *
     * @return array<string, array{seconds_per_record: float, recent: int}>
     */
    private static function measuredPace(StoreMigration $migration): array
    {
        $cacheKey = "migration:{$migration->id}:pace";
        $cached = SafeCache::get($cacheKey);

        if (is_array($cached)) {
            return $cached;
        }

        $since = now()->subSeconds(self::ROLLING_WINDOW_SECONDS);

        $lifetime = MigrationItem::query()
            ->where('migration_id', $migration->id)
            ->selectRaw('entity_type, count(*) as records, min(created_at) as first_at, max(created_at) as last_at')
            ->groupBy('entity_type')
            ->get();

        $recent = MigrationItem::query()
            ->where('migration_id', $migration->id)
            ->where('created_at', '>=', $since)
            ->selectRaw('entity_type, count(*) as records, min(created_at) as first_at')
            ->groupBy('entity_type')
            ->get()
            ->keyBy('entity_type');

        $paces = [];

        foreach ($lifetime as $row) {
            $entityType = (string) $row->entity_type;
            $window = $recent->get($entityType);

            $pace = self::paceFrom(
                (int) ($window->records ?? 0),
                $window->first_at ?? null,
                // Open-ended at the near side: the entity is still running, so
                // "now" is the right end of the window. Using the last mapping
                // row instead would make a stalled entity look infinitely fast.
                now(),
                self::MIN_ROLLING_RECORDS,
            ) ?? self::paceFrom(
                (int) $row->records,
                $row->first_at,
                $row->last_at,
                self::MIN_LIFETIME_RECORDS,
            );

            if ($pace !== null) {
                $paces[$entityType] = [
                    'seconds_per_record' => $pace,
                    'recent' => (int) ($window->records ?? 0),
                ];
            }
        }

        SafeCache::put($cacheKey, $paces, now()->addSeconds(self::SNAPSHOT_TTL_SECONDS));

        return $paces;
    }

    /** Seconds per record across a span, or null when the sample is too thin. */
    private static function paceFrom(int $records, $firstAt, $lastAt, int $minimum): ?float
    {
        if ($records < $minimum || ! $firstAt || ! $lastAt) {
            return null;
        }

        $seconds = Carbon::parse($lastAt)->diffInSeconds(Carbon::parse($firstAt), absolute: true);

        if ($seconds < self::MIN_SAMPLE_SECONDS) {
            return null;
        }

        return $seconds / max(1, $records);
    }

    /**
     * How wrong the static model is on this store, as a multiplier.
     *
     * Measured against the entities that have actually run, so an entity that has
     * not started is not priced as though it were on a fast host when everything
     * before it was on a slow one. Bounded, because one weird entity must not
     * turn a six-hour estimate into a six-day one.
     */
    private static function calibration(array $measured, int $workers): float
    {
        $ratios = [];

        foreach ($measured as $entityType => $data) {
            $modelled = self::secondsFor((string) $entityType, 1, $workers);

            if ($modelled > 0) {
                $ratios[] = $data['seconds_per_record'] / $modelled;
            }
        }

        if (empty($ratios)) {
            return 1.0;
        }

        return min(10.0, max(0.2, array_sum($ratios) / count($ratios)));
    }

    /**
     * Records a minute RIGHT NOW, across whatever is running.
     *
     * Deliberately the recent figure rather than the run's lifetime average: the
     * merchant is watching a phase, and the average of a fast phase that has
     * finished and a slow one that is running describes neither of them.
     */
    private static function currentRecordsPerMinute(array $measured): ?float
    {
        $perMinute = 0.0;

        foreach ($measured as $data) {
            if ($data['recent'] >= self::MIN_ROLLING_RECORDS && $data['seconds_per_record'] > 0) {
                $perMinute += 60 / $data['seconds_per_record'];
            }
        }

        return $perMinute > 0 ? round($perMinute, 1) : null;
    }

    /**
     * Serial seconds divided across the workers that will actually run.
     *
     * Not a clean division: a run is never perfectly parallel — the entities are
     * strictly ordered, so the shop's category batches finish before a single
     * product starts, and one straggling batch holds the tail. Charging a little
     * for that is better than promising a number the run cannot hit.
     */
    private static function spread(float $serialSeconds, int $workers): int
    {
        $workers = max(1, $workers);

        $efficiency = $workers === 1 ? 1.0 : 0.8;

        return (int) max(1, round($serialSeconds / ($workers * $efficiency)));
    }
}
