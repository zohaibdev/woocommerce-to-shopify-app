<?php

namespace App\Support;

use App\Migration\MigrationRunner;
use App\Models\MigrationBatch;
use App\Models\MigrationItem;
use App\Models\MigrationLog;
use App\Models\StoreMigration;
use App\Services\ShopifyPlan;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * The whole state of one migration, written for whoever has to debug it.
 *
 * Deliberately NOT the merchant's log. That one is written in plain language and
 * says what happened to their data; this one says what the SYSTEM was doing —
 * which worker ran what, where the seconds went, how many chunks are parked, what
 * the concurrency ceiling actually resolved to, and which config values were in
 * force. The two audiences want opposite things from the same run, and trying to
 * serve both is how the merchant's log filled up with numbers they could not act
 * on.
 *
 * Compiled on demand from data already being written (migration_logs at `debug`
 * level, see MigrationDiagnostics), so it costs nothing when nobody is looking
 * and there is no second store of truth to keep in step.
 */
class MigrationDeveloperLog
{
    /** Debug rows scanned for the aggregate. Bounded: a long run has thousands. */
    private const AGGREGATE_LIMIT = 5000;

    /** A worker is "recently seen" if it wrote a line inside this window. */
    private const WORKER_WINDOW_MINUTES = 15;

    public function __construct(private readonly StoreMigration $migration) {}

    public static function for(StoreMigration $migration): self
    {
        return new self($migration);
    }

    public function filename(): string
    {
        return "store-shift-debug-migration-{$this->migration->id}.txt";
    }

    /**
     * Stream the report. Streamed rather than built in memory because the log
     * section is unbounded — a 50k-record run with a systemic failure has a line
     * per record, and building that as one string is how a debug tool becomes the
     * outage.
     */
    public function stream(): void
    {
        $out = fn (string $text = '') => print($text."\n");

        $this->header($out);
        $this->migrationSection($out);
        $this->shopSection($out);
        $this->runtimeSection($out);
        $this->queueSection($out);
        $this->throughputSection($out);
        $this->timeBreakdownSection($out);
        $this->workersSection($out);
        $this->batchesSection($out);
        $this->failuresSection($out);
        $this->logSection($out);
    }

    /**
     * The same picture as a structure, for the admin panel's live view.
     *
     * Everything except the unbounded log tail, which the panel pages through on
     * its own.
     */
    public function snapshot(): array
    {
        $aggregate = $this->aggregate();

        return [
            'migration_id' => $this->migration->id,
            'shop' => $this->migration->shop?->shopify_domain,
            'status' => $this->migration->status,
            'started_at' => $this->migration->started_at,
            'completed_at' => $this->migration->completed_at,
            'elapsed_seconds' => $this->elapsedSeconds(),
            'debug_logging_enabled' => MigrationDiagnostics::enabled(),
            'concurrency' => $this->concurrency(),
            'runtime' => $this->runtime(),
            'queue' => [
                'pending_jobs' => QueueKeeper::pending(),
                'failed_jobs' => $this->failedJobs(),
            ],
            'throughput' => $this->throughput(),
            'totals' => $aggregate['totals'],
            'workers' => array_values($aggregate['workers']),
            'outcomes' => $aggregate['outcomes'],
            'slowest_records' => $aggregate['slowest'],
            'notes' => $aggregate['notes'],
            'batches' => $this->batchSummary(),
            'source_health' => SourceHealth::snapshot($this->migration->id),
        ];
    }

    // -----------------------------------------------------------------
    // Sections
    // -----------------------------------------------------------------

    private function header(callable $out): void
    {
        $out('STORE SHIFT — DEVELOPER LOG');
        $out('Generated '.now()->toDateTimeString().' UTC');
        $out(str_repeat('=', 78));

        if (! MigrationDiagnostics::enabled()) {
            $out();
            $out('!! MIGRATION_DEBUG_LOG is OFF, so the per-job sections below are empty for');
            $out('!! anything that ran while it was off. Set MIGRATION_DEBUG_LOG=true and run');
            $out('!! `php artisan config:cache` to collect them.');
        }
    }

