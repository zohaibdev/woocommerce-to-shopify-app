<?php

namespace App\Services\Exceptions;

use RuntimeException;

/**
 * The merchant's WooCommerce store would not answer a question we need answered.
 *
 * Distinct from "the answer was zero", which is the mistake this exists to stop
 * us making: expired API keys returned HTTP 401, the count came back 0, and the
 * merchant was told their store was empty.
 */
class WooCommerceUnavailableException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 0)
    {
        parent::__construct($message);
    }
}
