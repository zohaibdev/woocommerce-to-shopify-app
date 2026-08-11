<?php

namespace App\Services;

/**
 * Stateless helpers for the Shopify OAuth handshake:
 * shop-domain validation, HMAC verification, and URL building.
 *
 * @see https://shopify.dev/docs/apps/auth/oauth
 */
class ShopifyOAuth
{
    public static function apiKey(): string
    {
        return (string) config('services.shopify.api_key');
    }

    public static function apiSecret(): string
    {
        return (string) config('services.shopify.api_secret');
    }

    public static function scopes(): string
    {
        return (string) config('services.shopify.scopes');
    }

    /** Required scopes as an array. */
    public static function requiredScopes(): array
    {
        return collect(explode(',', self::scopes()))
            ->map(fn ($s) => trim($s))->filter()->values()->all();
    }

    /** Required scopes not present in the given granted-scope string. */
    public static function missingScopes(?string $grantedScopes): array
    {
        $granted = collect(explode(',', (string) $grantedScopes))->map(fn ($s) => trim($s))->filter();

        // In Shopify, write_X implies read_X — treat the read as satisfied too.
        $effective = $granted->flatMap(fn ($s) => str_starts_with($s, 'write_')
            ? [$s, 'read_' . substr($s, 6)]
            : [$s])->unique();

        return collect(self::requiredScopes())
            ->reject(fn ($s) => $effective->contains($s))
            ->values()->all();
    }

    public static function appUrl(): string
    {
        return (string) config('services.shopify.app_url');
    }

    /** Only *.myshopify.com hosts are valid shops. Prevents open-redirect abuse. */
    public static function isValidShopDomain(?string $shop): bool
    {
        if (! $shop) {
            return false;
        }

        return (bool) preg_match('/^[a-zA-Z0-9][a-zA-Z0-9\-]*\.myshopify\.com$/', $shop);
    }

    /** Build the Shopify authorization URL the merchant is redirected to. */
    public static function authorizeUrl(string $shop, string $state): string
    {
        $query = http_build_query([
            'client_id' => self::apiKey(),
            'scope' => self::scopes(),
            'redirect_uri' => self::appUrl() . '/api/auth/callback',
            'state' => $state,
        ]);

        return "https://{$shop}/admin/oauth/authorize?{$query}";
    }

    /**
     * Verify the HMAC on an OAuth/redirect request.
     * The signature is computed over all query params except hmac/signature,
     * sorted and joined as key=value pairs.
     */
    public static function verifyHmac(array $query): bool
    {
        $hmac = $query['hmac'] ?? null;
        if (! is_string($hmac) || $hmac === '') {
            return false;
        }

        unset($query['hmac'], $query['signature']);
        ksort($query);

        $pairs = [];
        foreach ($query as $key => $value) {
            $pairs[] = "{$key}={$value}";
        }
        $message = implode('&', $pairs);

        $computed = hash_hmac('sha256', $message, self::apiSecret());

        return hash_equals($computed, $hmac);
    }

    /**
     * Verify the HMAC on a webhook request. Webhooks are signed with the
     * base64 HMAC-SHA256 of the raw request body in the X-Shopify-Hmac-Sha256 header.
     */
    public static function verifyWebhook(string $rawBody, ?string $hmacHeader): bool
    {
        if (! $hmacHeader) {
            return false;
        }

        $computed = base64_encode(hash_hmac('sha256', $rawBody, self::apiSecret(), true));

        return hash_equals($computed, $hmacHeader);
    }
}
