<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/**
 * Keeps the queue moving on hosting that has no persistent worker.
 *
 * A migration is entirely queue-driven, so "no worker" does not degrade the app
 * — it stops it dead, with the merchant watching a progress bar that will never
 * move. The documented fix was a cron the operator had to remember to install,
 * and every time it was missing, or the host reset it, every migration on the
 * platform froze silently until somebody opened the admin panel.
 *
 * So the app starts its own workers: when it notices queued work and free
 * capacity, it launches DETACHED `queue:work` processes that drain the queue and
 * exit.
 *
 * PARALLELISM IS THE POINT. This used to start exactly one worker, which quietly
 * made the whole concurrency design decorative: MigrationRunner works out from
 * the shop's real Shopify budget that it may run up to eight batches at once and
 * dispatches that many jobs — and a single worker then ran them one after
 * another. On a 20,000-product catalogue that is the difference between a
 * migration that finishes overnight and one that finishes in an afternoon. Slots
 * are leased through the cache (see slotKey), so however many pollers arrive at
 * once, the number of self-started workers is bounded by `queue.auto_workers`.
 *
 * Still a safety net, not a replacement for a proper worker — a real cron or
 * supervisor gives better throughput and restarts a dead worker immediately,
 * which is why canSpawn() reports honestly rather than pretending the problem is
 * solved.
 *
 * Deliberately NOT running jobs inside the web request: ProcessBatchJob has a
 * 300s timeout, and a job that outlives the request leaves a half-finished batch
 * and a 500 for the merchant.
 */
class QueueKeeper
{
    /**
     * How long a spawned worker lives.
     *
     * Under retry_after (900) by a wide margin, and short enough that a worker
     * started by a page the merchant then closed does not linger. The slot lease
     * below is derived from this, so the two can never drift apart.
     */
    private const MAX_SECONDS = 55;

    /**
     * A slot is leased for exactly as long as its worker is meant to live, with
     * no grace on top.
     *
     * Grace sounded safer and was not: it left a dead slot for the length of the
     * grace, so a merchant polling every three seconds got a worker for 55
     * seconds out of every 65 — a 15% hole in throughput on the longest runs, for
     * nothing.
     *
     * The honest caveat: --max-time only stops a worker picking up NEW jobs, so a
     * worker that is inside a job when its slot expires keeps going while a
     * replacement starts. The live process count can therefore briefly exceed
     * `queue.auto_workers`. That is bounded in practice because ProcessBatchJob
     * budgets itself to 35 seconds and hands the rest to a fresh job — the
     * overlap is seconds, not minutes — and it is harmless because the queue
     * hands any one job to exactly one worker and batches are claimed with a
     * conditional UPDATE on top of that. But on a host with a hard process limit,
     * size QUEUE_AUTO_WORKERS with a little room rather than to the ceiling.
     */
    private const LEASE_GRACE_SECONDS = 0;

    /**
     * Start workers if there is work and spare capacity.
     *
     * Safe to call on every request: the fast path is one COUNT on an indexed
     * table plus a cache write per free slot, and it returns before that when the
     * queue is empty. Never throws — a queue that cannot be nudged must not take
     * the request down with it.
     *
     * @return bool whether at least one worker was started
     */
    public static function ensureRunning(): bool
    {
        try {
            if (! config('queue.auto_worker', true) || ! self::canSpawn()) {
                return false;
            }

            $pending = self::pending();

            if ($pending === 0) {
                return false;
            }

            $started = 0;

            // Never start more workers than there is work for them to take. A
            // single queued job does not need four processes racing for it, and
            // each one costs a PHP boot on a host that is usually already tight.
            foreach (range(0, self::targetWorkers($pending) - 1) as $slot) {
                // add() is atomic on every store we support, so of ten concurrent
                // pollers exactly one wins each slot.
                if (! Cache::add(self::slotKey($slot), true, self::leaseSeconds())) {
                    continue;
                }

                if (! self::spawn()) {
                    // Hand the slot straight back: a host that just refused to
                    // fork will refuse the next slot too, and holding the lease
                    // would block the retry a minute from now for no reason.
                    Cache::forget(self::slotKey($slot));

                    break;
                }

                $started++;
            }

            if ($started > 0) {
                Log::info("QueueKeeper dispatched {$started} background queue worker(s) (detached; exit status not observable).");
            }

            return $started > 0;
        } catch (\Throwable $e) {
            Log::warning('QueueKeeper could not start a worker: '.$e->getMessage());

            return false;
        }
    }

