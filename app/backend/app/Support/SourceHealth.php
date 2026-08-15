<?php

namespace App\Support;

use App\Migration\MigrationRunner;
use Illuminate\Support\Facades\Cache;

/**
 * Watches how the merchant's WooCommerce host behaves DURING a run.
 *
 * A migration that crawls is almost never the app's fault — it is a shared
 * WordPress host answering in 12 seconds, or dying every third request. The
 * merchant has no way to know that, so they blame the migration and open a
 * ticket. This measures the source and says so out loud, live, while the run is
 * still going.
 *
 * Every WooCommerce request reports its duration here. When the picture gets
 * meaningfully worse we write ONE log line (on the transition, never per
 * request) and the details page shows a banner.
 */
class SourceHealth
{
    public const HEALTHY = 'healthy';
    public const SLOW = 'slow';
    public const VERY_SLOW = 'very_slow';
    public const FAILING = 'failing';

    /** Average seconds per request at which the source stops being comfortable. */
    private const SLOW_SECONDS = 4.0;

    private const VERY_SLOW_SECONDS = 12.0;

    /** Consecutive dead/errored requests before we call the source broken. */
    private const FAILING_STREAK = 3;

    /** How many recent timings the rolling average is taken over. */
    private const WINDOW = 20;

    private const SEVERITY = [
        self::HEALTHY => 0,
        self::SLOW => 1,
        self::VERY_SLOW => 2,
        self::FAILING => 3,
    ];

    /**
     * Migration the current worker process is running.
     *
     * The HTTP client has no idea which migration it is serving — fetchBatch()
     * only ever receives a connection. Jobs set this once at the top of handle()
     * so every request underneath is attributed correctly. A worker runs one job
     * at a time, so a per-process value is safe.
     */
    private static ?int $migrationId = null;

    public static function within(?int $migrationId): void
    {
        self::$migrationId = $migrationId;
    }

    /**
     * Say something to the migration the current worker is serving, if any.
     *
     * Lets code that only ever receives a WooCommerce connection — the HTTP
     * client, for one — reach the merchant's activity log without having to be
     * handed a migration it has no other use for.
     */
    public static function note(string $level, string $message): void
    {
        if (! self::$migrationId) {
            return;
        }

        MigrationRunner::log(self::$migrationId, $level, null, $message);
    }

    /**
     * Report one WooCommerce request.
     *
     * Wrapped whole, and not just around the log write: this is called from
     * inside every HTTP call the migration makes, so a cache backend having a bad
     * day here does not degrade telemetry — it throws straight up through the
     * WooCommerce client and fails the batch that was only trying to fetch a
     * page. Measuring the run must never be able to end it.
     */
    public static function record(float $seconds, bool $failed = false): void
    {
        try {
            self::write($seconds, $failed);
        } catch (\Throwable) {
            // Nothing here is worth a single migrated record.
        }
    }

    private static function write(float $seconds, bool $failed): void
    {
        $migrationId = self::$migrationId;

        if (! $migrationId) {
            return;
        }

        $key = self::key($migrationId);

        // Read-modify-write is not atomic across parallel workers, so a sample
        // can be lost under concurrency. That is fine — this is telemetry to
        // describe a trend, not a counter anyone reconciles against.
        $state = Cache::get($key, [
            'durations' => [],
            'requests' => 0,
            'failures' => 0,
            'streak' => 0,
            'status' => self::HEALTHY,
        ]);

        $state['requests']++;
        $state['durations'][] = round($seconds, 2);

        if (count($state['durations']) > self::WINDOW) {
            $state['durations'] = array_slice($state['durations'], -self::WINDOW);
        }

        if ($failed) {
            $state['failures']++;
            $state['streak']++;
        } else {
            $state['streak'] = 0;
        }

        $previous = $state['status'];
        $state['status'] = self::classify($state);

        Cache::put($key, $state, now()->addHours(12));

        // Only speak up when things got WORSE. A source that recovers should not
        // spam the log, and a steadily slow source should say it once.
        if (self::severity($state['status']) > self::severity($previous)) {
            MigrationRunner::log(
                $migrationId,
                $state['status'] === self::FAILING ? 'error' : 'warning',
                null,
                self::message($state),
            );
        }
    }

    /**
     * What the details page shows. Null when we have not measured enough to say
     * anything honest yet.
     *
     * @return array{status:string, average_seconds:float, requests:int, failures:int, message:string}|null
     */
    public static function snapshot(int $migrationId): ?array
    {
        try {
            $state = Cache::get(self::key($migrationId));
        } catch (\Throwable) {
            return null;
        }

        if (! is_array($state) || ($state['requests'] ?? 0) < 3) {
            return null;
        }

        return [
            'status' => $state['status'],
            'average_seconds' => self::average($state),
            'requests' => $state['requests'],
            'failures' => $state['failures'],
            'message' => self::message($state),
        ];
    }

    public static function forget(int $migrationId): void
    {
        Cache::forget(self::key($migrationId));
    }

    private static function classify(array $state): string
    {
        if ($state['streak'] >= self::FAILING_STREAK) {
            return self::FAILING;
        }

        $average = self::average($state);

        return match (true) {
            $average >= self::VERY_SLOW_SECONDS => self::VERY_SLOW,
            $average >= self::SLOW_SECONDS => self::SLOW,
            default => self::HEALTHY,
        };
    }

    private static function average(array $state): float
    {
        $durations = $state['durations'] ?? [];

        return empty($durations) ? 0.0 : round(array_sum($durations) / count($durations), 1);
    }

    /** Plain language, aimed at a merchant — never at a developer. */
    private static function message(array $state): string
    {
        $average = self::average($state);

        return match ($state['status']) {
            self::FAILING => 'Your WooCommerce store stopped responding ('
                .$state['streak'].' failed requests in a row). The migration will keep retrying, '
                .'but it cannot move forward until your store answers again. Check that it is online '
                .'and that your host is not blocking or rate-limiting API requests.',

            self::VERY_SLOW => 'Your WooCommerce store is answering very slowly (about '
                .$average.'s per request). This is the reason the migration is slow — it is waiting '
                .'on your store, not on Shopify. Nothing is lost; it will simply take longer.',

            self::SLOW => 'Your WooCommerce store is answering slowly (about '.$average.'s per request), '
                .'so this migration will take longer than usual.',

            default => 'Your WooCommerce store is responding normally (about '.$average.'s per request).',
        };
    }

    private static function severity(string $status): int
    {
        return self::SEVERITY[$status] ?? 0;
    }

    private static function key(int $migrationId): string
    {
        return "migration:{$migrationId}:source_health";
    }
}
