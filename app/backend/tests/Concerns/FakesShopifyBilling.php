<?php

namespace Tests\Concerns;

use App\Models\AdminUser;
use App\Services\ShopifyBilling;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/**
 * A stateful fake of the Shopify Billing API, following the documented
 * behaviour: subscriptions are created PENDING, become ACTIVE when approved
 * (cancelling whatever was active — one active subscription per merchant),
 * CANCELLED by appSubscriptionCancel, and currentPeriodEnd is null unless ACTIVE.
 */
trait FakesShopifyBilling
{
    protected const DOMAIN = 'app-dev-test.myshopify.com';

    /** @var array<string, array> Shopify AppSubscription nodes, in creation order */
    protected array $shopifySubs = [];

    /** @var list<array{query: string, variables: array, token: ?string}> */
    protected array $graphqlCalls = [];

    protected array $shopifyBehaviour = [
        'create_fails' => false,
        'cancel_fails' => false,
        'unreachable' => false,
        'revoked_token' => null,
    ];

    private int $nextShopifyId = 9000;

    private bool $shopifyFaked = false;

    protected function configureShopify(): void
    {
        config([
            'services.shopify.api_key' => 'test_key',
            'services.shopify.api_secret' => 'test_secret',
            'services.shopify.app_url' => 'https://shopshift.net/app',
            'services.shopify.billing_test' => true,
            'services.shopify.billing_prorate_cancellations' => false,
        ]);

        $this->fakeShopify();
    }

    protected function fakeShopify(): void
    {
        if ($this->shopifyFaked) {
            return;
        }
        $this->shopifyFaked = true;

        // Http::fake keeps the FIRST stub, so this is registered once and reads
        // the mutable state above on every request.
        Http::fake(function (HttpRequest $request) {
            if (str_contains($request->url(), '/admin/oauth/access_token')) {
                return Http::response(['access_token' => 'shpat_reinstalled', 'scope' => 'write_products', 'expires_in' => 3600, 'refresh_token' => 'shprt_new', 'refresh_token_expires_in' => 7776000]);
            }

            $token = $request->header('X-Shopify-Access-Token')[0] ?? null;
            $body = json_decode($request->body(), true) ?? [];
            $query = $body['query'] ?? '';
            $variables = (array) ($body['variables'] ?? []);
            $this->graphqlCalls[] = ['query' => $query, 'variables' => $variables, 'token' => $token];

            if ($this->shopifyBehaviour['revoked_token'] !== null && $token === $this->shopifyBehaviour['revoked_token']) {
                return Http::response(['errors' => '[API] Invalid API key or access token'], 401);
            }

            if ($this->shopifyBehaviour['unreachable']) {
                return Http::response('Service unavailable', 503);
            }

            if (str_contains($query, 'appSubscriptionCreate')) {
                if ($this->shopifyBehaviour['create_fails']) {
                    return Http::response(['data' => ['appSubscriptionCreate' => ['confirmationUrl' => null, 'appSubscription' => null,
                        'userErrors' => [['field' => ['lineItems'], 'message' => 'GraphQL internal: pricing plan invalid for shop']]]]]);
                }

                $id = 'gid://shopify/AppSubscription/'.$this->nextShopifyId++;
                $item = $variables['lineItems'][0]['plan']['appRecurringPricingDetails'] ?? [];
                $this->shopifySubs[$id] = [
                    'id' => $id,
                    'name' => $variables['name'],
                    'status' => 'PENDING',
                    'test' => (bool) ($variables['test'] ?? false),
                    'createdAt' => now()->toIso8601String(),
                    'currentPeriodEnd' => null,
                    'trialDays' => 0,
                    'returnUrl' => $variables['returnUrl'],
                    'lineItems' => [['plan' => ['pricingDetails' => [
                        '__typename' => 'AppRecurringPricing',
                        'interval' => $item['interval'] ?? 'EVERY_30_DAYS',
                        'price' => ['amount' => (string) ($item['price']['amount'] ?? 0), 'currencyCode' => $item['price']['currencyCode'] ?? 'USD'],
                    ]]]],
                ];

                return Http::response(['data' => ['appSubscriptionCreate' => [
                    'confirmationUrl' => 'https://'.self::DOMAIN.'/admin/charges/confirm/'.$this->nextShopifyId,
                    'appSubscription' => ['id' => $id, 'status' => 'PENDING', 'createdAt' => now()->toIso8601String(), 'test' => (bool) ($variables['test'] ?? false)],
                    'userErrors' => [],
                ]]]);
            }

            if (str_contains($query, 'appSubscriptionCancel')) {
                $id = $variables['id'] ?? null;

                if ($this->shopifyBehaviour['cancel_fails'] || ! isset($this->shopifySubs[$id])) {
                    return Http::response(['data' => ['appSubscriptionCancel' => ['appSubscription' => null,
                        'userErrors' => [['field' => ['id'], 'message' => 'Internal: could not cancel']]]]]);
                }

                $this->shopifySubs[$id]['status'] = 'CANCELLED';
                $this->shopifySubs[$id]['currentPeriodEnd'] = null;

                return Http::response(['data' => ['appSubscriptionCancel' => ['appSubscription' => ['id' => $id, 'status' => 'CANCELLED'], 'userErrors' => []]]]);
            }

            if (str_contains($query, 'currentAppInstallation')) {
                $all = array_values($this->shopifySubs);

                return Http::response(['data' => ['currentAppInstallation' => [
                    'activeSubscriptions' => array_values(array_filter($all, fn ($s) => $s['status'] === 'ACTIVE')),
                    'allSubscriptions' => ['nodes' => array_reverse($all)],
                ]]]);
            }

            // Webhook registrar and anything else.
            return Http::response(['data' => ['webhookSubscriptions' => ['edges' => []]]]);
        });
    }

