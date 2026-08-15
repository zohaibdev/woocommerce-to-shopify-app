<?php

namespace App\Jobs;

use App\Migration\MigrationItemResult;
use App\Migration\MigrationRunner;
use App\Migration\MigratorRegistry;
use App\Models\MigrationBatch;
use App\Models\MigrationEntity;
use App\Models\MigrationItem;
use App\Models\StoreMigration;
use App\Services\Exceptions\ShopifyThrottledException;
use App\Support\SafeCache;
use App\Support\SourceHealth;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Processes one chunk of one entity.
 *
 * Five properties make a run un-stallable:
 *
 *  1. EXCLUSIVE — the batch is claimed with a conditional UPDATE, so two workers
 *     can never hold the same chunk. Batches run in parallel, which made this
 *     mandatory rather than merely tidy (see claim()).
 *  2. LEASED — a job that hands work to its own successor passes the claim along
 *     as a fencing token instead of releasing it, so the chunk is never briefly
 *     free for someone else to pick up (see $lease).
 *  3. RESUMABLE — every item writes an ABSOLUTE processed_offset, so a worker
 *     killed mid-chunk resumes at the next item instead of replaying the chunk.
 *  4. TIME-BOUNDED — the job stops itself well before the queue's retry_after and
 *     hands the remainder to a fresh job, so the queue never decides the job is
 *     dead and runs a second copy of it concurrently.
 *  5. SELF-ADVANCING — however this job ends (done, failed, out of retries) it
 *     hands the migration to MigrationRunner::advance(). One broken batch can no
 *     longer take the whole migration down with it.
 *
 * A chunk is addressed by a fixed list of WooCommerce ids rather than by page
 * number wherever the source allows it — see sourceIds(). That is what keeps a
 * 50k catalogue from getting slower the deeper it goes, and what stops a product
 * deleted mid-run from shifting a later page out from under a resume.
 */
class ProcessBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * Hard kill time for the job. The budget below is only checked BETWEEN items,
     * so a single slow record — a variable product with many variations, its own
     * Woo call, and a media retry — can run well past it. At the 60s default that
     * killed the whole batch ("ProcessBatchJob has timed out"). Must stay under
     * the queue's retry_after (900s).
     */
    public int $timeout = 300;

    /**
     * Stop after this many seconds and continue in a new job. Must stay well under
     * both the worker's --max-time and the queue's retry_after.
     */
    private const TIME_BUDGET_SECONDS = 35;

    /** How often progress counters are republished while a chunk runs. */
    private const COUNTER_SYNC_SECONDS = 1.0;

    /** Records sampled to estimate a page's serialized size — see cachePage(). */
    private const SIZE_SAMPLE = 3;

    /**
     * Migration ids already told that their pages are not being cached.
     *
     * Static, so one worker draining two hundred batches of the same run says it
     * once rather than two hundred times. A per-process memory is exactly the
     * right scope: it cannot depend on the cache, which in this particular case
     * is the thing that may be broken.
     *
     * @var array<int, true>
     */
    private static array $cacheWarned = [];

    /**
     * How often the run's status is re-read while walking a chunk. Reading it per
     * item was a database round trip per record; the merchant will not notice two
     * seconds between pressing Cancel and the run stopping.
     */
    private const HALT_CHECK_SECONDS = 2.0;

    /**
     * Consecutive throttle pauses with NOTHING migrated in between, before this
     * batch is given up on.
     *
     * A throttle pause used to re-dispatch a brand new job, which reset the
     * attempt counter — so nothing anywhere bounded the loop. Any permanent error
     * that merely LOOKED like throttling parked the batch at sixty seconds a go,
     * forever, and the migration sat at "processing" with no way out. Counting
     * only the pauses that made no progress leaves a genuinely busy shop free to
     * throttle as often as it likes.
     */
    private const MAX_STALLED_THROTTLE_WAITS = 10;

    /** Backstop bound on a batch's claims when the cache is unavailable. */
    private const MAX_CLAIMS_WITHOUT_CACHE = 200;

    /** @var float when the run's status was last read (see isHalted) */
    private float $lastHaltCheck = 0.0;

    private bool $halted = false;

    /**
     * @param  int|null  $lease  the `attempts` value this job was handed the chunk
     *                           with. Set only when a previous job of this chunk
     *                           passed the work on to this one — see claim().
     */
    public function __construct(public int $batchId, public ?int $lease = null) {}

    /** Backoff between retries of a hard failure (e.g. WooCommerce unreachable). */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(MigratorRegistry $registry): void
    {
        $batch = MigrationBatch::find($this->batchId);

        if (! $batch) {
            return;
        }

        $migration = StoreMigration::with('connection', 'shop')->find($batch->migration_id);

        if (! $migration) {
            return;
        }

        // Cooperative cancellation: stop before doing any work and do NOT advance.
        if ($migration->isCancelledOrPaused()) {
            return;
        }

        // Every WooCommerce request made below this line is attributed to this
        // migration, which is how the merchant gets told their own host is slow.
        SourceHealth::within($migration->id);

        // Another worker already finished this chunk — just keep the run moving.
        if ($batch->status === 'completed') {
            MigrationRunner::advance($migration->id);

            return;
        }

        // Someone else is already on this chunk. Advancing is still right: it only
        // ever hands out batches nobody holds, so the run keeps flowing.
        if (! $this->claim($batch)) {
            MigrationRunner::advance($migration->id);

            return;
        }

        $batch->refresh();

        $migrator = $registry->make($batch->entity_type);

        $entity = MigrationEntity::firstOrCreate(
            ['migration_id' => $migration->id, 'entity_type' => $batch->entity_type],
        );

        try {
            $page = $this->fetchPage($migrator, $migration, $batch, $entity);
        } catch (Throwable $e) {
            $this->abandonOrRetry($migration, $batch, "Fetch failed: {$e->getMessage()}", $e);

            return;
        }

        // Resume: skip whatever a previous (killed) run of this chunk already did.
        // The page is a STABLE list — same ids, same order, on every resume — so
        // this position means the same thing each time it is read.
        $baseOffset = (int) $batch->processed_offset;
        $page = array_slice($page, $baseOffset);

        // Give the migrator a chance to do page-wide lookups in bulk (image sizes
        // above all) instead of one request per record. Deliberately after the
        // resume slice: a page usually takes several jobs to finish, and looking
        // up records this job will not touch is pure waste on every resume.
        if (method_exists($migrator, 'prefetchPage')) {
            $migrator->prefetchPage($migration, $this->records($page));
        }

        $startedAt = microtime(true);
        $lastSync = 0.0; // 0 so the first item always publishes progress immediately

        foreach ($page as $index => $entry) {
            if ($this->isHalted($migration)) {
                // Hand the chunk back. Left at "processing" it counts against the
                // shop's concurrency budget forever, so on a single-worker host a
                // resumed run had no free slot and went nowhere until the
                // watchdog noticed ten minutes later.
                $batch->update(['status' => 'pending']);

                return;
            }

            $wooId = (int) $entry['id'];
            $wooItem = $entry['item'];

            if ($wooItem === null) {
                // The id was pinned when this chunk was planned and WooCommerce no
                // longer returns it, so the merchant deleted it mid-run. Record it
                // rather than leaving a hole: the counters have to reach the total
                // or the run can never read as finished.
                $batch->update(['processed_offset' => $baseOffset + $index + 1]);
                $this->recordDeletedAtSource($migration, $batch->entity_type, $wooId);
            } else {
                try {
                    $result = $migrator->migrateItem($migration, $wooItem);
                } catch (ShopifyThrottledException $e) {
                    // Wait out the throttle window in a fresh job so this doesn't
                    // burn an attempt. The saved offset means we resume, not
                    // restart, and the lease means nobody else takes the chunk
                    // while we are waiting.
                    if (! $this->throttleBudgetRemains($batch)) {
                        $this->giveUpOnThrottle($migration, $batch);

                        return;
                    }

                    $this->continueInNewJob($batch, (int) ceil($e->retryAfterSeconds));

                    return;
                } catch (Throwable $e) {
                    // An unexpected error on ONE item is that item's failure, never
                    // the migration's. It is recorded and the run carries on.
                    $result = MigrationItemResult::failed($e->getMessage());
                }

                // Checkpoint before anything else so a kill here at worst replays
                // one idempotent item. Written as an ABSOLUTE position, never as an
                // increment: two workers incrementing the same row would race the
                // offset past items that were never migrated, and because counters
                // are derived from the mapping table those records would simply be
                // missing with nothing to show it.
                $batch->update(['processed_offset' => $baseOffset + $index + 1]);

                $this->recordResult($migration, $batch->entity_type, $wooId, $result);
            }

            // Keep progress live without a counter query per item. Syncing only at
            // the end of a chunk left the merchant staring at a frozen 0/10 for
            // half a minute; syncing every item costs a query per row on fast
            // entities. Once a second is smoother than the 3s UI poll either way.
            $now = microtime(true);
            if ($now - $lastSync >= self::COUNTER_SYNC_SECONDS) {
                MigrationRunner::syncCounters($migration, $entity);
                $lastSync = $now;
            }

            if ($now - $startedAt >= self::TIME_BUDGET_SECONDS) {
                MigrationRunner::syncCounters($migration, $entity);
                $this->continueInNewJob($batch);

                return;
            }
        }

        $batch->update(['status' => 'completed', 'error' => null]);
        $this->forgetPage($batch);
        SafeCache::forget($this->throttleKey($batch));

        MigrationRunner::syncCounters($migration, $entity);
        MigrationRunner::refreshEntityStatus($migration, $entity);
        MigrationRunner::advance($migration->id);
    }

    /**
     * Hand the rest of this chunk to a fresh job WITHOUT letting go of it.
     *
     * The status deliberately stays "processing" and the current `attempts` value
     * travels with the job as its lease. Flipping the row back to "pending" left a
     * window in which the chunk looked free: inFlight() undercounted, a concurrent
     * advance() handed the very same chunk out again, and the duplicate job — which
     * claim() correctly refuses — still cost a queued job, a database round trip
     * and another advance(). On a 500-batch run with eight workers that was
     * constant churn against exactly the shops with the least headroom.
     */
    private function continueInNewJob(MigrationBatch $batch, int $delaySeconds = 0): void
    {
        // Keeps the watchdog's "nothing has touched this in a while" clock honest
        // even when the chunk made no progress at all this time round.
        $batch->touch();

        $job = self::dispatch($batch->id, (int) $batch->attempts);

        if ($delaySeconds > 0) {
            $job->delay($delaySeconds);
        }
    }

    /**
     * Has the run been cancelled or paused? Answered from a cached read that is
     * refreshed every couple of seconds, because the honest answer costs a query
     * and this sits in the per-item hot path.
     */
    private function isHalted(StoreMigration $migration): bool
    {
        if ($this->halted) {
            return true;
        }

        $now = microtime(true);

        if ($now - $this->lastHaltCheck < self::HALT_CHECK_SECONDS) {
            return false;
        }

        $this->lastHaltCheck = $now;

        return $this->halted = $migration->isCancelledOrPaused();
    }

    /**
     * Whether this batch may pause for throttling again.
     *
     * Only pauses that migrated NOTHING since the last one count against the
     * budget: a large order batch on a small Shopify plan will legitimately be
     * throttled dozens of times, and each of those pauses is doing its job.
     */
    private function throttleBudgetRemains(MigrationBatch $batch): bool
    {
        $key = $this->throttleKey($batch);
        $state = SafeCache::get($key, ['waits' => 0, 'offset' => -1]);
        $offset = (int) $batch->processed_offset;

        $state = $offset > (int) ($state['offset'] ?? -1)
            ? ['waits' => 1, 'offset' => $offset]
            : ['waits' => (int) ($state['waits'] ?? 0) + 1, 'offset' => $offset];

        // With no working cache the counter cannot be kept, and an unbounded
        // throttle loop is the one thing this exists to prevent — so fall back to
        // the claim count, which lives in the database and is generous enough
        // never to trouble a batch that is genuinely making progress. Every
        // resume re-claims, so this keeps counting even across handovers.
        if (! SafeCache::put($key, $state, now()->addDay())) {
            return (int) $batch->attempts <= self::MAX_CLAIMS_WITHOUT_CACHE;
        }

        return $state['waits'] <= self::MAX_STALLED_THROTTLE_WAITS;
    }

    /**
     * Throttling that never clears is not throttling. Stop burning worker time on
     * this chunk, tell the merchant plainly, and let the rest of the run continue.
     */
    private function giveUpOnThrottle(StoreMigration $migration, MigrationBatch $batch): void
    {
        $batch->update([
            'status' => 'failed',
            'error' => 'Shopify kept rejecting this batch as rate limited and it stopped making progress.',
        ]);

        $this->forgetPage($batch);
        SafeCache::forget($this->throttleKey($batch));

        MigrationRunner::log(
            $migration->id,
            'error',
            $batch->entity_type,
            "Batch {$batch->batch_number} was rate limited ".self::MAX_STALLED_THROTTLE_WAITS
                .' times without migrating anything, so it was stopped. Use "Retry failed items" once your '
                .'Shopify store is less busy — the rest of this migration is continuing.',
        );

        MigrationRunner::advance($migration->id);
    }

    private function throttleKey(MigrationBatch $batch): string
    {
        return "migration:batch:{$batch->id}:throttle";
    }

    /**
     * Take exclusive ownership of the batch.
     *
     * Batches of the same entity run in parallel, and the watchdog can revive one
     * it believes is dead — so "read the row, then write processing" is not
     * enough: two workers both read `pending` and both proceed. A conditional
     * UPDATE lets the database decide, and exactly one caller sees a row count
     * of 1.
     *
     * A job carrying a LEASE is the designated continuation of a chunk that was
     * never released, so it may also re-take a row that is still "processing" —
     * but only while `attempts` is exactly what it was handed. If anything else
     * has claimed the chunk since (the watchdog revived it, a fresh dispatch took
     * it) that number has moved on, and this job stands down instead of becoming
     * a second worker on somebody else's chunk.
     */
    private function claim(MigrationBatch $batch): bool
    {
        $query = MigrationBatch::where('id', $batch->id);

        if ($this->lease === null) {
            $query->where('status', 'pending');
        } else {
            $query->where(function ($q) {
                $q->where('status', 'pending')
                    ->orWhere(fn ($held) => $held->where('status', 'processing')->where('attempts', $this->lease));
            });
        }

        return $query->update([
            'status' => 'processing',
            'attempts' => DB::raw('attempts + 1'),
            'updated_at' => now(),
        ]) === 1;
    }

    /**
     * The chunk this batch covers, as a STABLE list of
     * `['id' => int, 'item' => array|null]` in a fixed order.
     *
     * Two things are going on here.
     *
     * FIRST, the chunk is resolved to concrete WooCommerce ids once (sourceIds())
     * and fetched with `include=` from then on. Page/offset paging becomes a deep
     * SQL OFFSET inside WordPress, so page 500 of a 50k catalogue is both the
     * slowest query on the site and the one most likely to exceed the client's
     * timeout — and the 35s budget meant every resume paid it again. An id lookup
     * costs the same at any depth.
     *
     * SECOND, pinning the ids is what makes the list stable. Ordering by id kept
     * an INSERT during a run from disturbing pages already read, but a DELETE
     * shifted every later page one place left, and processed_offset is a position
     * within a page — so a record could fall through the gap between two resumes
     * and never be migrated, with nothing anywhere to show it. Ids decided up
     * front cannot shift; a record that disappears leaves a null in its own slot
     * and is recorded as deleted at source.
     *
     * The whole (uncapped) chunk is cached for the life of the batch. Slicing to
     * the plan allowance happens on the way out, so a plan upgrade mid-run is
     * picked up rather than baked into the cache.
     *
     * @return array<int, array{id: int, item: array|null}>
     */
    private function fetchPage($migrator, StoreMigration $migration, MigrationBatch $batch, MigrationEntity $entity): array
    {
        $key = $this->pageKey($batch);
        $cached = SafeCache::get($key);

        if (is_array($cached)) {
            return $this->capToAllowance($cached, $batch, $entity, $migration);
        }

        $ids = $this->sourceIds($migrator, $migration, $batch);

        if ($ids === null) {
            // This source cannot be addressed by id (or would not answer). Fall
            // back to ordinary paging — slower and still deletion-sensitive, but
            // it is what every migrator did before and it works.
            $page = [];

            foreach (array_values($migrator->fetchBatch($migration->connection, $batch->page, $batch->per_page)) as $item) {
                $page[] = ['id' => (int) $migrator->sourceId($item), 'item' => $item];
            }
        } else {
            $byId = [];

            foreach (array_chunk($ids, 100) as $chunk) {
                foreach ($migrator->fetchByIds($migration->connection, $chunk) as $item) {
                    $byId[(int) $migrator->sourceId($item)] = $item;
                }
            }

            $page = array_map(
                fn (int $id) => ['id' => $id, 'item' => $byId[$id] ?? null],
                $ids,
            );
        }

        $this->cachePage($key, $page, $migration);

        return $this->capToAllowance($page, $batch, $entity, $migration);
    }

    /**
     * The ids this batch owns, resolved once and remembered on the row.
     *
     * Costs one extra WooCommerce request the first time a batch runs, and saves
     * one full page download on every resume after that — a page routinely takes
     * several jobs to finish, so this is a saving even before the depth problem
     * it exists to solve.
     *
     * @return array<int, int>|null
     */
    private function sourceIds($migrator, StoreMigration $migration, MigrationBatch $batch): ?array
    {
        // Already pinned (including a legitimately empty page).
        if (is_array($batch->source_ids)) {
            return array_map('intval', $batch->source_ids);
        }

        if (! method_exists($migrator, 'listSourceIds')) {
            return null;
        }

        $ids = $migrator->listSourceIds($migration->connection, $batch->page, $batch->per_page);

        if ($ids === null) {
            return null;
        }

        // De-duplicate defensively: the mapping table would collapse a repeated id
        // anyway, but a duplicate here would inflate the chunk's length and leave
        // processed_offset pointing past the end of the real work.
        $ids = array_values(array_unique(array_map('intval', $ids)));

        $batch->update(['source_ids' => $ids]);

        return $ids;
    }

    /**
     * Trim the chunk to what the merchant's plan still allows.
     *
     * `total_items` is already capped to the plan at planning time, so this only
     * has to take the slice of the chunk that still fits under it. Without it a
     * 10-product plan would migrate a whole 100-item page.
     *
     * @param  array<int, array{id: int, item: array|null}>  $page
     * @return array<int, array{id: int, item: array|null}>
     */
    private function capToAllowance(array $page, MigrationBatch $batch, MigrationEntity $entity, StoreMigration $migration): array
    {
        $total = (int) $entity->total_items;

        // A batch exists, so planning found records for this entity — a zero total
        // means the entity row was lost or never written. Slicing to zero here
        // would quietly migrate NOTHING and mark the batch completed, which reads
        // as success. Say so and process the chunk instead.
        if ($total <= 0) {
            MigrationRunner::log(
                $migration->id,
                'warning',
                $batch->entity_type,
                "Batch {$batch->batch_number} found no planned total for {$batch->entity_type} — "
                    .'processing it in full rather than skipping it. Progress for this entity may read low.',
            );

            return $page;
        }

        $allowance = max(0, $total - (($batch->page - 1) * $batch->per_page));

        return array_slice($page, 0, min($batch->per_page, $allowance));
    }

    /**
     * The raw records of a chunk, for migrators that prefetch page-wide lookups.
     *
     * @param  array<int, array{id: int, item: array|null}>  $page
     */
    private function records(array $page): array
    {
        return array_values(array_filter(array_column($page, 'item')));
    }

    /**
     * An id that was in this chunk when it was planned and is not in WooCommerce
     * any more. Recorded as skipped — the same verdict the retry path gives a
     * record that has gone — so the counters still reach the total instead of the
     * run hanging a few records short of finished with nothing to explain it.
     */
    private function recordDeletedAtSource(StoreMigration $migration, string $entityType, int $wooId): void
    {
        MigrationItem::updateOrCreate(
            [
                'shop_id' => $migration->shop_id,
                'entity_type' => $entityType,
                'woocommerce_id' => $wooId,
            ],
            [
                'migration_id' => $migration->id,
                'status' => 'skipped',
                'error' => 'No longer exists in WooCommerce',
            ],
        );

        MigrationRunner::log(
            $migration->id,
            'warning',
            $entityType,
            "Item {$wooId} was deleted in WooCommerce while this migration was running — skipped.",
        );
    }

    /**
     * Park the chunk for the life of the batch.
     *
     * The size is ESTIMATED from a handful of records rather than measured by
     * encoding the whole thing: the honest measurement built a multi-megabyte
     * JSON string purely to call strlen() on it and throw it away, which on a
     * 128MB shared host is a real spike at exactly the moment the page itself is
     * already resident. A few records is a good enough estimate for a decision
     * whose only downside is a re-fetch.
     *
     * The store is very often the DATABASE cache table (shared hosting has no
     * Redis), where an oversized blob can breach max_allowed_packet — and a failed
     * optimisation must never be able to fail the batch it was meant to speed up,
     * so this goes through SafeCache.
     *
     * @param  array<int, array{id: int, item: array|null}>  $page
     */
    private function cachePage(string $key, array $page, StoreMigration $migration): void
    {
        $count = count($page);

        if ($count === 0) {
            SafeCache::put($key, $page, now()->addHours(2));

            return;
        }

        $limit = max(0, (int) config('migration.max_cached_page_bytes', 3_500_000));

        $sampleSize = min(self::SIZE_SAMPLE, $count);
        $sampleBytes = strlen((string) json_encode(array_slice($page, 0, $sampleSize)));
        $estimate = ($sampleBytes / $sampleSize) * $count;

        // Either the page is too big for the store to hold, or the store refused
        // it. Both are survivable — the page is simply fetched again — but both
        // also mean every resume of every batch pays for a full page download,
        // which on a big catalogue is the difference between hours. It used to
        // happen in complete silence, so the operator had no way to connect a
        // slow migration to a `max_allowed_packet` they could have raised.
        if ($estimate > $limit) {
            $this->noteUncachedPages(
                $migration,
                "a page of {$count} records is larger than the ".number_format($limit).'-byte cache limit',
            );

            return;
        }

        if (! SafeCache::put($key, $page, now()->addHours(2))) {
            $this->noteUncachedPages($migration, 'the cache store rejected the write');
        }
    }

    /** Said once per run per worker — see $cacheWarned. */
    private function noteUncachedPages(StoreMigration $migration, string $reason): void
    {
        if (isset(self::$cacheWarned[$migration->id])) {
            return;
        }

        self::$cacheWarned[$migration->id] = true;

        MigrationRunner::log(
            $migration->id,
            'info',
            null,
            "WooCommerce pages are not being cached between steps because {$reason}. Nothing is lost, but each "
                .'step re-reads its page from your store, so the migration runs slower. Raising the database '
                ."server's max_allowed_packet, or lowering MIGRATION_PER_PAGE, will fix it.",
        );
    }

    private function forgetPage(MigrationBatch $batch): void
    {
        SafeCache::forget($this->pageKey($batch));
    }

    private function pageKey(MigrationBatch $batch): string
    {
        return "migration:{$batch->migration_id}:batch:{$batch->id}:page";
    }

    /**
     * A batch-level failure (not an item failure). Retry while attempts remain,
     * otherwise record it, leave the rest of the migration alone, and move on.
     */
    private function abandonOrRetry(StoreMigration $migration, MigrationBatch $batch, string $message, Throwable $e): void
    {
        MigrationRunner::log($migration->id, 'error', $batch->entity_type, $message);

        if ($this->attempts() < $this->tries) {
            $batch->update(['status' => 'pending', 'error' => $message]);

            $this->release($this->backoff()[$this->attempts() - 1] ?? 300);

            return;
        }

        $batch->update(['status' => 'failed', 'error' => $message]);
        $this->forgetPage($batch);

        MigrationRunner::log(
            $migration->id,
            'error',
            $batch->entity_type,
            "Batch {$batch->batch_number} gave up after {$this->tries} attempts — its records were not read from "
                .'WooCommerce at all, so they are not in the failed-items list. Use "Retry failed items" to run it again.',
        );

        MigrationRunner::advance($migration->id);
    }

    /**
     * Counters are recomputed from the mapping table (see MigrationRunner), so this
     * only has to write the human-readable log line for the item.
     */
    private function recordResult(StoreMigration $migration, string $entityType, int $wooId, MigrationItemResult $result): void
    {
        if ($result->status === 'failed') {
            MigrationRunner::log($migration->id, 'error', $entityType, "Item {$wooId} failed: {$result->error}");

            return;
        }

        if ($result->warning) {
            MigrationRunner::log($migration->id, 'warning', $entityType, "Item {$wooId}: {$result->warning}");
        }
    }

    /**
     * Last resort: the queue gave up on this job entirely. Record it and still push
     * the migration forward so it can never sit at "processing" forever.
     */
    public function failed(?Throwable $e): void
    {
        $batch = MigrationBatch::find($this->batchId);

        if (! $batch) {
            return;
        }

        $batch->update(['status' => 'failed', 'error' => $e?->getMessage() ?? 'Job failed']);
        $this->forgetPage($batch);

        MigrationRunner::log(
            $batch->migration_id,
            'error',
            $batch->entity_type,
            "Batch {$batch->batch_number} failed: ".($e?->getMessage() ?? 'unknown error'),
        );

        MigrationRunner::advance($batch->migration_id);
    }
}
