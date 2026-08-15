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
use App\Support\SourceHealth;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Re-runs ONLY the failed items of a migration.
 *
 * This used to just flip the failed rows back to pending and re-dispatch
 * StartMigrationJob, which re-planned and re-walked every entity from page one.
 * On a large catalogue that meant a full re-run to retry a handful of records —
 * slow enough that it looked like the button did nothing.
 *
 * Now the failed WooCommerce ids are fetched directly (one request per chunk) and
 * re-migrated. Same time budget and resumability as ProcessBatchJob, so it can
 * never hang the queue either.
 */
class RetryFailedItemsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** Same reasoning as ProcessBatchJob: one slow record must not kill the job. */
    public int $timeout = 300;

    private const TIME_BUDGET_SECONDS = 35;

    /** WooCommerce ids fetched per request. */
    private const CHUNK = 25;

    /**
     * Failed rows read into memory at a time.
     *
     * The whole set used to be loaded in one `get()`. On the run that most needs
     * retrying — a systemic failure, where every one of twenty thousand records
     * failed for the same reason — that is twenty thousand Eloquent models in a
     * job that also has a page of WooCommerce data resident. On the 128MB limit
     * shared hosting gives PHP, the retry died of the very size that made the
     * merchant press the button.
     */
    private const MAX_ROWS_PER_READ = 500;

    /**
     * Consecutive throttle pauses on the SAME chunk before we give up on it and
     * sweep past. Mirrors ProcessBatchJob: throttling that never clears is not
     * throttling, and without a bound the pause/re-dispatch pair is just a slower
     * infinite loop.
     */
    private const MAX_THROTTLE_WAITS = 10;

    /**
     * @param  string|null  $resumeEntity  entity the previous pass stopped inside
     * @param  int  $afterId  last migration_items id that pass dealt with
     * @param  int  $throttleWaits  pauses already spent on the chunk at the cursor
     */
    public function __construct(
        public int $migrationId,
        public ?string $resumeEntity = null,
        public int $afterId = 0,
        public int $throttleWaits = 0,
    ) {}

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(MigratorRegistry $registry): void
    {
        $migration = StoreMigration::with('connection', 'shop')->find($this->migrationId);

        if (! $migration || $migration->isCancelledOrPaused()) {
            return;
        }

        SourceHealth::within($migration->id);

        // Whole batches that never got read come first — see reviveFailedBatches().
        // Only on the FIRST pass: a batch that fails again during the retry is
        // left alone rather than revived over and over by the sweep's own
        // continuations.
        if ($this->resumeEntity === null) {
            $this->reviveFailedBatches($migration);
        }

        // Dependency order still matters: a failed product may need a category
        // that failed alongside it.
        $entityTypes = $registry->order(
            MigrationItem::where('migration_id', $migration->id)
                ->where('status', 'failed')
                ->distinct()
                ->pluck('entity_type')
                ->filter(fn ($type) => $registry->has($type))
                ->all(),
        );

        // Pick the sweep up where the last pass stopped. An entity earlier in the
        // order has already been swept, even if some of its records failed again.
        if ($this->resumeEntity !== null) {
            $from = array_search($this->resumeEntity, $entityTypes, true);
            $entityTypes = $from === false ? [] : array_slice($entityTypes, $from);
        }

        if (empty($entityTypes)) {
            // Finalize hands straight back to the runner when batches are
            // outstanding, so a run whose only problem was failed batches starts
            // moving again from here.
            FinalizeMigrationJob::dispatch($migration->id);

            return;
        }

        $startedAt = microtime(true);

        foreach ($entityTypes as $entityType) {
            $migrator = $registry->make($entityType);
            $entity = MigrationEntity::firstOrCreate(
                ['migration_id' => $migration->id, 'entity_type' => $entityType],
            );

            // The cursor only carries into the entity the last pass was inside.
            $after = $entityType === $this->resumeEntity ? $this->afterId : 0;

            while (true) {
                $rows = $this->failedRowsAfter($migration, $entityType, $after);

                if ($rows->isEmpty()) {
                    break;
                }

                foreach ($rows->chunk(self::CHUNK) as $chunk) {
                    if ($migration->isCancelledOrPaused()) {
                        return;
                    }

                    // Where the cursor was BEFORE this chunk, so a chunk cut short
                    // by throttling is resumed rather than stepped over.
                    $chunkStart = $after;
                    $chunkEnd = (int) $chunk->max('id');

                    $delay = $this->retryChunk($migration, $migrator, $entity, $entityType, $chunk->values()->all());

                    if ($delay !== null) {
                        $this->pauseForThrottle($migration, $entityType, $chunkStart, $chunkEnd, $delay);

                        return;
                    }

                    // The cursor advances whether the records recovered or not.
                    // THIS is what makes the sweep finite. It used to re-read
                    // "everything still failed" from the top on every pass and
                    // assume anything retried had stopped being failed — true only
                    // when the retry WORKED. On the runs that most need this button
                    // (no Protected Customer Data approval, an expired token, a
                    // WooCommerce store that has gone away) every record fails
                    // again, so the job re-dispatched itself forever, burning the
                    // merchant's Shopify rate limit on the same first hundred
                    // records and never reaching the rest.
                    $after = $chunkEnd;

                    MigrationRunner::syncCounters($migration, $entity);

                    if (microtime(true) - $startedAt >= self::TIME_BUDGET_SECONDS) {
                        // Progress was made, so the throttle budget starts fresh.
                        self::dispatch($migration->id, $entityType, $after);

                        return;
                    }
                }
            }

            MigrationRunner::refreshEntityStatus($migration, $entity);
        }

        FinalizeMigrationJob::dispatch($migration->id);
    }

    /**
     * Wait out a throttle and come back to the SAME chunk — unless it has now
     * been throttled so many times that it is clearly not going to clear, in
     * which case sweep past it so the rest of the failures still get their turn.
     */
    private function pauseForThrottle(
        StoreMigration $migration,
        string $entityType,
        int $chunkStart,
        int $chunkEnd,
        int $delay,
    ): void {
        if ($this->throttleWaits >= self::MAX_THROTTLE_WAITS) {
            MigrationRunner::log(
                $migration->id,
                'warning',
                $entityType,
                'Shopify kept rate limiting the same records during the retry, so they were left as they are and '
                    .'the rest of the failures were retried instead. Try those again when your Shopify store is less busy.',
            );

            self::dispatch($migration->id, $entityType, $chunkEnd)->delay($delay);

            return;
        }

        self::dispatch($migration->id, $entityType, $chunkStart, $this->throttleWaits + 1)->delay($delay);
    }

    /**
     * The next slice of still-failed records for one entity, past the cursor.
     *
     * Ordered by id and read in bounded slices, so neither the number of failures
     * nor the width of a mapping row decides whether this job survives.
     *
     * @return Collection<int, object{id: int, woocommerce_id: int}>
     */
    private function failedRowsAfter(StoreMigration $migration, string $entityType, int $after)
    {
        return MigrationItem::where('migration_id', $migration->id)
            ->where('entity_type', $entityType)
            ->where('status', 'failed')
            ->where('id', '>', $after)
            ->orderBy('id')
            ->limit(self::MAX_ROWS_PER_READ)
            ->get(['id', 'woocommerce_id']);
    }

    /**
     * Put failed BATCHES back in the queue.
     *
     * A batch fails as a whole when its WooCommerce page could not be read at all
     * — the deep-offset timeout that a large catalogue produces, a host that went
     * away mid-run. When that happens the item loop never ran, so those records
     * have no rows in migration_items: not as failed, not as anything.
     *
     * That made them invisible to every recovery path there is. The counters could
     * not see them, this job's failed-item query returned nothing, and the log
     * download printed "FAILED ITEMS (0)" while a hundred products were missing
     * from the merchant's store. The only remedy was re-running the entire
     * migration, and nothing in the app said so.
     *
     * So the batch itself is the unit that gets retried. `processed_offset` is
     * left exactly where it was, so a batch that failed halfway resumes rather
     * than replaying; `attempts` is reset because this is a deliberate, merchant-
     * initiated fresh start rather than another automatic go.
     */
    private function reviveFailedBatches(StoreMigration $migration): void
    {
        $failedBatches = MigrationBatch::where('migration_id', $migration->id)
            ->where('status', 'failed')
            ->get();

        if ($failedBatches->isEmpty()) {
            return;
        }

        foreach ($failedBatches as $batch) {
            $batch->update([
                'status' => 'pending',
                'attempts' => 0,
                'error' => null,
            ]);
        }

        $byEntity = $failedBatches
            ->groupBy('entity_type')
            ->map(fn ($rows, $type) => $rows->count()." {$type}")
            ->implode(', ');

        MigrationRunner::log(
            $migration->id,
            'info',
            null,
            "Re-queued {$failedBatches->count()} batch(es) whose records were never read from WooCommerce ({$byEntity}).",
        );
    }

    /**
     * Re-migrate one chunk of failed records.
     *
     * @param  array<int, object{id: int, woocommerce_id: int}>  $rows  mapping rows, not source ids
     * @return int|null seconds to wait before continuing, or null to carry straight on.
     *                  The CALLER re-dispatches, because only it knows the cursor —
     *                  dispatching from in here restarted the sweep from the top
     *                  every time Shopify throttled.
     */
    private function retryChunk(
        StoreMigration $migration,
        $migrator,
        MigrationEntity $entity,
        string $entityType,
        array $rows,
    ): ?int {
        $ids = array_map(fn ($row) => (int) $row->woocommerce_id, $rows);

        try {
            $items = $migrator->fetchByIds($migration->connection, $ids);
        } catch (Throwable $e) {
            MigrationRunner::log($migration->id, 'error', $entityType, "Retry could not read WooCommerce: {$e->getMessage()}");

            return null; // skip this chunk, keep going
        }

        // Anything WooCommerce no longer returns has been deleted at the source.
        // Retrying it forever helps nobody — say so and stop counting it as work.
        $returned = collect($items)->map(fn ($i) => $migrator->sourceId($i))->all();

        foreach (array_diff($ids, $returned) as $missing) {
            MigrationItem::where('migration_id', $migration->id)
                ->where('entity_type', $entityType)
                ->where('woocommerce_id', $missing)
                ->update(['status' => 'skipped', 'error' => 'No longer exists in WooCommerce']);

            MigrationRunner::log($migration->id, 'warning', $entityType, "Item {$missing} no longer exists in WooCommerce — skipped.");
        }

        // Same bulk lookups a normal batch gets — without this a retried product
        // would ship full-size images even though the merchant asked for smaller.
        if (method_exists($migrator, 'prefetchPage')) {
            $migrator->prefetchPage($migration, $items);
        }

        $recovered = 0;

        foreach ($items as $wooItem) {
            $wooId = $migrator->sourceId($wooItem);

            try {
                $result = $migrator->migrateItem($migration, $wooItem);
            } catch (ShopifyThrottledException $e) {
                return max(1, (int) ceil($e->retryAfterSeconds));
            } catch (Throwable $e) {
                $result = MigrationItemResult::failed($e->getMessage());
            }

            // Only failures and warnings get a line of their own. A per-item
            // "migrated on retry" note meant retrying a 50,000-record run wrote
            // 50,000 log rows nobody reads — the recovered count below says the
            // same thing in one line.
            if ($result->status === 'failed') {
                MigrationRunner::log($migration->id, 'error', $entityType, "Retry of item {$wooId} failed: {$result->error}");
            } elseif ($result->warning) {
                MigrationRunner::log($migration->id, 'warning', $entityType, "Item {$wooId}: {$result->warning}");
            } else {
                $recovered++;
            }
        }

        if ($recovered > 0) {
            MigrationRunner::log($migration->id, 'info', $entityType, "{$recovered} item(s) migrated on retry.");
        }

        // null, NOT the `true` this used to return when it answered a bool. With
        // an `?int` return type and no strict_types in this file, `true` silently
        // becomes 1 — so every chunk that went perfectly well was read by the
        // caller as "throttled, come back in a second", and the sweep re-queued
        // itself after every single chunk instead of carrying on.
        return null;
    }

    public function failed(?Throwable $e): void
    {
        MigrationRunner::log(
            $this->migrationId,
            'error',
            null,
            'Retry failed: '.($e?->getMessage() ?? 'unknown error'),
        );

        FinalizeMigrationJob::dispatch($this->migrationId);
    }
}
