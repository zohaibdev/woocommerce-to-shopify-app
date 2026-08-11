<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Services\ShopifyOAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopifyOAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.shopify.api_key' => 'test_key',
            'services.shopify.api_secret' => 'test_secret',
            'services.shopify.app_url' => 'https://app.example.com',
            'services.shopify.scopes' => 'write_products',
        ]);
    }

    private function signedQuery(array $params): array
    {
        ksort($params);
        $pairs = [];
        foreach ($params as $k => $v) {
            $pairs[] = "{$k}={$v}";
        }
        $params['hmac'] = hash_hmac('sha256', implode('&', $pairs), 'test_secret');

        return $params;
    }

    public function test_hmac_verification_rejects_tampering(): void
    {
        $valid = $this->signedQuery(['shop' => 'demo.myshopify.com', 'code' => 'abc']);
        $this->assertTrue(ShopifyOAuth::verifyHmac($valid));

        $tampered = $valid;
        $tampered['code'] = 'evil';
        $this->assertFalse(ShopifyOAuth::verifyHmac($tampered));
    }

    public function test_begin_redirects_to_shopify_authorize(): void
    {
        $res = $this->get('/api/auth?shop=demo.myshopify.com');

        $res->assertRedirect();
        $this->assertStringContainsString('demo.myshopify.com/admin/oauth/authorize', $res->headers->get('Location'));
        $this->assertStringContainsString('client_id=test_key', $res->headers->get('Location'));
    }

    public function test_begin_rejects_non_myshopify_domain(): void
    {
        $this->get('/api/auth?shop=evil.example.com')->assertStatus(400);
    }

    public function test_callback_exchanges_code_and_stores_encrypted_token(): void
    {
        Http::fake([
            '*/admin/oauth/access_token' => Http::response([
                'access_token' => 'shpat_realtoken',
                'scope' => 'write_products',
            ], 200),
            '*/webhooks.json' => Http::response(['webhook' => ['id' => 1]], 201),
        ]);

        Cache::put('shopify_oauth_state:demo.myshopify.com', 'thestate', now()->addMinutes(10));

        $query = $this->signedQuery([
            'shop' => 'demo.myshopify.com',
            'code' => 'authcode123',
            'state' => 'thestate',
        ]);

        $res = $this->get('/api/auth/callback?' . http_build_query($query));

        // Lands in the embedded admin app.
        $res->assertRedirect('https://admin.shopify.com/store/demo/apps/test_key');

        $shop = Shop::where('shopify_domain', 'demo.myshopify.com')->first();
        $this->assertNotNull($shop);
        $this->assertSame('shpat_realtoken', $shop->access_token); // decrypted via cast
        // Stored value in the DB must NOT be the plaintext token.
        $raw = \DB::table('shops')->where('shopify_domain', 'demo.myshopify.com')->value('access_token');
        $this->assertNotSame('shpat_realtoken', $raw, 'token must be encrypted at rest');
    }

    public function test_callback_rejects_bad_hmac(): void
    {
        Cache::put('shopify_oauth_state:demo.myshopify.com', 'thestate', now()->addMinutes(10));

        $this->get('/api/auth/callback?' . http_build_query([
            'shop' => 'demo.myshopify.com',
            'code' => 'authcode123',
            'state' => 'thestate',
            'hmac' => 'deadbeef',
        ]))->assertStatus(403);
    }
}