    private function migrationSection(callable $out): void
    {
        $m = $this->migration;

        $out();
        $out('MIGRATION');
        $this->pairs($out, [
            'id' => $m->id,
            'name' => $m->name ?: '-',
            'shop' => ($m->shop?->shopify_domain ?? '?').' (shop_id '.$m->shop_id.')',
            'status' => $m->status,
            'created' => (string) $m->created_at,
            'started' => (string) ($m->started_at ?? '-'),
            'completed' => (string) ($m->completed_at ?? '-'),
            'elapsed' => $this->humanDuration($this->elapsedSeconds()),
            'planned_at' => (string) ($m->planned_at ?? 'NOT YET — still listing ids'),
            'entities' => implode(', ', $m->selected_entities ?? []),
            'options' => json_encode($m->options ?: new \stdClass),
        ]);
    }

    private function shopSection(callable $out): void
    {
        $out();
        $out('SHOPIFY LIMITS & CONCURRENCY');
        $this->pairs($out, $this->concurrency());
        $out();
        $out('  A run only ever fills slots for the entity it has currently REACHED —');
        $out('  parallelism is within one entity, never across them. If "effective now"');
        $out('  is lower than "derived from API budget", a per-entity ceiling is why.');
    }

    private function runtimeSection(callable $out): void
    {
        $out();
        $out('RUNTIME / CONFIG');
        $this->pairs($out, $this->runtime());
    }

    private function queueSection(callable $out): void
    {
        $out();
        $out('QUEUE');
        $this->pairs($out, [
            'pending jobs' => QueueKeeper::pending(),
            'failed jobs' => $this->failedJobs(),
            'self-starter allowed' => QueueKeeper::canSpawn() ? 'yes' : 'NO (proc_open disabled)',
            'auto worker enabled' => config('queue.auto_worker', true) ? 'yes' : 'no',
            'auto worker pool size' => config('queue.auto_workers', 2),
        ]);
    }

    private function throughputSection(callable $out): void
    {
        $out();
        $out('THROUGHPUT');
        $out(sprintf('  %-12s %8s %8s %8s %8s %10s', 'entity', 'total', 'done', 'ok', 'failed', 'rec/min'));

        foreach ($this->throughput() as $row) {
            $out(sprintf(
                '  %-12s %8d %8d %8d %8d %10s',
                $row['entity'],
                $row['total'],
                $row['processed'],
                $row['successful'],
                $row['failed'],
                $row['records_per_minute'] ?? '-',
            ));
        }

        $out();
        $out('  rec/min is measured over the whole elapsed run, so it includes time spent');
        $out('  on OTHER entities earlier in the dependency order.');
    }

    private function timeBreakdownSection(callable $out): void
    {
        $aggregate = $this->aggregate();
        $totals = $aggregate['totals'];

        $out();
        $out('WHERE THE TIME WENT  (summed over '.$totals['jobs'].' job(s))');

        if ($totals['jobs'] === 0) {
            $out('  No per-job diagnostics recorded for this run.');

            return;
        }

        $this->pairs($out, [
            'job wall clock' => $this->humanDuration($totals['elapsed_seconds']),
            'shopify calls' => $this->callLine($totals['shopify_calls'], $totals['shopify_seconds']),
            'woocommerce calls' => $this->callLine($totals['woo_calls'], $totals['woo_seconds']),
            'woocommerce failures' => $totals['woo_failures'],
            'throttle pauses' => $totals['throttle_waits'].' ('.$this->humanDuration($totals['throttle_seconds']).' asleep)',
            'shopify 429s' => $totals['shopify_429'],
            'cost-budget throttles' => $totals['shopify_cost_throttled'],
            'soft throttles (orderCreate)' => $totals['shopify_soft_throttled'],
            'self-paced waits' => $totals['pace_waits'].' ('.$this->humanDuration($totals['pace_seconds']).')',
            'page cache' => $totals['page_cache_hits'].' hit / '.$totals['page_cache_misses'].' MISS',
            'records handled' => $totals['records'].' ('.$this->humanDuration($totals['records_seconds']).' in migrators)',
        ]);

        $out();

        foreach ($this->diagnose($totals) as $line) {
            $out('  >> '.$line);
        }

        if ($aggregate['outcomes']) {
            $out();
            $out('  How those jobs ended:');

            foreach ($aggregate['outcomes'] as $outcome => $count) {
                $out(sprintf('    %-40s %d', $outcome, $count));
            }
        }

        if ($aggregate['slowest']) {
            $out();
            $out('  Slowest single records:');

            foreach ($aggregate['slowest'] as $row) {
                $out(sprintf('    #%-12s %6.2fs   %s', $row['id'], $row['seconds'], $row['at']));
            }
        }

        if ($aggregate['notes']) {
            $out();
            $out('  Recurring observations:');

            foreach ($aggregate['notes'] as $note => $count) {
                $out(sprintf('    %-60s ×%d', $note, $count));
            }
        }
    }

