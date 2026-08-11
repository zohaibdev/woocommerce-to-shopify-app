<?php

namespace App\Http\Middleware;

use App\Models\Shop;
use App\Services\SessionToken;
use App\Services\ShopifyTokenService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates embedded-app API calls via a Shopify App Bridge session token
 * (Authorization: Bearer <jwt>). On success the verified shop domain is placed
 * on the request for CurrentShop to resolve.
 *
 * Local-dev fallback: when APP_ENV=local and no bearer token is present, the
 * X-Shop-Domain header is accepted so the app is testable before embedding.
 */
class AuthenticateSessionToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();

        if ($bearer) {
            $result = SessionToken::verify($bearer);

            if (! $result['shop']) {
                \Log::warning('Session token rejected', ['reason' => $result['reason']]);

                return response()->json([
                    'error' => 'Invalid session token',
                    // Surface the cause in local/dev only — never in production.
                    'reason' => app()->environment('local', 'testing') ? $result['reason'] : null,
                ], 401);
            }
            $request->attributes->set('shop_domain', $result['shop']);

            // Keep the shop's OFFLINE token current. Permanent offline tokens are
            // deprecated, so we exchange this session token for an expiring one
            // whenever the shop has none, still has a legacy token, or it expired.
            $this->ensureOfflineToken($result['shop'], $bearer);

            return $next($request);
        }

        // Development/testing fallback only.
        if (app()->environment('local', 'testing') && $request->header('X-Shop-Domain')) {
            $request->attributes->set('shop_domain', $request->header('X-Shop-Domain'));

            return $next($request);
        }

        return response()->json(['error' => 'Missing session token'], 401);
    }

    /**
     * Ensure the shop holds a current EXPIRING offline token, exchanging the
     * caller's session token when needed. Never blocks the request — a failure
     * here surfaces later as a Shopify API error rather than a hard 401.
     */
    private function ensureOfflineToken(string $shopDomain, string $sessionToken): void
    {
        $shop = Shop::firstOrCreate(['shopify_domain' => $shopDomain]);

        $needsToken = ! $shop->access_token || $shop->hasLegacyToken() || $shop->tokenIsExpired();
        if (! $needsToken) {
            return;
        }

        try {
            app(ShopifyTokenService::class)->exchangeSessionToken($shop, $sessionToken);
        } catch (\Throwable $e) {
            Log::warning('Offline token exchange failed', [
                'shop' => $shopDomain,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
