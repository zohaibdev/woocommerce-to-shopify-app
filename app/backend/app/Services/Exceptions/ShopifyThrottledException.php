<?php

namespace App\Services\Exceptions;

use RuntimeException;

class ShopifyThrottledException extends RuntimeException
{
    public function __construct(public readonly float $retryAfterSeconds)
    {
        parent::__construct("Shopify API throttled; retry after {$retryAfterSeconds}s");
    }
}