    private function workersSection(callable $out): void
    {
        $workers = $this->aggregate()['workers'];

        $out();
        $out('WORKERS THAT TOUCHED THIS RUN');

        if (! $workers) {
            $out('  None recorded.');

            return;
        }

        $out(sprintf('  %-28s %8s %12s %s', 'host:pid', 'jobs', 'busy', 'last seen'));

        foreach ($workers as $worker) {
            $out(sprintf(
                '  %-28s %8d %12s %s',
                $worker['worker'],
                $worker['jobs'],
                $this->humanDuration($worker['seconds']),
                $worker['last_seen'],
            ));
        }

        $recent = collect($workers)->where('recent', true)->count();

        $out();
        $out('  '.count($workers).' distinct process(es) over the whole run; '.$recent
            .' active in the last '.self::WORKER_WINDOW_MINUTES.' minutes.');
        $out('  Compare that with the concurrency section: if fewer processes are busy than');
        $out('  workers are running, the run is CEILINGED, not starved — the extra workers');
        $out('  have nothing they are allowed to pick up.');
    }

    private function batchesSection(callable $out): void
    {
        $out();
        $out('CHUNKS');

        foreach ($this->batchSummary() as $entity => $counts) {
            $out(sprintf(
                '  %-12s %s',
                $entity,
                collect($counts)->map(fn ($n, $status) => "{$status}={$n}")->implode('  '),
            ));
        }

        // The individual rows that are not moving. A completed chunk is noise
        // here; a chunk sitting at "processing" with an old timestamp is the
        // entire reason someone opened this file.
        $stuck = MigrationBatch::where('migration_id', $this->migration->id)
            ->whereIn('status', ['processing', 'failed'])
            ->orderBy('id')
            ->limit(100)
            ->get();

        if ($stuck->isEmpty()) {
            return;
        }

        $out();
        $out('  In flight or failed (up to 100):');
        $out(sprintf('    %-10s %-6s %-11s %-9s %-9s %-21s %s',
            'entity', 'batch', 'status', 'attempts', 'offset', 'updated_at', 'error'));

        foreach ($stuck as $b) {
            $out(sprintf(
                '    %-10s %-6d %-11s %-9d %-9s %-21s %s',
                $b->entity_type,
                $b->batch_number,
                $b->status,
                $b->attempts,
                $b->processed_offset.'/'.(is_array($b->source_ids) ? count($b->source_ids) : $b->per_page),
                (string) $b->updated_at,
                $b->error ? mb_substr($b->error, 0, 120) : '-',
            ));
        }
    }

    private function failuresSection(callable $out): void
    {
        $query = MigrationItem::where('migration_id', $this->migration->id)->where('status', 'failed');
        $total = $query->count();

        $out();
        $out("FAILED RECORDS ({$total})");

        if ($total === 0) {
            return;
        }

        // Grouped by reason: on a systemic failure the useful fact is "9,412
        // records, one cause", and listing them individually buries it.
        $out('  By reason:');

        $query->clone()
            ->selectRaw('entity_type, error, count(*) as aggregate')
            ->groupBy('entity_type', 'error')
            ->orderByDesc('aggregate')
            ->limit(30)
            ->get()
            ->each(fn ($row) => $out(sprintf(
                '    %-10s ×%-7d %s',
                $row->entity_type,
                $row->aggregate,
                mb_substr((string) $row->error, 0, 150),
            )));

        $out();
        $out('  First 200 by id:');

        $query->clone()->orderBy('id')->limit(200)->get()->each(fn ($f) => $out(sprintf(
            '    [%s] woo #%d: %s',
            $f->entity_type,
            $f->woocommerce_id,
            mb_substr((string) $f->error, 0, 200),
        )));
    }

