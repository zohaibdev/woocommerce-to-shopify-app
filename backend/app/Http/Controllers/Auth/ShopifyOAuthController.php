<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Services\ShopifyOAuth;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ShopifyOAuthController extends Controller
{
    /**
     * OAuth entry point (this is the app's "App URL").
     * If the shop already has a token, bounce into the app; otherwise begin OAuth.
     *
     * GET /api/auth?shop={shop}.myshopify.com
     */
    public function begin(Request $request): RedirectResponse
    {
        $shop = $request->query('shop');

        abort_unless(ShopifyOAuth::isValidShopDomain($shop), 400, 'Invalid shop domain');

        // Already installed AND all required scopes granted? Go straight into the app.
        // If required scopes are missing (e.g. we added write_orders), fall through
        // to OAuth so the merchant re-consents and the token is upgraded.
        $existing = Shop::where('shopify_domain', $shop)->first();
        if (
            $existing
            && $existing->access_token
            && ! $existing->uninstalled_at
            && $this->hasAllRequiredScopes($existing->scopes)
        ) {
            // Forward the full query (shop, host, embedded) so App Bridge can
            // initialise when the admin loads the app inside its iframe.
            $qs = $request->getQueryString();

            return redirect()->away(ShopifyOAuth::appUrl() . '/' . ($qs ? "?{$qs}" : ''));
        }

        // Generate a nonce and stash it so the callback can verify it.
        $state = Str::random(40);
        Cache::put("shopify_oauth_state:{$shop}", $state, now()->addMinutes(10));

        return redirect()->away(ShopifyOAuth::authorizeUrl($shop, $state));
    }

    /** True only if every configured scope is present in the granted scope string. */
    private function hasAllRequiredScopes(?string $grantedScopes): bool
    {
        return empty(ShopifyOAuth::missingScopes($grantedScopes));
    }

    /**
     * OAuth callback. Verifies HMAC + state, exchanges the code for a permanent
     * access token, stores it encrypted, registers webhooks, and redirects into
     * the app.
     *
     * GET /api/auth/callback?shop=&code=&hmac=&state=
     */
    public function callback(Request $request): RedirectResponse
    {
        $query = $request->query();
        $shop = $query['shop'] ?? null;
        $code = $query['code'] ?? null;
        $state = $query['state'] ?? null;

        abort_unless(ShopifyOAuth::isValidShopDomain($shop), 400, 'Invalid shop domain');
        abort_unless(ShopifyOAuth::verifyHmac($query), 403, 'HMAC verification failed');

        $expectedState = Cache::pull("shopify_oauth_state:{$shop}");
        abort_unless($expectedState && hash_equals($expectedState, (string) $state), 403, 'Invalid OAuth state');
        abort_unless($code, 400, 'Missing authorization code');

        // Exchange the temporary code for a permanent access token.
        $res = Http::asJson()->post("https://{$shop}/admin/oauth/access_token", [
            'client_id' => ShopifyOAuth::apiKey(),
            'client_secret' => ShopifyOAuth::apiSecret(),
            'code' => $code,
        ]);

        abort_unless($res->successful(), 502, 'Token exchange failed');

        $accessToken = $res->json('access_token');
        $scopes = $res->json('scope');
        abort_unless($accessToken, 502, 'No access token returned');

        $storeShop = Shop::updateOrCreate(
            ['shopify_domain' => $shop],
            [
                'access_token' => $accessToken, // encrypted cast on the model
                'scopes' => $scopes,
                'installed_at' => now(),
                'uninstalled_at' => null,
            ],
        );

        $this->registerWebhooks($storeShop);

        // Land the merchant in the EMBEDDED app inside the Shopify admin.
        $apiKey = ShopifyOAuth::apiKey();
        $handle = preg_replace('/\.myshopify\.com$/', '', $shop);

        return redirect()->away("https://admin.shopify.com/store/{$handle}/apps/{$apiKey}");
    }

    /** Register the mandatory app/uninstalled webhook (best-effort). */
    private function registerWebhooks(Shop $shop): void
    {
        $version = config('services.shopify.api_version');
        $callback = ShopifyOAuth::appUrl() . '/api/webhooks/app-uninstalled';

        try {
            Http::withHeaders(['X-Shopify-Access-Token' => $shop->access_token])
                ->asJson()
                ->post("https://{$shop->shopify_domain}/admin/api/{$version}/webhooks.json", [
                    'webhook' => [
                        'topic' => 'app/uninstalled',
                        'address' => $callback,
                        'format' => 'json',
                    ],
                ]);
        } catch (\Throwable $e) {
            report($e); // don't fail the install if webhook registration hiccups
        }
    }
}
