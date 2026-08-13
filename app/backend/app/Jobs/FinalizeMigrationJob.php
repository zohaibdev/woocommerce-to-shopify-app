<?php

namespace App\Jobs;

use App\Migration\MigrationRunner;
use App\Models\MigrationBatch;
use App\Models\StoreMigration;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Rolls up the final status once no batches are outstanding. Idempotent, and it
 * re-derives every counter from the mapping table first so the numbers a merchant
 * sees at the end are the truth rather than whatever the increments happened to
 * add up to.
 */
class FinalizeMigrationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $migrationId) {}

    public function handle(): void
    {
        $migration = StoreMigration::with('entities')->find($this->migrationId);

        if (! $migration || in_array($migration->status, MigrationRunner::TERMINAL, true) || $migration->status === 'paused') {
            return;
        }

        // Work may have been queued between the last batch finishing and this job
        // running — if so, hand back to the runner instead of closing the run.
        if (MigrationRunner::nextBatch($migration->id)) {
            MigrationRunner::advance($migration->id);

            return;
        }

        foreach ($migration->entities as $entity) {
            MigrationRunner::syncCounters($migration, $entity);
            MigrationRunner::refreshEntityStatus($migration, $entity);
        }

        $migration->load('entities');

        $failedItems = $migration->entities->sum('failed_items');
        $successfulItems = $migration->entities->sum('successful_items');

        $failedBatches = MigrationBatch::where('migration_id', $migration->id)
            ->where('status', 'failed')
            ->count();

        $status = match (true) {
            // Nothing at all landed and something went wrong — a real failure.
            $successfulItems === 0 && ($failedItems > 0 || $failedBatches > 0) => 'failed',
            $failedItems > 0 || $failedBatches > 0 => 'completed_with_errors',
            default => 'completed',
        };

        $migration->update(['status' => $status, 'completed_at' => now()]);

        MigrationRunner::log(
            $migration->id,
            $status === 'completed' ? 'info' : 'warning',
            null,
            "Migration finished: {$successfulItems} migrated, {$failedItems} failed.",
        );
    }
}
