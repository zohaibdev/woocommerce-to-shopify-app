<?php

namespace App\Services;

use App\Models\Shop;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Obtains and maintains EXPIRING offline access tokens.
 *
 * Shopify deprecated permanent (non-expiring) offline tokens: they can no longer
 * be used for API calls and must be exchanged for expiring ones. Expiring tokens
 * last ~1h and come with a refresh token (~90 days).
 *
 * @see https://shopify.dev/docs/apps/build/authentication-authorization/access-tokens/offline-access-tokens
 */
class ShopifyTokenService
{
    private const TOKEN_EXCHANGE_GRANT = 'urn:ietf:params:oauth:grant-type:token-exchange';
    private const ID_TOKEN_TYPE = 'urn:ietf:params:oauth:token-type:id_token';
    private const ACCESS_TOKEN_TYPE = 'urn:shopify:params:oauth:token-type:access-token';
    private const OFFLINE_TOKEN_TYPE = 'urn:shopify:params:oauth:token-type:offline-access-token';

    /**
     * Exchange an App Bridge session token (id_token) for an expiring offline token.
     * This is the primary path for embedded requests.
     */
    public function exchangeSessionToken(Shop $shop, string $sessionToken): Shop
    {
        return $this->exchange($shop, $sessionToken, self::ID_TOKEN_TYPE);
    }

    /**
     * Migrate a deprecated permanent offline token to an expiring one.
     * The old token is revoked by Shopify on success — this is irreversible.
     */
    public function migrateLegacyToken(Shop $shop): Shop
    {
        if (! $shop->access_token) {
            throw new RuntimeException('Shop has no token to migrate');
        }

        return $this->exchange($shop, $shop->access_token, self::ACCESS_TOKEN_TYPE);
    }

    /** Perform the token exchange and persist the result. */
    private function exchange(Shop $shop, string $subjectToken, string $subjectTokenType): Shop
    {
        $res = Http::asForm()->acceptJson()->timeout(30)
            ->post("https://{$shop->shopify_domain}/admin/oauth/access_token", [
                'client_id' => ShopifyOAuth::apiKey(),
                'client_secret' => ShopifyOAuth::apiSecret(),
                'grant_type' => self::TOKEN_EXCHANGE_GRANT,
                'subject_token' => $subjectToken,
                'subject_token_type' => $subjectTokenType,
                'requested_token_type' => self::OFFLINE_TOKEN_TYPE,
                'expiring' => '1',
            ]);

        if (! $res->successful()) {
            throw new RuntimeException("Token exchange failed ({$res->status()}): {$res->body()}");
        }

        return $this->store($shop, $res->json());
    }

    /**
     * Refresh an expiring offline token using its refresh token (standard OAuth
     * refresh grant). Used by background jobs, where no session token exists.
     */
    public function refresh(Shop $shop): Shop
    {
        if (! $shop->refreshTokenIsUsable()) {
            throw new RuntimeException('No usable refresh token; merchant must reopen the app');
        }

        $res = Http::asForm()->acceptJson()->timeout(30)
            ->post("https://{$shop->shopify_domain}/admin/oauth/access_token", [
                'client_id' => ShopifyOAuth::apiKey(),
                'client_secret' => ShopifyOAuth::apiSecret(),
                'grant_type' => 'refresh_token',
                'refresh_token' => $shop->refresh_token,
            ]);

        if (! $res->successful()) {
            throw new RuntimeException("Token refresh failed ({$res->status()}): {$res->body()}");
        }

        return $this->store($shop, $res->json());
    }

    /**
     * Ensure the shop has a usable, non-expired token. Refreshes when possible.
     * Returns true when the shop is ready to make API calls.
     */
    public function ensureFresh(Shop $shop): bool
    {
        if (! $shop->access_token) {
            return false;
        }

        // Legacy permanent token: migrate it before it stops working.
        if ($shop->hasLegacyToken()) {
            try {
                $this->migrateLegacyToken($shop);

                return true;
            } catch (\Throwable $e) {
                Log::warning('Legacy token migration failed', [
                    'shop' => $shop->shopify_domain,
                    'error' => $e->getMessage(),
                ]);

                // Old token may still work until Shopify fully disables it.
                return true;
            }
        }

        if (! $shop->tokenIsExpired()) {
            return true;
        }

        try {
            $this->refresh($shop);

            return true;
        } catch (\Throwable $e) {
            Log::warning('Token refresh failed', [
                'shop' => $shop->shopify_domain,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /** Persist a token-endpoint response onto the shop. */
    private function store(Shop $shop, array $data): Shop
    {
        $shop->forceFill(array_filter([
            'access_token' => $data['access_token'] ?? null,
            'refresh_token' => $data['refresh_token'] ?? $shop->refresh_token,
            'scopes' => $data['scope'] ?? $shop->scopes,
            'token_expires_at' => isset($data['expires_in'])
                ? now()->addSeconds((int) $data['expires_in'])
                : null,
            'refresh_token_expires_at' => isset($data['refresh_token_expires_in'])
                ? now()->addSeconds((int) $data['refresh_token_expires_in'])
                : $shop->refresh_token_expires_at,
        ], fn ($v) => $v !== null));

        // A token for an uninstalled shop means it was reinstalled. With Shopify
        // managed install that happens HERE, never in the OAuth callback, so this
        // is the only place the reinstall can be recorded.
        if ($shop->uninstalled_at) {
            $shop->forceFill(['installed_at' => now(), 'uninstalled_at' => null]);
        }

        $shop->save();

        return $shop->refresh();
    }
}
