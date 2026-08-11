<?php

namespace App\Jobs;

use App\Migration\MigratorRegistry;
use App\Models\MigrationBatch;
use App\Models\MigrationEntity;
use App\Models\MigrationItem;
use App\Models\MigrationLog;
use App\Models\StoreMigration;
use App\Services\Exceptions\ShopifyThrottledException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Processes exactly one chunk (page) of one entity. Idempotent: each item is
 * guarded by the migration_items mapping table. Respects Shopify throttling by
 * releasing itself back onto the queue with the requested delay.
 */
class ProcessBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(public int $batchId) {}

    /** Exponential backoff (seconds) between retries. */
    public function backoff(): array
    {
        return [10, 30, 60, 120, 300];
    }

    public function handle(MigratorRegistry $registry): void
    {
        $batch = MigrationBatch::findOrFail($this->batchId);
        $migration = StoreMigration::with('connection', 'shop')->findOrFail($batch->migration_id);

        // Cooperative cancellation: stop before doing any work.
        if ($migration->isCancelledOrPaused()) {
            $batch->update(['status' => 'completed']); // let the chain drain cleanly
            return;
        }

        $batch->update(['status' => 'processing', 'attempts' => $batch->attempts + 1]);

        $migrator = $registry->make($batch->entity_type);

        try {
            $items = $migrator->fetchBatch($migration->connection, $batch->page, $batch->per_page);
        } catch (Throwable $e) {
            $batch->update(['status' => 'failed', 'error' => $e->getMessage()]);
            $this->log($migration, 'error', $batch->entity_type, "Fetch failed: {$e->getMessage()}");
            throw $e; // let the queue retry with backoff
        }

        $entity = MigrationEntity::firstOrCreate(
            ['migration_id' => $migration->id, 'entity_type' => $batch->entity_type],
        );

        foreach ($items as $wooItem) {
            if ($migration->isCancelledOrPaused()) {
                break;
            }

            $wooId = $migrator->sourceId($wooItem);

            try {
                $result = $migrator->migrateItem($migration, $wooItem);
            } catch (ShopifyThrottledException $e) {
                // Re-queue this whole chunk after the throttle window; already-mapped
                // items will be skipped, so this stays idempotent.
                $batch->update(['status' => 'pending']);
                $this->release((int) ceil($e->retryAfterSeconds));
                return;
            } catch (Throwable $e) {
                $result = \App\Migration\MigrationItemResult::failed($e->getMessage());
            }

            $this->recordResult($migration, $entity, $batch->entity_type, $wooId, $result);
        }

        $batch->update(['status' => 'completed']);
        $this->refreshEntityStatus($entity);
    }

    private function recordResult($migration, MigrationEntity $entity, string $entityType, int $wooId, $result): void
    {
        // The migrator owns the migration_items mapping row (idempotency). The job
        // is responsible only for aggregate counters and logging.
        $entity->increment('processed_items');
        if ($result->status === 'success' || $result->status === 'skipped') {
            $entity->increment('successful_items');
        } elseif ($result->status === 'failed') {
            $entity->increment('failed_items');
            $this->log($migration, 'error', $entityType, "Item {$wooId} failed: {$result->error}");
        }
    }

    private function refreshEntityStatus(MigrationEntity $entity): void
    {
        $entity->refresh();
        if ($entity->processed_items >= $entity->total_items) {
            $entity->update([
                'status' => $entity->failed_items > 0 ? 'completed_with_errors' : 'completed',
            ]);
        }
    }

    private function log($migration, string $level, ?string $entityType, string $message): void
    {
        MigrationLog::create([
            'migration_id' => $migration->id,
            'level' => $level,
            'entity_type' => $entityType,
            'message' => $message,
            'created_at' => now(),
        ]);
    }

    public function failed(Throwable $e): void
    {
        $batch = MigrationBatch::find($this->batchId);
        if ($batch) {
            $batch->update(['status' => 'failed', 'error' => $e->getMessage()]);
        }
    }
}
