<?php

namespace App\Jobs;

use App\Models\StoreMigration;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Runs at the end of the migration chain and rolls up the final status from the
 * per-entity counters. Idempotent and side-effect free beyond status update.
 */
class FinalizeMigrationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $migrationId) {}

    public function handle(): void
    {
        $migration = StoreMigration::with('entities')->findOrFail($this->migrationId);

        if (in_array($migration->status, ['cancelled', 'paused'], true)) {
            return;
        }

        $failed = $migration->entities->sum('failed_items');
        $anyFailedEntity = $migration->entities->contains('status', 'failed');

        $status = match (true) {
            $anyFailedEntity => 'failed',
            $failed > 0 => 'completed_with_errors',
            default => 'completed',
        };

        $migration->update(['status' => $status, 'completed_at' => now()]);
    }
}
