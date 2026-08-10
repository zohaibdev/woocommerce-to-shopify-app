<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Services\ShopifyTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ExpiringTokenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.shopify.api_key' => 'test_key',
            'services.shopify.api_secret' => 'test_secret',
        ]);
    }

    private function fakeTokenResponse(): void
    {
        Http::fake([
            '*/admin/oauth/access_token' => Http::response([
                'access_token' => 'shpat_new',
                'refresh_token' => 'shprt_new',
                'scope' => 'write_products',
                'expires_in' => 3600,
                'refresh_token_expires_in' => 7776000,
            ], 200),
        ]);
    }

    public function test_session_token_exchange_stores_expiry_and_refresh_token(): void
    {
        $this->fakeTokenResponse();
        $shop = Shop::create(['shopify_domain' => 'demo.myshopify.com']);

        app(ShopifyTokenService::class)->exchangeSessionToken($shop, 'the.session.token');

        $shop->refresh();
        $this->assertSame('shpat_new', $shop->access_token);
        $this->assertSame('shprt_new', $shop->refresh_token);
        $this->assertNotNull($shop->token_expires_at);
        $this->assertFalse($shop->tokenIsExpired());
        $this->assertFalse($shop->hasLegacyToken());

        // Must request an EXPIRING token via the token-exchange grant.
        Http::assertSent(function ($req) {
            $b = $req->data();
            return $b['expiring'] === '1'
                && $b['grant_type'] === 'urn:ietf:params:oauth:grant-type:token-exchange'
                && $b['subject_token_type'] === 'urn:ietf:params:oauth:token-type:id_token'
                && $b['requested_token_type'] === 'urn:shopify:params:oauth:token-type:offline-access-token';
        });
    }

    public function test_legacy_permanent_token_is_detected_and_migrated(): void
    {
        $shop = Shop::create([
            'shopify_domain' => 'demo.myshopify.com',
            'access_token' => 'shpat_legacy_permanent',
        ]);

        $this->assertTrue($shop->hasLegacyToken(), 'no expiry recorded => legacy token');

        $this->fakeTokenResponse();
        app(ShopifyTokenService::class)->ensureFresh($shop);

        $shop->refresh();
        $this->assertSame('shpat_new', $shop->access_token);
        $this->assertFalse($shop->hasLegacyToken());

        // Legacy migration uses the old ACCESS token as the subject token.
        Http::assertSent(fn ($req) => $req->data()['subject_token_type']
            === 'urn:shopify:params:oauth:token-type:access-token');
    }

    public function test_expired_token_is_refreshed_via_refresh_token(): void
    {
        $this->fakeTokenResponse();
        $shop = Shop::create([
            'shopify_domain' => 'demo.myshopify.com',
            'access_token' => 'shpat_old',
            'refresh_token' => 'shprt_old',
            'token_expires_at' => now()->subMinute(),
            'refresh_token_expires_at' => now()->addDays(30),
        ]);

        $this->assertTrue($shop->tokenIsExpired());

        app(ShopifyTokenService::class)->ensureFresh($shop);

        $shop->refresh();
        $this->assertSame('shpat_new', $shop->access_token);
        Http::assertSent(fn ($req) => ($req->data()['grant_type'] ?? null) === 'refresh_token');
    }
}
