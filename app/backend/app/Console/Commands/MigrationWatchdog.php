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

    /**
     * Batches named individually in the activity log before it is summarised.
     * Reviving four hundred at once should not write four hundred lines the
     * merchant has to scroll past to find anything else.
     */
    private const MAX_LOGGED_BATCHES = 10;

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
        // Never planned (or planning died) — start it over. Batch rows are rebuilt
        // from scratch and migration_items keeps it from duplicating anything.
        if (! MigrationBatch::where('migration_id', $migration->id)->exists()) {
            if ($migration->updated_at && $migration->updated_at->gt($cutoff)) {
                return false;
            }

            MigrationRunner::log($migration->id, 'warning', null, 'Watchdog: migration never started planning — restarting.');
            StartMigrationJob::dispatch($migration->id);

            return true;
        }

        // Everything is done but the run was never closed out.
        if (! MigrationRunner::nextBatch($migration->id)) {
            MigrationRunner::log($migration->id, 'warning', null, 'Watchdog: all batches finished but the migration was still open — finalizing.');
            MigrationRunner::advance($migration->id);

            return true;
        }

        return $this->reviveStalled($migration, $cutoff)
            || $this->unstickIdle($migration, $cutoff);
    }

    /**
     * Revive EVERY batch that is held but not moving.
     *
     * This used to look at one row: the oldest pending-or-processing batch. With
     * batches running one at a time that was the whole story, but the engine now
     * runs up to eight at once — so if that single oldest batch happened to be
     * healthy, the watchdog declared the migration fine and returned, while the
     * seven dead ones beside it stayed dead. And because it revived one batch per
     * invocation, recovering a whole stalled entity took one five-minute cron
     * cycle per batch.
     *
     * Only "processing" counts as stalled. A "pending" batch is not stuck, it is
     * queued — a run plans every batch up front, so hundreds of them legitimately
     * sit untouched for hours waiting for a free concurrency slot, and treating
     * those as stalled would rewrite the whole run's activity log every cycle.
     */
    private function reviveStalled(StoreMigration $migration, \Illuminate\Support\Carbon $cutoff): bool
    {
        $stalled = MigrationBatch::where('migration_id', $migration->id)
            ->where('status', 'processing')
            ->where('updated_at', '<=', $cutoff)
            ->orderBy('id')
            ->get();

        if ($stalled->isEmpty()) {
            return false;
        }

        foreach ($stalled->take(self::MAX_LOGGED_BATCHES) as $batch) {
            MigrationRunner::log(
                $migration->id,
                'warning',
                $batch->entity_type,
                "Watchdog: batch {$batch->batch_number} stalled at item {$batch->processed_offset} — resuming.",
            );
        }

        if ($stalled->count() > self::MAX_LOGGED_BATCHES) {
            MigrationRunner::log(
                $migration->id,
                'warning',
                null,
                'Watchdog: '.($stalled->count() - self::MAX_LOGGED_BATCHES).' further stalled batch(es) were resumed as well.',
            );
        }

        // Back to pending so a batch job does not mistake a dead "processing" row
        // for another worker's in-flight work and stand down from it.
        MigrationBatch::whereIn('id', $stalled->pluck('id'))->update([
            'status' => 'pending',
            'updated_at' => now(),
        ]);

        MigrationRunner::advance($migration->id);

        return true;
    }

    /**
     * Work is outstanding, nothing is holding any of it, and nothing has happened
     * for a while — so a dispatch was lost somewhere. advance() is idempotent and
     * respects the concurrency limit, so the fix is simply to ask again.
     */
    private function unstickIdle(StoreMigration $migration, \Illuminate\Support\Carbon $cutoff): bool
    {
        $inFlight = MigrationBatch::where('migration_id', $migration->id)
            ->where('status', 'processing')
            ->exists();

        if ($inFlight) {
            return false;
        }

        $lastTouched = MigrationBatch::where('migration_id', $migration->id)->max('updated_at');

        if ($lastTouched && \Illuminate\Support\Carbon::parse($lastTouched)->gt($cutoff)) {
            return false;
        }

        MigrationRunner::log(
            $migration->id,
            'warning',
            null,
            'Watchdog: batches are waiting but nothing is running them — re-dispatching.',
        );

        MigrationRunner::advance($migration->id);

        return true;
    }
}
