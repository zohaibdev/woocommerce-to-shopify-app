<?php

namespace App\Http\Middleware;

use App\Support\QueueKeeper;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Starts a queue worker off the back of ordinary app traffic.
 *
 * The one thing this app can rely on is that a merchant watching a migration
 * polls the status endpoint every few seconds. That is a heartbeat nobody has to
 * install, so it is what the queue is hung off: if a poll finds queued work and
 * no worker, it starts one.
 *
 * It runs in terminate(), AFTER the response has been sent, so the merchant
 * never waits on it — and QueueKeeper takes a cache lock, so a page with three
 * pollers open still only ever starts one worker per minute.
 */
class KeepQueueAlive
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        QueueKeeper::ensureRunning();
    }
}