    /**
     * Jobs sitting in the queue right now, on WHICHEVER driver is configured.
     *
     * This used to count rows in the `jobs` table and return 0 for anything
     * else. On a Redis queue — which is the recommended setup on a VPS, and the
     * whole reason to move to one — that table still exists and is permanently
     * empty, so the admin panel reported "0 jobs waiting" with five hundred
     * backed up in Redis, and the self-starter concluded there was nothing to
     * start a worker for.
     *
     * Queue::size() answers for database, Redis and SQS alike. Never throws: it
     * runs on every API request and a queue that cannot be measured must not be
     * able to take the response down with it.
     */
    public static function pending(): int
    {
        try {
            return (int) Queue::connection()->size(
                config('queue.connections.'.config('queue.default').'.queue', 'default'),
            );
        } catch (\Throwable) {
            // A driver that cannot be asked (no table yet on a fresh install, a
            // Redis server that is down) reads as "nothing to do" rather than as
            // an error — the watchdog and the panel both survive that; neither
            // survives an exception here.
            return 0;
        }
    }

    /**
     * How many self-started workers this host is allowed to run at once.
     *
     * Capped by the amount of queued work, so the common case (one stray job) is
     * still one process.
     */
    public static function targetWorkers(?int $pending = null): int
    {
        $configured = max(1, (int) config('queue.auto_workers', 2));
        $pending ??= self::pending();

        return max(1, min($configured, $pending));
    }

    /**
     * Whether this host lets PHP start a background process at all.
     *
     * Plenty of shared hosts disable proc_open, and on those the cron really is
     * the only answer. The admin panel reads this so it can say which situation
     * the operator is in instead of showing advice that cannot work.
     */
    public static function canSpawn(): bool
    {
        if (! function_exists('proc_open')) {
            return false;
        }

        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return ! in_array('proc_open', $disabled, true) && PHP_BINARY !== '';
    }

    /**
     * Launch a detached worker.
     *
     * --stop-when-empty so it exits instead of lingering; --max-time so a wedged
     * job cannot leave a process running forever; an explicit --timeout because
     * the worker default (60s) is shorter than ProcessBatchJob's own 300s and
     * would kill a healthy batch mid-write.
     */
    private static function spawn(): bool
    {
        $artisan = base_path('artisan');

        $command = [
            PHP_BINARY,
            $artisan,
            'queue:work',
            '--stop-when-empty',
            '--max-time='.self::MAX_SECONDS,
            '--timeout=300',
            '--tries=3',
            '--quiet',
        ];

        $line = implode(' ', array_map('escapeshellarg', $command));

        // Detach so the worker outlives this request. Without the redirects the
        // child keeps the request's pipes open and PHP-FPM waits on it, which
        // turns a background nudge into a blocked response.
        //
        // The empty "" after `start` is NOT decoration. cmd.exe reads the first
        // QUOTED argument as the window title, so `start /B "C:\php.exe" "artisan"`
        // silently tries to run `artisan` as the program with the interpreter as
        // the title — it fails, and because start returns 0 either way, nothing
        // reports it. Production is Linux and took the nohup branch, so this only
        // ever broke local development, which is exactly where it went unnoticed.
        $shell = windows_os()
            ? 'start "" /B '.$line.' > NUL 2>&1'
            : 'nohup '.$line.' > /dev/null 2>&1 &';

        $process = proc_open($shell, [
            0 => ['file', windows_os() ? 'NUL' : '/dev/null', 'r'],
            1 => ['file', windows_os() ? 'NUL' : '/dev/null', 'w'],
            2 => ['file', windows_os() ? 'NUL' : '/dev/null', 'w'],
        ], $pipes);

        if (! is_resource($process)) {
            return false;
        }

        // The exit code is the SHELL's, not the worker's — the worker is
        // detached by design, so there is nothing here to wait on.
        proc_close($process);

        return true;
    }

    /** How long a slot stays taken: the worker's whole life, plus a little. */
    private static function leaseSeconds(): int
    {
        return self::MAX_SECONDS + self::LEASE_GRACE_SECONDS;
    }

    private static function slotKey(int $slot): string
    {
        return "queue-keeper:worker-slot:{$slot}";
    }
}
