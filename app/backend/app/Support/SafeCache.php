<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The cache, for the places where it is an OPTIMISATION rather than the truth.
 *
 * Production runs the database cache store — shared hosting has no Redis — so a
 * cache call is a database write, and it can fail for reasons that have nothing
 * to do with the migration: an oversized blob against `max_allowed_packet`, a
 * locked table, a connection dropped mid-run.
 *
 * Every one of those used to surface as an exception thrown from deep inside a
 * WooCommerce request or an image lookup, killing the batch that was merely
 * trying to go faster. Wherever a miss is survivable, use this: it degrades to
 * "no cache", which is slow, instead of "no migration", which is not.
 *
 * Do NOT use it where a lost value changes the result — reach for the facade
 * there and handle the failure deliberately.
 */
class SafeCache
{
    public static function get(string $key, mixed $default = null): mixed
    {
        try {
            return Cache::get($key, $default);
        } catch (Throwable $e) {
            report($e);

            return $default;
        }
    }

    public static function put(string $key, mixed $value, mixed $ttl = null): bool
    {
        try {
            return (bool) Cache::put($key, $value, $ttl);
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    public static function forget(string $key): bool
    {
        try {
            return (bool) Cache::forget($key);
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /** Falls back to computing the value every time when the cache is unavailable. */
    public static function remember(string $key, mixed $ttl, callable $callback): mixed
    {
        try {
            return Cache::remember($key, $ttl, $callback);
        } catch (Throwable $e) {
            report($e);

            return $callback();
        }
    }

    /** Returns the new value, or null when the counter could not be kept. */
    public static function increment(string $key, int $ttlDays = 1): ?int
    {
        try {
            Cache::add($key, 0, now()->addDays($ttlDays));

            return (int) Cache::increment($key);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }
}
