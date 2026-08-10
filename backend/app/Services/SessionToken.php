<?php

namespace App\Services;

/**
 * Verifies a Shopify App Bridge session token (a JWT, HS256-signed with the app
 * secret). Returns the shop domain on success, or null if invalid/expired.
 *
 * @see https://shopify.dev/docs/apps/build/authentication-authorization/session-tokens
 */
class SessionToken
{
    /** Verify a session-token JWT and return the shop domain, or null. */
    public static function shopDomain(string $jwt): ?string
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return null;
        }
        [$header64, $payload64, $signature64] = $parts;

        // Verify HS256 signature over "header.payload".
        $expected = self::base64UrlEncode(
            hash_hmac('sha256', "{$header64}.{$payload64}", ShopifyOAuth::apiSecret(), true)
        );
        if (! hash_equals($expected, $signature64)) {
            return null;
        }

        $payload = json_decode(self::base64UrlDecode($payload64), true);
        if (! is_array($payload)) {
            return null;
        }

        $now = time();
        // exp / nbf validation (allow small clock skew).
        if (isset($payload['exp']) && $now >= (int) $payload['exp'] + 5) {
            return null;
        }
        if (isset($payload['nbf']) && $now < (int) $payload['nbf'] - 5) {
            return null;
        }
        // Token must be for THIS app.
        if (($payload['aud'] ?? null) !== ShopifyOAuth::apiKey()) {
            return null;
        }

        // The shop is in `dest` (and `iss`), e.g. "https://shop.myshopify.com".
        $dest = $payload['dest'] ?? $payload['iss'] ?? '';
        $host = parse_url($dest, PHP_URL_HOST) ?: '';

        return ShopifyOAuth::isValidShopDomain($host) ? $host : null;
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/')) ?: '';
    }
}
