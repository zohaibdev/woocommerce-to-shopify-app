<?php

namespace App\Migration;

class MigrationItemResult
{
    public function __construct(
        public readonly string $status,      // 'success' | 'failed' | 'skipped'
        public readonly ?string $shopifyId = null,
        public readonly ?string $error = null,
        /**
         * Set when the item WAS migrated but something non-essential was dropped
         * (e.g. an image that no longer exists at the source URL). The item still
         * counts as a success — the warning is written to the migration log so the
         * merchant knows what is missing.
         */
        public readonly ?string $warning = null,
    ) {}

    public static function success(string $shopifyId): self
    {
        return new self('success', $shopifyId);
    }

    /** Migrated, but with something skipped — never fails the record. */
    public static function successWithWarning(string $shopifyId, string $warning): self
    {
        return new self('success', $shopifyId, null, $warning);
    }

    public static function skipped(?string $shopifyId = null): self
    {
        return new self('skipped', $shopifyId);
    }

    public static function failed(string $error): self
    {
        return new self('failed', null, $error);
    }

    /**
     * Fold in warnings raised BEFORE the write (a product whose shape had to be
     * reshaped to fit Shopify's limits, say) so they reach the merchant alongside
     * anything the write itself had to drop.
     *
     * @param  array<int, string>  $warnings
     */
    public function withWarnings(array $warnings): self
    {
        $warnings = array_values(array_filter($warnings));

        if (empty($warnings) || $this->status === 'failed') {
            return $this;
        }

        return new self(
            $this->status,
            $this->shopifyId,
            $this->error,
            trim(implode(' ', array_filter([$this->warning, ...$warnings]))),
        );
    }
}
