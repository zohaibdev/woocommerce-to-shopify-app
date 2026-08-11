<?php

namespace App\Migration;

class MigrationItemResult
{
    public function __construct(
        public readonly string $status,      // 'success' | 'failed' | 'skipped'
        public readonly ?string $shopifyId = null,
        public readonly ?string $error = null,
    ) {}

    public static function success(string $shopifyId): self
    {
        return new self('success', $shopifyId);
    }

    public static function skipped(?string $shopifyId = null): self
    {
        return new self('skipped', $shopifyId);
    }

    public static function failed(string $error): self
    {
        return new self('failed', null, $error);
    }
}
