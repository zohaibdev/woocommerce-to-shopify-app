<?php

namespace App\Support;

use App\Migration\MigrationRunner;
use Throwable;

/**
 * Where a migration's time actually goes, recorded per QUEUE JOB.
 *
 * The app could already say a run was slow (SourceHealth) and which records
 * failed (migration_logs). It could not answer the question an operator actually
 * asks when a production migration crawls: *what is it doing with the time?* A
 * run at four records a minute looks identical whether it is waiting on a slow
 * WordPress host, waiting out Shopify throttles, re-downloading the same page
 * because the cache will not hold it, or simply running two workers where the
 * operator believes eight are running. Those have four different fixes and no
 * signal in the app distinguished them.
 *
 * So every job accumulates counters and timers IN PROCESS and writes exactly one
 * `debug` row when it ends. In process is the whole point: a shared counter in
 * the cache would be a database round trip per record on the production cache
 * store — telemetry that slows down the thing it is measuring is worse than no
 * telemetry. One row per job is roughly one row per 35 seconds of work.
 *
 * Nothing here may ever throw. It is called from inside the item loop and from
 * inside the HTTP clients, and a migration must not be able to die of being
 * watched.
 */
class MigrationDiagnostics
{
    /** Job currently being measured, or null when nothing is. */
    private static ?int $migrationId = null;

    private static ?string $entityType = null;

    /** Human label for the unit of work, e.g. "batch 12". */
    private static string $unit = '';

    private static float $startedAt = 0.0;

    /** @var array<string, int> */
    private static array $counts = [];

    /** @var array<string, float> seconds */
    private static array $timers = [];

    /** @var array<int, string> short free-text observations for this job */
    private static array $notes = [];

    /** The single slowest record this job handled: [id, seconds]. */
    private static ?array $slowest = null;

    /** Cached process identity — see worker(). */
    private static ?string $worker = null;

    public static function enabled(): bool
    {
        return (bool) config('migration.debug_log', true);
    }

    /**
     * Which process this is, as `host:pid`.
     *
     * The reason the developer log can answer "how many workers are actually
     * running?" — a question the queue itself will not answer on the database
     * driver, and the one the operator most often has a wrong belief about.
     */
    public static function worker(): string
    {
        return self::$worker ??= (gethostname() ?: 'host').':'.getmypid();
    }

    /**
     * Start measuring one job. Safe to call when diagnostics are off (everything
     * below then no-ops), and safe to call twice — the second call wins, which is
     * what you want when a job hands over to a successor in the same process.
     */
    public static function begin(int $migrationId, ?string $entityType, string $unit): void
    {
        if (! self::enabled()) {
            return;
        }

        self::$migrationId = $migrationId;
        self::$entityType = $entityType;
        self::$unit = $unit;
        self::$startedAt = microtime(true);
        self::$counts = [];
        self::$timers = [];
        self::$notes = [];
        self::$slowest = null;
    }

    /** Count something (`shopify_calls`, `throttles`, `records`, …). */
    public static function bump(string $key, int $by = 1): void
    {
        if (self::$migrationId === null) {
            return;
        }

        self::$counts[$key] = (self::$counts[$key] ?? 0) + $by;
    }

    /** Add to a running total of seconds. */
    public static function add(string $key, float $seconds): void
    {
        if (self::$migrationId === null) {
            return;
        }

        self::$timers[$key] = round((self::$timers[$key] ?? 0.0) + $seconds, 3);
    }

    /**
     * Run a callable, recording both how many times it ran and how long it took.
     *
     * The timing is recorded in a finally block on purpose: a Shopify call that
     * throws a throttle is exactly the call whose duration matters, and losing it
     * would make throttled jobs look free.
     *
     * @template T
     *
     * @param  callable():T  $fn
     * @return T
     */
    public static function time(string $key, callable $fn)
    {
        if (self::$migrationId === null) {
            return $fn();
        }

        $startedAt = microtime(true);

        try {
            return $fn();
        } finally {
            self::bump($key.'_calls');
            self::add($key.'_seconds', microtime(true) - $startedAt);
        }
    }

    /** One short observation, e.g. "page cache MISS". Deduplicated. */
    public static function note(string $text): void
    {
        if (self::$migrationId === null || in_array($text, self::$notes, true)) {
            return;
        }

        // Bounded: a job that finds something to say about every record must not
        // build a log line nobody can read.
        if (count(self::$notes) < 8) {
            self::$notes[] = $text;
        }
    }

    /**
     * Offer a record's duration as a candidate for "slowest in this job".
     *
     * An average hides the record that took ninety seconds, and that record is
     * usually the whole story — a product with forty images, an order Shopify
     * refused four times while shedding fields.
     */
    public static function record(int $sourceId, float $seconds): void
    {
        if (self::$migrationId === null) {
            return;
        }

        self::bump('records');
        self::add('records_seconds', $seconds);

        if (self::$slowest === null || $seconds > self::$slowest[1]) {
            self::$slowest = [$sourceId, round($seconds, 2)];
        }
    }

    /**
     * Write the job's line and stop measuring.
     *
     * `$outcome` says how the job ENDED — completed, handed over on its time
     * budget, parked on a throttle, gave up — which is what turns a pile of
     * timings into a story about the run.
     */
    public static function finish(string $outcome): void
    {
        $migrationId = self::$migrationId;

        if ($migrationId === null) {
            return;
        }

        try {
            $elapsed = round(microtime(true) - self::$startedAt, 2);

            $context = [
                'worker' => self::worker(),
                'unit' => self::$unit,
                'outcome' => $outcome,
                'elapsed_seconds' => $elapsed,
            ] + self::$counts + self::$timers;

            if (self::$slowest) {
                $context['slowest_record'] = self::$slowest[0];
                $context['slowest_record_seconds'] = self::$slowest[1];
            }

            if (self::$notes) {
                $context['notes'] = self::$notes;
            }

            MigrationRunner::log(
                $migrationId,
                'debug',
                self::$entityType,
                self::summarise($outcome, $elapsed),
                $context,
            );
        } catch (Throwable) {
            // Measuring the run must never be able to end it.
        } finally {
            self::$migrationId = null;
        }
    }

    /**
     * Abandon the current measurement without writing anything — for a job that
     * turned out to have no work to do.
     */
    public static function discard(): void
    {
        self::$migrationId = null;
    }

    /** The one line an operator reads first. Everything else is in `context`. */
    private static function summarise(string $outcome, float $elapsed): string
    {
        $parts = [self::$unit.' '.$outcome.' in '.$elapsed.'s'];

        if ($records = self::$counts['records'] ?? 0) {
            $parts[] = $records.' record'.($records === 1 ? '' : 's');
        }

        foreach (['shopify' => 'shopify', 'woo' => 'woo'] as $key => $label) {
            if ($calls = self::$counts[$key.'_calls'] ?? 0) {
                $parts[] = $label.' '.$calls.'×'.round(self::$timers[$key.'_seconds'] ?? 0, 1).'s';
            }
        }

        if ($waits = self::$counts['throttle_waits'] ?? 0) {
            $parts[] = 'throttled '.$waits.'× (waiting '.round(self::$timers['throttle_seconds'] ?? 0).'s)';
        }

        if (self::$slowest && self::$slowest[1] >= 5) {
            $parts[] = 'slowest #'.self::$slowest[0].' '.self::$slowest[1].'s';
        }

        foreach (self::$notes as $note) {
            $parts[] = $note;
        }

        return implode(' · ', $parts);
    }
}
