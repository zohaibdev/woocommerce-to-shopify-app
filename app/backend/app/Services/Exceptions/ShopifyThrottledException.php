<?php

namespace App\Services\Exceptions;

use RuntimeException;

class ShopifyThrottledException extends RuntimeException
{
    /**
     * @param  float  $retryAfterSeconds  how long to wait before trying again.
     * @param  bool  $measured  whether that number came from Shopify (a
     *                          `Retry-After` header, or arithmetic on the point
     *                          budget it reported) or is our own GUESS.
     *
     * The distinction decides who owns the number. A measured wait is Shopify's
     * answer and the caller should honour it exactly. A guess is the caller's to
     * refine — see ProcessBatchJob, which probes early and only backs off to a
     * full minute once the early probe has been refused. Treating the two the
     * same is how every soft-throttled orderCreate cost a flat sixty seconds
     * whether or not the window had already reopened.
     */
    public function __construct(
        public readonly float $retryAfterSeconds,
        public readonly bool $measured = true,
    ) {
        parent::__construct("Shopify API throttled; retry after {$retryAfterSeconds}s");
    }
}
