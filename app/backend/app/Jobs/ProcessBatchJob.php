<?php

namespace App\Jobs;

use App\Migration\MigrationItemResult;
use App\Migration\MigrationRunner;
use App\Migration\MigratorRegistry;
use App\Models\MigrationBatch;
use App\Models\MigrationEntity;
use App\Models\StoreMigration;
use App\Services\Exceptions\ShopifyThrottledException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Processes one chunk (page) of one entity.
 *
 * Three properties make a run un-stallable:
 *
 *  1. RESUMABLE — every item advances `processed_offset` on the batch row, so a
 *     worker killed mid-page (shared hosting runs `queue:work --max-time=55`)
 *     resumes at the next item instead of replaying the page.
 *  2. TIME-BOUNDED — the job stops itself well before the queue's retry_after and
 *     re-dispatches a FRESH job for the remainder, so the queue never decides the
 *     job is dead and runs a second copy of it concurrently.
 *  3. SELF-ADVANCING — however this job ends (done, failed, out of retries) it
 *     hands the migration to MigrationRunner::advance(). One broken batch can no
 *     longer take the whole migration down with it.
 */
class ProcessBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * Stop after this many seconds and continue in a new job. Must stay well under
     * both the worker's --max-time and the queue's retry_after.
     */
    private const TIME_BUDGET_SECONDS = 35;

    public function __construct(public int $batchId) {}

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

        // Another worker already finished this page — just keep the run moving.
        if ($batch->status === 'completed') {
            MigrationRunner::advance($migration->id);

            return;
        }

        $batch->update(['status' => 'processing', 'attempts' => $batch->attempts + 1]);

        $migrator = $registry->make($batch->entity_type);

        $entity = MigrationEntity::firstOrCreate(
            ['migration_id' => $migration->id, 'entity_type' => $batch->entity_type],
        );

        try {
            $items = $migrator->fetchBatch($migration->connection, $batch->page, $batch->per_page);
        } catch (Throwable $e) {
            $this->abandonOrRetry($migration, $batch, "Fetch failed: {$e->getMessage()}", $e);

            return;
        }

        // Plan cap: `total_items` is already capped to the merchant's plan, so only
        // take the slice of this page that still fits under it. Without this a
        // 10-product plan would migrate a whole 100-item page.
        $allowance = max(0, $entity->total_items - (($batch->page - 1) * $batch->per_page));
        $items = array_slice($items, 0, min($batch->per_page, $allowance));

        // Resume: skip whatever a previous (killed) run of this batch already did.
        $items = array_slice($items, (int) $batch->processed_offset);

        $startedAt = microtime(true);

        foreach ($items as $wooItem) {
            if ($migration->isCancelledOrPaused()) {
                return;
            }

            $wooId = $migrator->sourceId($wooItem);

            try {
                $result = $migrator->migrateItem($migration, $wooItem);
            } catch (ShopifyThrottledException $e) {
                // Wait out the throttle window in a fresh job so this doesn't burn
                // an attempt. The saved offset means we resume, not restart.
                $batch->update(['status' => 'pending']);
                MigrationRunner::syncCounters($migration, $entity);
                self::dispatch($batch->id)->delay((int) ceil($e->retryAfterSeconds));

                return;
            } catch (Throwable $e) {
                // An unexpected error on ONE item is that item's failure, never the
                // migration's. It is recorded and the run carries on.
                $result = MigrationItemResult::failed($e->getMessage());
            }

            // Checkpoint before anything else so a kill here at worst replays one
            // idempotent item.
            $batch->increment('processed_offset');

            $this->recordResult($migration, $batch->entity_type, $wooId, $result);

            if (microtime(true) - $startedAt >= self::TIME_BUDGET_SECONDS) {
                $batch->update(['status' => 'pending']);
                MigrationRunner::syncCounters($migration, $entity);
                self::dispatch($batch->id);

                return;
            }
        }

        $batch->update(['status' => 'completed', 'error' => null]);

        MigrationRunner::syncCounters($migration, $entity);
        MigrationRunner::refreshEntityStatus($migration, $entity);
        MigrationRunner::advance($migration->id);
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

        MigrationRunner::log(
            $migration->id,
            'error',
            $batch->entity_type,
            "Batch {$batch->batch_number} gave up after {$this->tries} attempts — continuing with the rest of the migration.",
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

        MigrationRunner::log(
            $batch->migration_id,
            'error',
            $batch->entity_type,
            "Batch {$batch->batch_number} failed: ".($e?->getMessage() ?? 'unknown error'),
        );

        MigrationRunner::advance($batch->migration_id);
    }
}
