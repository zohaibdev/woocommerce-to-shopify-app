<?php

namespace App\Migration;

use App\Jobs\FinalizeMigrationJob;
use App\Jobs\ProcessBatchJob;
use App\Models\MigrationBatch;
use App\Models\MigrationEntity;
use App\Models\MigrationItem;
use App\Models\MigrationLog;
use App\Models\StoreMigration;

/**
 * Drives a migration forward one batch at a time.
 *
 * Batches are NOT dispatched as a Bus chain any more: a chain dies the moment one
 * link fails, which used to leave the whole migration frozen at "processing" with
 * no error and no way to resume. Instead each batch, when it finishes — succeeded,
 * failed, or gave up — asks the runner for the next one. A migration therefore
 * always moves forward, and a permanently broken batch costs one batch, never the
 * run.
 */
class MigrationRunner
{
    /** Statuses that mean the run is over and must not be advanced any further. */
    public const TERMINAL = ['completed', 'completed_with_errors', 'failed', 'cancelled'];

    /**
     * Dispatch the next outstanding batch, or finalize when there is none left.
     * Safe to call repeatedly — it only ever looks at persisted state.
     */
    public static function advance(int $migrationId): void
    {
        $migration = StoreMigration::find($migrationId);

        if (! $migration || in_array($migration->status, self::TERMINAL, true) || $migration->status === 'paused') {
            return;
        }

        $next = self::nextBatch($migrationId);

        if ($next) {
            ProcessBatchJob::dispatch($next->id);

            return;
        }

        FinalizeMigrationJob::dispatch($migrationId);
    }

    /** The oldest batch that still has work left in it. */
    public static function nextBatch(int $migrationId): ?MigrationBatch
    {
        return MigrationBatch::where('migration_id', $migrationId)
            ->whereIn('status', ['pending', 'processing'])
            ->orderBy('id')
            ->first();
    }

    /**
     * Recompute an entity's counters from the mapping table.
     *
     * Counters used to be blind increment() calls, so any batch that ran twice
     * (worker killed mid-page, queue re-reservation) inflated them — which is how
     * a run could report "100/112 processed" while only 24 collections existed.
     * Deriving them from migration_items makes the numbers true no matter how
     * many times a batch is replayed.
     */
    public static function syncCounters(StoreMigration $migration, MigrationEntity $entity): void
    {
        $counts = MigrationItem::query()
            ->where('migration_id', $migration->id)
            ->where('entity_type', $entity->entity_type)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $successful = (int) ($counts['success'] ?? 0) + (int) ($counts['skipped'] ?? 0);
        $failed = (int) ($counts['failed'] ?? 0);
        $processed = $successful + $failed;

        // Never report more processed than planned (the plan cap is the total).
        if ($entity->total_items > 0) {
            $processed = min($processed, $entity->total_items);
        }

        $entity->update([
            'successful_items' => $successful,
            'failed_items' => $failed,
            'processed_items' => $processed,
        ]);
    }

    /** Mark an entity finished once every one of its batches is done. */
    public static function refreshEntityStatus(StoreMigration $migration, MigrationEntity $entity): void
    {
        $outstanding = MigrationBatch::where('migration_id', $migration->id)
            ->where('entity_type', $entity->entity_type)
            ->whereIn('status', ['pending', 'processing'])
            ->exists();

        if ($outstanding) {
            return;
        }

        $entity->refresh()->update([
            'status' => $entity->failed_items > 0 ? 'completed_with_errors' : 'completed',
        ]);
    }

    public static function log(int $migrationId, string $level, ?string $entityType, string $message, ?array $context = null): void
    {
        MigrationLog::create([
            'migration_id' => $migrationId,
            'level' => $level,
            'entity_type' => $entityType,
            'message' => $message,
            'context' => $context,
            'created_at' => now(),
        ]);
    }
}