    private function logSection(callable $out): void
    {
        $out();
        $out('FULL LOG (oldest first, every level including debug)');

        MigrationLog::where('migration_id', $this->migration->id)
            ->orderBy('id')
            ->chunk(500, function ($rows) use ($out) {
                foreach ($rows as $log) {
                    $out(sprintf(
                        '  %s [%-7s] %-9s %s',
                        $log->created_at,
                        strtoupper($log->level),
                        $log->entity_type ?? '-',
                        $log->message,
                    ));

                    // Only for debug rows: the context of an info/warning line is
                    // already in its sentence, but a debug line's numbers are the
                    // whole point of it.
                    if ($log->level === 'debug' && is_array($log->context)) {
                        $out('            '.json_encode($log->context));
                    }
                }
            });
    }

    // -----------------------------------------------------------------
    // Data
    // -----------------------------------------------------------------

    /**
     * Every number the per-job debug rows carry, rolled up.
     *
     * Memoised because four sections ask for it and the scan is the most
     * expensive thing in this file.
     */
    private ?array $aggregate = null;

    private function aggregate(): array
    {
        if ($this->aggregate !== null) {
            return $this->aggregate;
        }

        $totals = array_fill_keys([
            'jobs', 'elapsed_seconds', 'records', 'records_seconds',
            'shopify_calls', 'shopify_seconds', 'woo_calls', 'woo_seconds', 'woo_failures',
            'throttle_waits', 'throttle_seconds', 'shopify_429', 'shopify_cost_throttled',
            'shopify_soft_throttled', 'pace_waits', 'pace_seconds',
            'page_cache_hits', 'page_cache_misses',
        ], 0);

        $workers = [];
        $outcomes = [];
        $notes = [];
        $slowest = [];
        $recentAfter = now()->subMinutes(self::WORKER_WINDOW_MINUTES);

        MigrationLog::where('migration_id', $this->migration->id)
            ->where('level', 'debug')
            ->orderByDesc('id')
            ->limit(self::AGGREGATE_LIMIT)
            ->chunk(500, function ($rows) use (&$totals, &$workers, &$outcomes, &$notes, &$slowest, $recentAfter) {
                foreach ($rows as $row) {
                    $context = is_array($row->context) ? $row->context : [];

                    $totals['jobs']++;

                    foreach ($totals as $key => $_) {
                        if ($key !== 'jobs' && isset($context[$key])) {
                            $totals[$key] += $context[$key];
                        }
                    }

                    $worker = (string) ($context['worker'] ?? 'unknown');
                    $workers[$worker] ??= [
                        'worker' => $worker,
                        'jobs' => 0,
                        'seconds' => 0.0,
                        'last_seen' => (string) $row->created_at,
                        'recent' => $row->created_at instanceof Carbon
                            ? $row->created_at->gte($recentAfter)
                            : false,
                    ];
                    $workers[$worker]['jobs']++;
                    $workers[$worker]['seconds'] += (float) ($context['elapsed_seconds'] ?? 0);

                    $outcome = (string) ($context['outcome'] ?? 'unknown');
                    $outcomes[$outcome] = ($outcomes[$outcome] ?? 0) + 1;

                    foreach ((array) ($context['notes'] ?? []) as $note) {
                        $notes[(string) $note] = ($notes[(string) $note] ?? 0) + 1;
                    }

                    if (isset($context['slowest_record'])) {
                        $slowest[] = [
                            'id' => $context['slowest_record'],
                            'seconds' => (float) ($context['slowest_record_seconds'] ?? 0),
                            'at' => (string) $row->created_at,
                        ];
                    }
                }
            });

        usort($slowest, fn ($a, $b) => $b['seconds'] <=> $a['seconds']);
        arsort($outcomes);
        arsort($notes);

        foreach ($totals as $key => $value) {
            $totals[$key] = str_ends_with($key, '_seconds') ? round((float) $value, 1) : (int) $value;
        }

        return $this->aggregate = [
            'totals' => $totals,
            'workers' => $workers,
            'outcomes' => $outcomes,
            'notes' => array_slice($notes, 0, 15, true),
            'slowest' => array_slice($slowest, 0, 10),
        ];
    }