    /** Put a subscription on "Shopify". */
    protected function shopifySub(string $id, string $planName, string $planCode, float $price, string $status = 'ACTIVE', ?Carbon $periodEnd = null, string $interval = 'EVERY_30_DAYS', ?Carbon $createdAt = null): void
    {
        $this->shopifySubs[$id] = [
            'id' => $id,
            'name' => $planName,
            'status' => $status,
            'test' => true,
            'createdAt' => ($createdAt ?? now()->subDays(5))->toIso8601String(),
            'currentPeriodEnd' => $status === 'ACTIVE' ? ($periodEnd ?? now()->addDays(25))->toIso8601String() : null,
            'trialDays' => 0,
            'returnUrl' => 'https://shopshift.net/app/api/billing/callback?shop='.self::DOMAIN."&plan={$planCode}",
            'lineItems' => [['plan' => ['pricingDetails' => [
                '__typename' => 'AppRecurringPricing', 'interval' => $interval,
                'price' => ['amount' => (string) $price, 'currencyCode' => 'USD'],
            ]]]],
        ];
    }

    /** The merchant approves on Shopify's screen: it replaces whatever was active. */
    protected function approveOnShopify(string $id, ?Carbon $periodEnd = null): void
    {
        foreach ($this->shopifySubs as $otherId => $sub) {
            if ($otherId !== $id && $sub['status'] === 'ACTIVE') {
                $this->shopifySubs[$otherId]['status'] = 'CANCELLED';
                $this->shopifySubs[$otherId]['currentPeriodEnd'] = null;
            }
        }

        $this->shopifySubs[$id]['status'] = 'ACTIVE';
        $this->shopifySubs[$id]['currentPeriodEnd'] = ($periodEnd ?? now()->addDays(30))->toIso8601String();
    }

    protected function setShopifyStatus(string $id, string $status): void
    {
        $this->shopifySubs[$id]['status'] = $status;

        if ($status !== 'ACTIVE') {
            $this->shopifySubs[$id]['currentPeriodEnd'] = null;
        }
    }

