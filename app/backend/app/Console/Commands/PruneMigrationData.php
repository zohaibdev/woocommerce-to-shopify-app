<?php

namespace App\Console\Commands;

use App\Models\MigrationLog;
use App\Models\StoreMigration;
use Illuminate\Console\Command;

/**
 * Keeps the activity log from becoming the biggest table in the database.
 *
 * A single large run writes a row per failed item and per warning, so a busy
 * installation accumulates millions of lines that nobody will ever open again —
 * and the merchant-facing endpoint only ever shows the most recent hundred. Old
 * lines of FINISHED runs are the only thing removed; anything still in flight,
 * and the full downloadable report of a recent run, are left completely alone.
 */
class PruneMigrationData extends Command
{
    protected $signature = 'migration:prune
        {--days=90 : Keep the logs of migrations that finished within this many days}
        {--debug-days=14 : Keep per-job DEBUG lines of finished migrations for this many days}';

    protected $description = 'Delete activity logs belonging to long-finished migrations';

    public function handle(): int
    {
        $days = max(7, (int) $this->option('days'));
        $cutoff = now()->subDays($days);

        // Debug lines go first and on a much shorter clock. There is one per
        // queue job — roughly one per 35 seconds of work, per run, across every
        // store — so they are the bulk of this table by a wide margin, and their
        // value is almost entirely in the days right after a run went wrong. The
        // merchant-facing lines of the same run outlive them.
        $this->pruneDebug(max(1, (int) $this->option('debug-days')));

        // Only runs that are OVER. A migration still at "processing" keeps every
        // line it has, however old the run has grown.
        $stale = StoreMigration::whereIn('status', ['completed', 'completed_with_errors', 'failed', 'cancelled'])
            ->where(function ($q) use ($cutoff) {
                $q->where('completed_at', '<', $cutoff)
                    ->orWhere(fn ($q) => $q->whereNull('completed_at')->where('updated_at', '<', $cutoff));
            })
            ->pluck('id');

        if ($stale->isEmpty()) {
            $this->info('Nothing to prune.');

            return self::SUCCESS;
        }

        $deleted = 0;

        // Chunked so this never builds one enormous DELETE, which on shared
        // hosting is the difference between a tidy-up and a locked table.
        foreach ($stale->chunk(200) as $ids) {
            do {
                $removed = MigrationLog::whereIn('migration_id', $ids)->limit(5000)->delete();
                $deleted += $removed;
            } while ($removed > 0);
        }

        $this->info("Pruned {$deleted} log line(s) from {$stale->count()} finished migration(s).");

        return self::SUCCESS;
    }

    /** Debug lines of runs that are OVER and older than the debug window. */
    private function pruneDebug(int $days): void
    {
        $cutoff = now()->subDays($days);

        $finished = StoreMigration::whereIn('status', ['completed', 'completed_with_errors', 'failed', 'cancelled'])
            ->where('updated_at', '<', $cutoff)
            ->pluck('id');

        if ($finished->isEmpty()) {
            return;
        }

        $deleted = 0;

        foreach ($finished->chunk(200) as $ids) {
            do {
                $removed = MigrationLog::whereIn('migration_id', $ids)
                    ->where('level', 'debug')
                    ->limit(5000)
                    ->delete();
                $deleted += $removed;
            } while ($removed > 0);
        }

        if ($deleted > 0) {
            $this->info("Pruned {$deleted} debug line(s) older than {$days} days.");
        }
    }
}