    /**
     * The one part of this file that draws a conclusion rather than a number.
     *
     * Every line here corresponds to a fix somebody can actually apply. A report
     * that only reports leaves the reader to know which of eight numbers matters,
     * and the whole reason this exists is that they did not.
     */
    private function diagnose(array $totals): array
    {
        $findings = [];
        $accounted = max(0.001, $totals['elapsed_seconds']);

        $share = fn (float $seconds) => round(($seconds / $accounted) * 100);

        if ($totals['throttle_seconds'] > 0 && $share($totals['throttle_seconds']) >= 20) {
            $findings[] = $share($totals['throttle_seconds']).'% of job time was spent ASLEEP waiting out '
                .'Shopify throttles. This is Shopify pacing the store, not the app being slow. Orders are '
                .'the usual cause — orderCreate has its own small rate limit.';
        }

        if ($totals['woo_seconds'] > 0 && $share($totals['woo_seconds']) >= 30) {
            $findings[] = $share($totals['woo_seconds']).'% of job time was spent waiting on the merchant\'s '
                .'WooCommerce store ('.$this->callLine($totals['woo_calls'], $totals['woo_seconds']).'). '
                .'The bottleneck is their host.';
        }

        if ($totals['shopify_seconds'] > 0 && $share($totals['shopify_seconds']) >= 50) {
            $findings[] = $share($totals['shopify_seconds']).'% of job time was spent inside Shopify calls '
                .'('.$this->callLine($totals['shopify_calls'], $totals['shopify_seconds']).'). Large product '
                .'images are the usual reason — productSet downloads every one before it returns.';
        }

        $cacheReads = $totals['page_cache_hits'] + $totals['page_cache_misses'];

        if ($cacheReads > 5 && $totals['page_cache_misses'] / $cacheReads > 0.4) {
            $findings[] = 'Chunk pages are mostly NOT being cached ('.$totals['page_cache_misses'].' of '
                .$cacheReads.' reads missed), so every resume re-downloads its whole chunk from WooCommerce. '
                .'Raise the database max_allowed_packet, raise MIGRATION_MAX_CACHED_PAGE_BYTES, or lower '
                .'MIGRATION_PER_PAGE.';
        }

        if ($totals['pace_seconds'] > 0 && $share($totals['pace_seconds']) >= 15) {
            $findings[] = $share($totals['pace_seconds']).'% of job time was self-paced backoff to stay inside '
                .'the shop\'s GraphQL point budget. That budget is set by the merchant\'s Shopify plan.';
        }

        if ($totals['woo_failures'] > 0) {
            $findings[] = $totals['woo_failures'].' WooCommerce request(s) failed outright. Check the source '
                .'store is online and not rate-limiting the app.';
        }

        return $findings ?: ['Nothing stands out — no single stage dominates the run\'s time.'];
    }

