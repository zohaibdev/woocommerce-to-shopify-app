<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Services\ShopifyWebhookRegistrar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WebhookRegistrarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.shopify.api_key' => 'test_key',
            'services.shopify.api_secret' => 'test_secret',
            'services.shopify.app_url' => 'https://shift-shop.webnday.com/app',
        ]);
    }

    private function shop(): Shop
    {
        return Shop::create([
            'shopify_domain' => 'demo.myshopify.com',
            'access_token' => 'shpat_x',
            'token_expires_at' => now()->addHour(),
        ]);
    }

    public function test_creates_subscription_when_none_exists(): void
    {
        Http::fake([
            '*/graphql.json' => Http::response([
                'data' => [
                    'webhookSubscriptions' => ['edges' => []],
                    'webhookSubscriptionCreate' => [
                        'webhookSubscription' => ['id' => 'gid://shopify/WebhookSubscription/1'],
                        'userErrors' => [],
                    ],
                ],
            ], 200),
        ]);

        $results = app(ShopifyWebhookRegistrar::class)->sync($this->shop());

        $this->assertSame('created', $results['APP_UNINSTALLED']);

        // The callback must target the current app URL, including the /app prefix.
        Http::assertSent(function ($req) {
            $body = json_decode($req->body(), true);
            $url = data_get($body, 'variables.sub.callbackUrl');
            return $url === null
                || $url === 'https://shift-shop.webnday.com/app/api/webhooks/app-uninstalled'
                || str_starts_with((string) $url, 'https://shift-shop.webnday.com/app/api/webhooks/');
        });
    }

    public function test_updates_stale_callback_url_instead_of_duplicating(): void
    {
        Http::fake([
            '*/graphql.json' => Http::response([
                'data' => [
                    'webhookSubscriptions' => [
                        'edges' => [[
                            'node' => [
                                'id' => 'gid://shopify/WebhookSubscription/99',
                                'topic' => 'APP_UNINSTALLED',
                                // Registered against a dead tunnel URL.
                                'endpoint' => [
                                    'callbackUrl' => 'https://old-tunnel.trycloudflare.com/api/webhooks/app-uninstalled',
                                ],
                            ],
                        ]],
                    ],
                    'webhookSubscriptionUpdate' => [
                        'webhookSubscription' => ['id' => 'gid://shopify/WebhookSubscription/99'],
                        'userErrors' => [],
                    ],
                    'webhookSubscriptionCreate' => [
                        'webhookSubscription' => ['id' => 'gid://shopify/WebhookSubscription/2'],
                        'userErrors' => [],
                    ],
                ],
            ], 200),
        ]);

        $results = app(ShopifyWebhookRegistrar::class)->sync($this->shop());

        $this->assertSame('updated', $results['APP_UNINSTALLED'], 'stale URL must be repointed');

        Http::assertSent(fn ($req) => str_contains($req->body(), 'webhookSubscriptionUpdate'));
    }

    public function test_leaves_correct_subscription_untouched(): void
    {
        Http::fake([
            '*/graphql.json' => Http::response([
                'data' => [
                    'webhookSubscriptions' => [
                        'edges' => [[
                            'node' => [
                                'id' => 'gid://shopify/WebhookSubscription/7',
                                'topic' => 'APP_UNINSTALLED',
                                'endpoint' => [
                                    'callbackUrl' => 'https://shift-shop.webnday.com/app/api/webhooks/app-uninstalled',
                                ],
                            ],
                        ]],
                    ],
                    'webhookSubscriptionCreate' => ['webhookSubscription' => null, 'userErrors' => []],
                ],
            ], 200),
        ]);

        $results = app(ShopifyWebhookRegistrar::class)->sync($this->shop());

        $this->assertSame('ok', $results['APP_UNINSTALLED']);
    }
}
