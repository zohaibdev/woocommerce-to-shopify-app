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
        return self::verify($jwt)['shop'];
    }

    /**
     * Verify a session token and report why it failed.
     *
     * @return array{shop: ?string, reason: ?string}
     */
    public static function verify(string $jwt): array
    {
        $fail = fn (string $reason) => ['shop' => null, 'reason' => $reason];

        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return $fail('malformed_jwt');
        }
        [$header64, $payload64, $signature64] = $parts;

        // Verify HS256 signature over "header.payload".
        $expected = self::base64UrlEncode(
            hash_hmac('sha256', "{$header64}.{$payload64}", ShopifyOAuth::apiSecret(), true)
        );
        if (! hash_equals($expected, $signature64)) {
            return $fail('bad_signature (SHOPIFY_API_SECRET does not match the app that issued this token)');
        }

        $payload = json_decode(self::base64UrlDecode($payload64), true);
        if (! is_array($payload)) {
            return $fail('unreadable_payload');
        }

        $now = time();
        // exp / nbf validation. Allow clock skew between this server and Shopify —
        // without it, a server running even ~30s slow rejects every valid token.
        $leeway = (int) config('services.shopify.token_leeway', 60);

        if (isset($payload['exp']) && $now >= (int) $payload['exp'] + $leeway) {
            return $fail('expired (check system clock)');
        }
        if (isset($payload['nbf']) && $now < (int) $payload['nbf'] - $leeway) {
            return $fail('not_yet_valid (check system clock)');
        }
        // Token must be for THIS app.
        if (($payload['aud'] ?? null) !== ShopifyOAuth::apiKey()) {
            return $fail('aud_mismatch (token aud=' . ($payload['aud'] ?? 'none') . ', expected=' . ShopifyOAuth::apiKey() . ')');
        }

        // The shop is in `dest` (and `iss`), e.g. "https://shop.myshopify.com".
        $dest = $payload['dest'] ?? $payload['iss'] ?? '';
        $host = parse_url($dest, PHP_URL_HOST) ?: '';

        if (! ShopifyOAuth::isValidShopDomain($host)) {
            return $fail('bad_dest (' . $dest . ')');
        }

        return ['shop' => $host, 'reason' => null];
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