    private function concurrency(): array
    {
        $shop = $this->migration->shop;
        $derived = $shop ? app(ShopifyPlan::class)->concurrencyFor($shop) : 1;

        $tier = $shop ? app(ShopifyPlan::class)->tier($shop) : 'standard';

        // Resolved rather than dumped raw: the config now holds a per-tier map,
        // and printing that map would leave the operator to work out which row
        // applies to the shop they are looking at.
        $ceilings = collect((array) config('migration.entity_concurrency', []))
            ->map(fn ($_, $entity) => ShopifyPlan::entityCeilingForTier($tier, $entity))
            ->all();

        // What the run is ACTUALLY allowed right now — the entity it has reached
        // decides, and that is the number an operator is trying to reconcile
        // against the processes they can see.
        $current = MigrationRunner::nextBatch($this->migration->id)?->entity_type;

        return [
            'shopify plan' => $shop?->shopify_plan ?: 'unknown',
            'api tier (drives the order ceiling)' => $tier,
            'api bucket size' => $shop?->api_bucket_size ?: 'not measured',
            'api restore rate' => ($shop?->api_restore_rate ?: 'not measured').' pts/s',
            'limits measured at' => (string) ($shop?->limits_checked_at ?? 'never'),
            'derived from API budget' => $derived,
            'hard ceiling (MIGRATION_MAX_CONCURRENCY)' => config('migration.max_concurrency', 8),
            'per-entity ceilings' => $ceilings
                ? collect($ceilings)->map(fn ($n, $e) => "{$e}={$n}")->implode(', ')
                : 'none',
            'entity in progress' => $current ?? '-',
            'effective now' => $current && $this->migration->shop
                ? MigrationRunner::concurrencyFor($this->migration, $current)
                : '-',
        ];
    }

    private function runtime(): array
    {
        return [
            'php' => PHP_VERSION,
            'laravel' => app()->version(),
            'environment' => app()->environment(),
            'queue driver' => config('queue.default'),
            'cache driver' => config('cache.default'),
            'queue retry_after' => config('queue.connections.'.config('queue.default').'.retry_after', '-'),
            'shopify api version' => config('services.shopify.api_version'),
            'MIGRATION_PER_PAGE' => config('migration.per_page'),
            'MIGRATION_MAX_CACHED_PAGE_BYTES' => number_format((int) config('migration.max_cached_page_bytes')),
            'soft throttle backoff' => implode('s, ', (array) config('migration.soft_throttle_backoff', [])).'s',
            'MIGRATION_DEBUG_LOG' => MigrationDiagnostics::enabled() ? 'on' : 'OFF',
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function throughput(): array
    {
        $elapsedMinutes = $this->elapsedSeconds() / 60;

        return $this->migration->entities->map(fn ($e) => [
            'entity' => $e->entity_type,
            'total' => (int) $e->total_items,
            'processed' => (int) $e->processed_items,
            'successful' => (int) $e->successful_items,
            'failed' => (int) $e->failed_items,
            'records_per_minute' => $elapsedMinutes > 1
                ? round($e->processed_items / $elapsedMinutes, 1)
                : null,
        ])->values()->all();
    }

    /** @return array<string, array<string, int>> entity => status => count */
    private function batchSummary(): array
    {
        return MigrationBatch::where('migration_id', $this->migration->id)
            ->selectRaw('entity_type, status, count(*) as aggregate')
            ->groupBy('entity_type', 'status')
            ->get()
            ->groupBy('entity_type')
            ->map(fn ($rows) => $rows->pluck('aggregate', 'status')->map(fn ($n) => (int) $n)->all())
            ->all();
    }

    private function failedJobs(): int
    {
        try {
            return (int) app('queue.failer')?->count();
        } catch (Throwable) {
            return 0;
        }
    }

    private function elapsedSeconds(): float
    {
        $start = $this->migration->started_at ?? $this->migration->created_at;

        if (! $start) {
            return 0.0;
        }

        return (float) ($this->migration->completed_at ?? now())->diffInSeconds($start, absolute: true);
    }

    // -----------------------------------------------------------------
    // Formatting
    // -----------------------------------------------------------------

    private function pairs(callable $out, array $rows): void
    {
        foreach ($rows as $label => $value) {
            $out(sprintf('  %-42s %s', $label, $value));
        }
    }

    private function callLine(int $calls, float $seconds): string
    {
        if ($calls === 0) {
            return '0';
        }

        return $calls.' calls, '.$this->humanDuration($seconds).' total, '
            .round($seconds / $calls, 2).'s avg';
    }

    private function humanDuration(float $seconds): string
    {
        $seconds = (int) round($seconds);

        if ($seconds < 60) {
            return $seconds.'s';
        }

        if ($seconds < 3600) {
            return intdiv($seconds, 60).'m '.($seconds % 60).'s';
        }

        return intdiv($seconds, 3600).'h '.intdiv($seconds % 3600, 60).'m';
    }
}
