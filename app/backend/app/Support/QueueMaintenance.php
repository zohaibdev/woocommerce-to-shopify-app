<?php

namespace App\Support;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Putting abandoned jobs back on the queue without anyone typing a command.
 *
 * A failed job is not an abstraction — it is a page of a merchant's catalogue
 * that did not migrate. The app's answer used to be a banner telling the
 * operator to run `php artisan queue:retry all`, which meant every transient
 * failure (a dropped connection, a brief Shopify outage, a worker killed
 * mid-batch) cost real records until a human noticed.
 *
 * Bounded on purpose. A job that fails for a REAL reason will fail again, and
 * retrying it forever would hide the problem while burning API quota, so each
 * job is retried a limited number of times and then left alone for a person to
 * look at.
 */
class QueueMaintenance
{
    /** How many times one job may be auto-retried before it is left for a human. */
    private const MAX_AUTO_RETRIES = 3;

    private const COUNTER_PREFIX = 'queue-maintenance:retries:';

    /**
     * Re-queue failed jobs, newest first.
     *
     * @return int how many were put back
     */
    public static function retryFailedJobs(?int $limit = null): int
    {
        $limit ??= (int) config('queue.auto_retry_failed', 25);

        if ($limit <= 0 || ! Schema::hasTable('failed_jobs')) {
            return 0;
        }

        try {
            $rows = DB::table('failed_jobs')
                ->orderByDesc('failed_at')
                ->limit($limit)
                ->get(['uuid']);
        } catch (\Throwable $e) {
            Log::warning('Could not read failed_jobs: '.$e->getMessage());

            return 0;
        }

        $retried = 0;

        foreach ($rows as $row) {
            if (! self::mayRetry($row->uuid)) {
                continue;
            }

            try {
                Artisan::call('queue:retry', ['id' => [$row->uuid]]);
                $retried++;
            } catch (\Throwable $e) {
                Log::warning("Could not retry job {$row->uuid}: ".$e->getMessage());
            }
        }

        if ($retried > 0) {
            Log::info("Auto-retried {$retried} failed job(s).");
        }

        return $retried;
    }

    /**
     * Has this job already had its automatic attempts?
     *
     * The count is kept in the cache rather than the database because it is an
     * optimisation, not a record: losing it re-runs a job a few more times,
     * which is the same thing the operator would have done by hand.
     */
    private static function mayRetry(string $uuid): bool
    {
        $key = self::COUNTER_PREFIX.$uuid;
        $seen = (int) SafeCache::get($key, 0);

        if ($seen >= self::MAX_AUTO_RETRIES) {
            return false;
        }

        SafeCache::put($key, $seen + 1, now()->addDays(7));

        return true;
    }
}