    protected function createCalls(): int
    {
        return collect($this->graphqlCalls)->filter(fn ($c) => str_contains($c['query'], 'appSubscriptionCreate'))->count();
    }

    protected function cancelCalls(): array
    {
        return collect($this->graphqlCalls)->filter(fn ($c) => str_contains($c['query'], 'appSubscriptionCancel'))->values()->all();
    }

    /** A valid App Bridge session token for DOMAIN. */
    protected function sessionToken(): string
    {
        $b64 = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $header = $b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payload = $b64(json_encode([
            'iss' => 'https://'.self::DOMAIN.'/admin', 'dest' => 'https://'.self::DOMAIN, 'aud' => 'test_key',
            'sub' => '1', 'exp' => time() + 60, 'nbf' => time() - 5, 'iat' => time() - 5, 'jti' => 'jti', 'sid' => 'sid',
        ]));

        return "{$header}.{$payload}.".$b64(hash_hmac('sha256', "{$header}.{$payload}", 'test_secret', true));
    }

    protected function app(): static
    {
        return $this->withHeader('Authorization', 'Bearer '.$this->sessionToken());
    }

    protected function billing(bool $refresh = true): TestResponse
    {
        return $this->app()->getJson('/api/billing'.($refresh ? '?refresh=1' : ''));
    }

    protected function subscribeTo(string $planCode): TestResponse
    {
        return $this->app()->postJson('/api/billing/subscribe', ['plan' => $planCode]);
    }

    protected function billingCallback(string $planCode, string $gid, bool $signed = true): TestResponse
    {
        $query = http_build_query(array_filter([
            'shop' => self::DOMAIN,
            'plan' => $planCode,
            'sig' => $signed ? ShopifyBilling::returnSignature(self::DOMAIN, $planCode) : 'forged',
            'charge_id' => substr($gid, strrpos($gid, '/') + 1),
        ]));

        return $this->get("/api/billing/callback?{$query}");
    }

    protected function signedWebhook(string $path, array $payload, ?string $deliveryId = null): TestResponse
    {
        $raw = json_encode($payload);

        return $this->call('POST', "/api/webhooks/{$path}", [], [], [], array_filter([
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SHOPIFY_HMAC_SHA256' => base64_encode(hash_hmac('sha256', $raw, 'test_secret', true)),
            'HTTP_X_SHOPIFY_SHOP_DOMAIN' => self::DOMAIN,
            'HTTP_X_SHOPIFY_WEBHOOK_ID' => $deliveryId,
        ]), $raw);
    }

    protected function subscriptionWebhook(string $id, string $status, ?Carbon $updatedAt = null, ?string $deliveryId = null): TestResponse
    {
        return $this->signedWebhook('app-subscriptions-update', ['app_subscription' => [
            'admin_graphql_api_id' => $id,
            'name' => $this->shopifySubs[$id]['name'] ?? 'Plan',
            'status' => $status,
            'admin_graphql_api_shop_id' => 'gid://shopify/Shop/1',
            'created_at' => now()->subHour()->toIso8601String(),
            'updated_at' => ($updatedAt ?? now())->toIso8601String(),
            'currency' => 'USD',
            'price' => '50.00',
            'interval' => 'every_30_days',
        ]], $deliveryId);
    }

    protected function adminHeaders(): array
    {
        $token = str_repeat('c', 64);

        AdminUser::firstOrCreate(['email' => 'admin@example.com'], [
            'name' => 'Admin',
            'password' => bcrypt('secret'),
            'api_token_hash' => hash('sha256', $token),
        ]);

        return ['Authorization' => "Bearer {$token}"];
    }

    protected function events(): array
    {
        return \App\Models\BillingEvent::orderBy('id')->pluck('event')->all();
    }
}
