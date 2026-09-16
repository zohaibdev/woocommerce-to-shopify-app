<?php

namespace App\Exceptions;

use Illuminate\Support\Str;
use RuntimeException;

/**
 * A billing operation that could not be completed, carrying a message that is
 * safe to show a merchant.
 *
 * The raw Shopify error never reaches the browser: it is logged (and recorded as
 * a billing event for admins) under `reference`, which the merchant can quote to
 * support.
 */
class BillingException extends RuntimeException
{
    public readonly string $reference;

    public function __construct(
        public readonly string $reason,
        public readonly string $userMessage,
        public readonly int $httpStatus = 422,
        ?string $reference = null,
    ) {
        parent::__construct($userMessage);
        $this->reference = $reference ?? (string) Str::uuid();
    }

    public static function inProgress(): self
    {
        return new self(
            'in_progress',
            'Another billing change for your store is already in progress. Wait a moment, then refresh this page.',
            409,
        );
    }

    public static function notConnected(): self
    {
        return new self(
            'not_connected',
            'We could not reach Shopify for your store. Close the app, open it again from your Shopify admin, and try once more.',
            409,
        );
    }

    public static function shopifyFailure(string $userMessage, string $reference): self
    {
        return new self('shopify_error', $userMessage, 502, $reference);
    }

    /** JSON body for an API response. */
    public function toResponseArray(): array
    {
        return [
            'error' => $this->userMessage,
            'reason' => $this->reason,
            'reference' => $this->reference,
        ];
    }
}
