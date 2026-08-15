<?php

namespace App\Support;

use App\Models\StoreMigration;

/**
 * How long a migration will take, before it starts and while it runs.
 *
 * A migration of any size runs for hours, and "we will email you when it is
 * done" is not an answer to "can I close my laptop, or is this five minutes?".
 * Without a number, the only signal a merchant has is a progress bar that barely
 * moves, which reads as broken rather than slow.
 *
 * TWO THINGS MAKE THIS HONEST RATHER THAN A GUESS.
 *
 * 1. RECORDS ARE NOT INTERCHANGEABLE. A product is a productSet that runs
 *    SYNCHRONOUSLY while Shopify downloads every one of its images, plus a
 *    separate WooCommerce read for its variations. A coupon is one small
 *    mutation. Treating them as one unit of work meant an estimate built from a
 *    catalogue of coupons and blog posts, then applied to products, was wrong by
 *    an order of magnitude. So each entity carries a WEIGHT, and everything
 *    below is arithmetic on weighted work rather than on record counts.
 *
 * 2. THE LIVE ESTIMATE CALIBRATES ITSELF. The static weights cannot know that
 *    this merchant's WooCommerce host answers in nine seconds, or that their
 *    products have twenty photos each. Once a run has done enough work to have
 *    an opinion, the estimate is derived from ITS OWN elapsed time — so it
 *    converges on the truth for that store instead of insisting on an average.
 *
 * The pre-start number is deliberately the rougher of the two and is presented
 * as such; its job is to distinguish "minutes" from "overnight".
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
        // orderCreate with line items, addresses, transactions and tax lines, and
        // rate limited separately by Shopify (which is why it runs near-serial).
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
     *
     * A product on a healthy pair of stores lands around here. It is only ever
     * the starting point: the moment a run has real progress, measured() takes
     * over. Tunable because the right number depends on typical image sizes more
     * than on anything in this app.
     */
    private const BASELINE_SECONDS_PER_UNIT = 2.5;

    /**
     * Weighted work that must be done before a measured rate is trusted.
     *
     * Below this the sample is dominated by whatever the run happens to have
     * started with — the categories at the front of the dependency order are
     * quick, and extrapolating a whole catalogue from them promised merchants
     * twenty minutes for a job that took six hours.
     */
    private const MIN_SAMPLE_UNITS = 25.0;

    /** And enough wall-clock that per-record noise has averaged out. */
    private const MIN_SAMPLE_SECONDS = 20.0;

    public static function weightFor(string $entityType): float
    {
        return self::WEIGHTS[$entityType] ?? self::DEFAULT_WEIGHT;
    }

    /**
     * Estimated seconds for ONE entity's records.
     *
     * Given per entity as well as in total because the wizard's summary has to
     * answer for whatever the merchant actually ticked, and the preview is
     * computed before they have ticked anything. Summing these is sound: the
     * spread across workers is a division, so the parts add up to the whole.
     */
    public static function secondsFor(string $entityType, int $count, int $workers): int
    {
        return self::spread(
            max(0, $count) * self::weightFor($entityType) * self::BASELINE_SECONDS_PER_UNIT,
            $workers,
        );
    }

    /**
     * Estimate for a run that has not started, from the wizard's own counts.
     *
     * @param  array<string, int>  $countsByEntity  what will actually be migrated
     * @param  int  $workers  batches this shop may run in parallel
     * @return array{seconds: int, basis: string, workers: int}|null
     */
    public static function forPlan(array $countsByEntity, int $workers): ?array
    {
        $units = 0.0;

        foreach ($countsByEntity as $entityType => $count) {
            $units += max(0, (int) $count) * self::weightFor((string) $entityType);
        }

        if ($units <= 0) {
            return null;
        }

        return [
            'seconds' => self::spread($units * self::BASELINE_SECONDS_PER_UNIT, $workers),
            'basis' => 'plan',
            'workers' => max(1, $workers),
        ];
    }

    /**
     * Estimate for a run in flight.
     *
     * Falls back to the static model until the run has done enough to speak for
     * itself, so the number never disappears and never jumps about early on.
     *
     * @return array{seconds_remaining: int, basis: string, workers: int, records_per_minute: float|null}|null
     */
    public static function forRun(StoreMigration $migration, int $workers): ?array
    {
        $done = 0.0;
        $total = 0.0;
        $recordsDone = 0;
        $recordsTotal = 0;

        foreach ($migration->entities as $entity) {
            $weight = self::weightFor($entity->entity_type);

            // Capped because processed can briefly exceed a total that planning
            // later revised down, and a negative remainder reads as "finished"
            // on a run that plainly is not.
            $processed = min((int) $entity->processed_items, (int) $entity->total_items);

            $done += $processed * $weight;
            $total += (int) $entity->total_items * $weight;
            $recordsDone += $processed;
            $recordsTotal += (int) $entity->total_items;
        }

        if ($total <= 0) {
            return null;
        }

        $remaining = max(0.0, $total - $done);

        if ($remaining <= 0.0) {
            return [
                'seconds_remaining' => 0,
                'basis' => 'progress',
                'workers' => max(1, $workers),
                'records_per_minute' => null,
            ];
        }

        $elapsed = $migration->started_at
            ? max(0.0, (float) now()->diffInSeconds($migration->started_at, absolute: true))
            : 0.0;

        // Measured: this run's own pace, which already includes the merchant's
        // host, their image sizes, their Shopify plan and however many workers
        // are really running. Nothing here has to be assumed.
        if ($done >= self::MIN_SAMPLE_UNITS && $elapsed >= self::MIN_SAMPLE_SECONDS) {
            $secondsPerUnit = $elapsed / $done;

            return [
                'seconds_remaining' => (int) round($remaining * $secondsPerUnit),
                'basis' => 'progress',
                'workers' => max(1, $workers),
                'records_per_minute' => $elapsed > 0
                    ? round($recordsDone / ($elapsed / 60), 1)
                    : null,
            ];
        }

        // Too early to have an opinion of its own — the static model, spread over
        // the workers the run is allowed to use.
        return [
            'seconds_remaining' => self::spread($remaining * self::BASELINE_SECONDS_PER_UNIT, $workers),
            'basis' => 'plan',
            'workers' => max(1, $workers),
            'records_per_minute' => null,
        ];
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
