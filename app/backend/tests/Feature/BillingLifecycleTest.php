<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Shop;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * App Store review 1.2.2: "Upon reinstallation, the app fails to accurately
 * reflect the previously cancelled subscription state or its remaining
 * expiration timeframe."
 *
 * The app/uninstalled webhook only cleared the token, so the cancelled charge
 * stayed 'active' locally and a reinstall — which arrives by token exchange, not
 * the OAuth callback — showed it as the merchant's plan. The same area also
 * granted a DECLINED upgrade, and "Switch to Free" never cancelled the Shopify
 * charge at all.
 */
class BillingLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private const PRO_CHARGE = 'gid://shopify/AppSubscription/1001';

    private const GROWTH_CHARGE = 'gid://shopify/AppSubscription/2002';

    private Shop $shop;

    private Plan $starter;

    private Plan $growth;

    private Plan $pro;

    /** @var list<array{query:string,variables:array}> */
    private array $graphqlCalls = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.shopify.api_key' => 'test_key',
            'services.shopify.api_secret' => 'test_secret',
            'services.shopify.app_url' => 'https://shopshift.net/app',
        ]);

        $this->shop = Shop::create([
            'shopify_domain' => 'reviewer.myshopify.com',
            'access_token' => 'shpat_x',
            'token_expires_at' => now()->addHour(),
            'installed_at' => now()->subMonths(2),
        ]);

        $this->starter = Plan::create(['code' => 'starter', 'name' => 'Starter', 'price' => 0, 'interval' => 'monthly', 'active' => true, 'limits' => ['products' => 10]]);
        $this->growth = Plan::create(['code' => 'growth', 'name' => 'Growth', 'price' => 19, 'interval' => 'monthly', 'active' => true, 'limits' => ['products' => 2000]]);
        $this->pro = Plan::create(['code' => 'pro', 'name' => 'Pro', 'price' => 49, 'interval' => 'monthly', 'active' => true, 'limits' => ['total' => 20000]]);
    }

    private function activePro(int $daysLeft = 20): Subscription
    {
        return Subscription::create([
            'shop_id' => $this->shop->id,
            'plan_id' => $this->pro->id,
            'status' => 'active',
            'shopify_charge_id' => self::PRO_CHARGE,
            'started_at' => now()->subDays(30 - $daysLeft),
            'current_period_end' => now()->addDays($daysLeft),
        ]);
    }

    /**
     * Fake the Admin GraphQL API.
     *
     * @param  list<array>  $active  currentAppInstallation.activeSubscriptions
     * @param  list<array>  $all  currentAppInstallation.allSubscriptions.nodes
     */
    private function fakeShopify(array $active = [], array $all = []): void
    {
        Http::fake(function (HttpRequest $request) use ($active, $all) {
            $body = json_decode($request->body(), true);
            $this->graphqlCalls[] = ['query' => $body['query'] ?? '', 'variables' => (array) ($body['variables'] ?? [])];
            $query = $body['query'] ?? '';

            if (str_contains($query, 'appSubscriptionCancel')) {
                return Http::response(['data' => ['appSubscriptionCancel' => [
                    'appSubscription' => ['id' => self::PRO_CHARGE, 'status' => 'CANCELLED'],
                    'userErrors' => [],
                ]]]);
            }

            if (str_contains($query, 'appSubscriptionCreate')) {
                return Http::response(['data' => ['appSubscriptionCreate' => [
                    'confirmationUrl' => 'https://reviewer.myshopify.com/admin/charges/confirm',
                    'appSubscription' => ['id' => self::GROWTH_CHARGE, 'status' => 'PENDING'],
                    'userErrors' => [],
                ]]]);
            }

            return Http::response(['data' => ['currentAppInstallation' => [
                'activeSubscriptions' => $active,
                'allSubscriptions' => ['nodes' => $all],
            ]]]);
        });
    }

    private function billing(): \Illuminate\Testing\TestResponse
    {
        return $this->withHeader('X-Shop-Domain', $this->shop->shopify_domain)->getJson('/api/billing');
    }

    private function signedWebhook(string $path, array $payload): \Illuminate\Testing\TestResponse
    {
        $raw = json_encode($payload);

        return $this->call('POST', "/api/webhooks/{$path}", [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SHOPIFY_HMAC_SHA256' => base64_encode(hash_hmac('sha256', $raw, 'test_secret', true)),
            'HTTP_X_SHOPIFY_SHOP_DOMAIN' => $this->shop->shopify_domain,
        ], $raw);
    }

    // --- The reviewer's scenario --------------------------------------------

    public function test_reinstall_shows_the_cancelled_plan_and_how_long_it_has_left(): void
    {
        $this->activePro(daysLeft: 20);

        // Uninstall: Shopify cancels the charge and revokes the token.
        $this->signedWebhook('app-uninstalled', ['id' => 1])->assertOk();

        $sub = Subscription::first();
        $this->assertSame('cancelled', $sub->status);
        $this->assertSame('uninstalled', $sub->cancellation_reason);
        $this->assertEqualsWithDelta(now()->addDays(20)->timestamp, $sub->ends_at->timestamp, 5);

        // Reinstall. Shopify has no active charge for the new installation.
        $this->shop->forceFill(['access_token' => 'shpat_new', 'uninstalled_at' => null])->save();
        $this->fakeShopify(active: [], all: []);

        $this->billing()
            ->assertOk()
            ->assertJsonPath('subscription.state', 'cancelled_grace')
            ->assertJsonPath('subscription.plan.code', 'pro')
            ->assertJsonPath('subscription.cancellation_reason', 'uninstalled')
            ->assertJsonPath('subscription.days_remaining', 20)
            // Still paid up, so still Pro — not silently Free.
            ->assertJsonPath('current_plan.code', 'pro');
    }

    public function test_a_missed_uninstall_webhook_is_reconciled_from_shopify(): void
    {
        // The webhook went to a dead domain; locally the charge still looks live.
        $this->activePro(daysLeft: 9);

        $this->fakeShopify(active: [], all: [
            ['id' => self::PRO_CHARGE, 'name' => 'Pro', 'status' => 'CANCELLED', 'currentPeriodEnd' => null],
        ]);

        $this->billing()
            ->assertOk()
            ->assertJsonPath('subscription.state', 'cancelled_grace')
            ->assertJsonPath('subscription.days_remaining', 9)
            ->assertJsonPath('subscription_status', null);
    }

    public function test_once_the_paid_period_runs_out_the_shop_is_on_free_and_is_told_so(): void
    {
        $this->activePro(daysLeft: 3);
        $this->signedWebhook('app-uninstalled', ['id' => 1])->assertOk();

        $this->travel(4)->days();
        $this->shop->forceFill(['access_token' => 'shpat_new', 'uninstalled_at' => null])->save();
        $this->fakeShopify();

        $this->billing()
            ->assertOk()
            ->assertJsonPath('subscription.state', 'ended')
            ->assertJsonPath('subscription.plan.code', 'pro')
            ->assertJsonPath('subscription.days_remaining', 0)
            ->assertJsonPath('current_plan.code', 'starter');
    }

    public function test_reapproving_during_the_grace_period_defers_the_charge_instead_of_double_billing(): void
    {
        $sub = $this->activePro(daysLeft: 12);
        $sub->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancellation_reason' => 'uninstalled', 'ends_at' => now()->addDays(12)]);
        $this->fakeShopify();

        $this->withHeader('X-Shop-Domain', $this->shop->shopify_domain)
            ->postJson('/api/billing/subscribe', ['plan' => 'pro'])
            ->assertOk()
            ->assertJsonPath('confirmation_url', 'https://reviewer.myshopify.com/admin/charges/confirm');

        $create = collect($this->graphqlCalls)->first(fn ($c) => str_contains($c['query'], 'appSubscriptionCreate'));
        $this->assertSame(12, $create['variables']['trialDays']);
    }

    // --- Accept and decline -------------------------------------------------

    public function test_a_declined_upgrade_is_not_granted(): void
    {
        $this->activePro();
        Subscription::create(['shop_id' => $this->shop->id, 'plan_id' => $this->growth->id, 'status' => 'pending', 'shopify_charge_id' => self::GROWTH_CHARGE]);

        // Pro is still live; the Growth charge was declined on Shopify's screen.
        $this->fakeShopify(
            active: [['id' => self::PRO_CHARGE, 'name' => 'Pro', 'status' => 'ACTIVE', 'currentPeriodEnd' => now()->addDays(20)->toIso8601String()]],
            all: [['id' => self::GROWTH_CHARGE, 'name' => 'Growth', 'status' => 'DECLINED']],
        );

        $this->get('/api/billing/callback?shop=reviewer.myshopify.com&plan=growth&charge_id=2002')
            ->assertRedirectContains('billing=declined');

        $this->shop->forgetPlan();
        $this->assertSame('pro', $this->shop->currentPlan()->code);
        $this->assertSame('declined', Subscription::where('shopify_charge_id', self::GROWTH_CHARGE)->value('cancellation_reason'));
    }

    public function test_an_approved_charge_replaces_the_previous_plan(): void
    {
        $this->activePro();
        Subscription::create(['shop_id' => $this->shop->id, 'plan_id' => $this->growth->id, 'status' => 'pending', 'shopify_charge_id' => self::GROWTH_CHARGE]);

        $periodEnd = now()->addDays(30)->startOfSecond();
        $this->fakeShopify(active: [['id' => self::GROWTH_CHARGE, 'name' => 'Growth', 'status' => 'ACTIVE', 'currentPeriodEnd' => $periodEnd->toIso8601String()]]);

        $this->get('/api/billing/callback?shop=reviewer.myshopify.com&plan=growth&charge_id=2002')
            ->assertRedirectContains('billing=success');

        $growth = Subscription::where('shopify_charge_id', self::GROWTH_CHARGE)->first();
        $this->assertSame('active', $growth->status);
        $this->assertTrue($growth->current_period_end->equalTo($periodEnd));
        $this->assertSame('replaced', Subscription::where('shopify_charge_id', self::PRO_CHARGE)->value('cancellation_reason'));
    }

    // --- Downgrade ------------------------------------------------------------

    public function test_switching_to_free_cancels_the_shopify_charge_and_keeps_the_paid_days(): void
    {
        $this->activePro(daysLeft: 15);
        $this->fakeShopify();

        $this->withHeader('X-Shop-Domain', $this->shop->shopify_domain)
            ->postJson('/api/billing/subscribe', ['plan' => 'starter'])
            ->assertOk()
            ->assertJsonPath('confirmation_url', null);

        // It really told Shopify to stop billing.
        $this->assertTrue(collect($this->graphqlCalls)->contains(fn ($c) => str_contains($c['query'], 'appSubscriptionCancel')));

        $this->fakeShopify(active: [], all: [['id' => self::PRO_CHARGE, 'name' => 'Pro', 'status' => 'CANCELLED']]);

        $this->billing()
            ->assertJsonPath('subscription.state', 'cancelled_grace')
            ->assertJsonPath('subscription.cancellation_reason', 'merchant')
            ->assertJsonPath('subscription.days_remaining', 15)
            ->assertJsonPath('current_plan.code', 'pro');
    }

    // --- Safety ---------------------------------------------------------------

    public function test_a_failed_lookup_never_downgrades_a_paying_merchant(): void
    {
        $this->activePro();
        Http::fake(['*' => Http::response(['errors' => 'Forbidden'], 403)]);

        $this->billing()
            ->assertOk()
            ->assertJsonPath('subscription.state', 'active')
            ->assertJsonPath('current_plan.code', 'pro');
    }

    public function test_subscription_update_webhook_applies_a_cancellation(): void
    {
        $this->activePro(daysLeft: 6);
        $this->shop->forceFill(['access_token' => null])->save();

        $this->signedWebhook('app-subscriptions-update', ['app_subscription' => [
            'admin_graphql_api_id' => self::PRO_CHARGE,
            'status' => 'CANCELLED',
        ]])->assertOk();

        $sub = Subscription::first();
        $this->assertSame('cancelled', $sub->status);
        $this->assertTrue($sub->inPaidGracePeriod());
    }

    public function test_the_subscription_update_webhook_rejects_an_invalid_hmac(): void
    {
        $this->call('POST', '/api/webhooks/app-subscriptions-update', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SHOPIFY_HMAC_SHA256' => 'invalid',
        ], '{}')->assertStatus(401);
    }
}
