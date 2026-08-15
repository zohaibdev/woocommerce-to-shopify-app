<?php

namespace App\Console\Commands;

use App\Support\QueueKeeper;
use Illuminate\Console\Command;

/**
 * Make sure something is draining the queue. Cheap enough to run every minute.
 *
 * The app can already start its own workers off ordinary API traffic (see
 * KeepQueueAlive), and while the merchant has the migration page open that
 * heartbeat is enough. The moment they close the tab it stops — and a migration
 * of any size runs for hours, which merchants are explicitly told they may walk
 * away from.
 *
 * Before this existed the only remaining heartbeat was `migration:watchdog` on a
 * five-minute cron, and a self-started worker lives 55 seconds. So a run nobody
 * was watching got 55 seconds of work out of every 300 — an 18% duty cycle, and
 * a fourteen-hour migration became a three-day one with nothing in the app
 * saying why.
 *
 * Separate from the watchdog on purpose: this is a two-query check, while the
 * watchdog sweeps every active migration and re-queues failed jobs. One wants to
 * run every minute; the other does not.
 */
class QueueKeepAlive extends Command
{
    protected $signature = 'queue:keepalive';

    protected $description = 'Start queue workers if there is queued work and spare capacity';

    public function handle(): int
    {
        if (! config('queue.auto_worker', true)) {
            $this->info('Self-starting workers are disabled (QUEUE_AUTO_WORKER=false) — supervisor or a worker cron is expected to be running.');

            return self::SUCCESS;
        }

        $pending = QueueKeeper::pending();

        if ($pending === 0) {
            return self::SUCCESS;
        }

        if (! QueueKeeper::canSpawn()) {
            $this->warn("{$pending} job(s) queued but this host disables proc_open — run `php artisan queue:work` from cron or supervisor instead.");

            return self::SUCCESS;
        }

        $this->info(QueueKeeper::ensureRunning()
            ? "{$pending} job(s) queued — started worker(s), up to ".QueueKeeper::targetWorkers($pending).'.'
            : "{$pending} job(s) queued — workers already running.");

        return self::SUCCESS;
    }
}
