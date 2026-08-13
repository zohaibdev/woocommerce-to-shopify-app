<?php

namespace App\Console\Commands;

use App\Jobs\StartMigrationJob;
use App\Migration\MigrationRunner;
use App\Models\MigrationBatch;
use App\Models\StoreMigration;
use Illuminate\Console\Command;

/**
 * Safety net: nothing may leave a migration sitting at "processing" forever.
 *
 * Even with self-advancing batches a run can stall for reasons outside the app —
 * the worker is OOM-killed, the host reboots mid-job, a queue row is lost. This
 * sweeps active migrations and nudges whatever should have happened next. It is
 * idempotent, so running it every minute alongside the queue worker is fine.
 */
class MigrationWatchdog extends Command
{
    protected $signature = 'migration:watchdog {--stall=10 : Minutes without progress before a batch is re-dispatched}';

    protected $description = 'Resume migrations that have stopped making progress';

    public function handle(): int
    {
        $stallMinutes = max(2, (int) $this->option('stall'));
        $cutoff = now()->subMinutes($stallMinutes);

        $migrations = StoreMigration::whereIn('status', ['pending', 'processing'])->get();

        if ($migrations->isEmpty()) {
            $this->info('No active migrations.');

            return self::SUCCESS;
        }

        $nudged = 0;

        foreach ($migrations as $migration) {
            if ($this->nudge($migration, $cutoff)) {
                $nudged++;
            }
        }

        $this->info("Checked {$migrations->count()} active migration(s), resumed {$nudged}.");

        return self::SUCCESS;
    }

    private function nudge(StoreMigration $migration, \Illuminate\Support\Carbon $cutoff): bool
    {
        $batches = MigrationBatch::where('migration_id', $migration->id);

        // Never planned (or planning died) — start it over. Batch rows are rebuilt
        // from scratch and migration_items keeps it from duplicating anything.
        if (! $batches->exists()) {
            if ($migration->updated_at && $migration->updated_at->gt($cutoff)) {
                return false;
            }

            MigrationRunner::log($migration->id, 'warning', null, 'Watchdog: migration never started planning — restarting.');
            StartMigrationJob::dispatch($migration->id);

            return true;
        }

        $next = MigrationRunner::nextBatch($migration->id);

        // Everything is done but the run was never closed out.
        if (! $next) {
            MigrationRunner::log($migration->id, 'warning', null, 'Watchdog: all batches finished but the migration was still open — finalizing.');
            MigrationRunner::advance($migration->id);

            return true;
        }

        // Work is outstanding but nothing has touched it in a while.
        if ($next->updated_at && $next->updated_at->gt($cutoff)) {
            return false;
        }

        MigrationRunner::log(
            $migration->id,
            'warning',
            $next->entity_type,
            "Watchdog: batch {$next->batch_number} stalled at item {$next->processed_offset} — resuming.",
        );

        // Reset to pending so the batch job doesn't mistake a dead "processing" row
        // for another worker's in-flight work.
        $next->update(['status' => 'pending']);

        MigrationRunner::advance($migration->id);

        return true;
    }
}
