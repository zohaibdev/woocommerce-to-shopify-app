<?php

namespace Tests\Feature;

use App\Jobs\SyncShopBillingJob;
use App\Models\BillingEvent;
use App\Models\Plan;
use App\Models\Shop;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\FakesShopifyBilling;
use Tests\TestCase;

/**
 * Admin billing visibility and actions, and the scheduled drift check.
 */
class BillingAdminAndSyncTest extends TestCase
{
    use FakesShopifyBilling;
    use RefreshDatabase;

    private const BASIC = 'gid://shopify/AppSubscription/1001';

    private Shop $shop;

    private Plan $basic;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureShopify();

        $this->shop = Shop::create(['shopify_domain' => self::DOMAIN, 'access_token' => 'shpat_x', 'token_expires_at' => now()->addHour(), 'installed_at' => now()->subMonth()]);
        Plan::create(['code' => 'starter', 'name' => 'Starter', 'price' => 0, 'interval' => 'monthly', 'active' => true, 'limits' => []]);
        $this->basic = Plan::create(['code' => 'basic', 'name' => 'Basic', 'price' => 50, 'interval' => 'monthly', 'active' => true, 'limits' => []]);
    }

    private function onBasic(int $daysLeft = 20, ?Shop $shop = null, string $gid = self::BASIC): Subscription
    {
        $this->shopifySub($gid, 'Basic', 'basic', 50, 'ACTIVE', now()->addDays($daysLeft));

        return Subscription::create([
            'shop_id' => ($shop ?? $this->shop)->id, 'plan_id' => $this->basic->id, 'status' => 'active',
            'shopify_charge_id' => $gid, 'price' => 50, 'currency' => 'USD', 'interval' => 'EVERY_30_DAYS',
            'started_at' => now()->subDays(30 - $daysLeft), 'current_period_end' => now()->addDays($daysLeft),
        ]);
    }

    public function test_admin_billing_endpoints_require_admin_auth(): void
    {
        $this->getJson("/api/admin/stores/{$this->shop->id}")->assertStatus(401);
        $this->postJson("/api/admin/stores/{$this->shop->id}/billing/sync")->assertStatus(401);
        $this->postJson("/api/admin/stores/{$this->shop->id}/billing/cancel", ['confirm_domain' => self::DOMAIN, 'reason' => 'testing it', 'prorate' => false])->assertStatus(401);

        // A merchant session token is not an admin credential.
        $this->app()->postJson("/api/admin/stores/{$this->shop->id}/billing/cancel", ['confirm_domain' => self::DOMAIN, 'reason' => 'testing it', 'prorate' => false])->assertStatus(401);

        $this->assertCount(0, $this->cancelCalls());
    }

    public function test_admin_sees_full_billing_detail_history_and_subscription_rows(): void
    {
        $this->onBasic();

        $response = $this->withHeaders($this->adminHeaders())->getJson("/api/admin/stores/{$this->shop->id}")
            ->assertOk()
            ->assertJsonPath('billing.state', 'active')
            ->assertJsonPath('billing.plan.code', 'basic')
            ->assertJsonPath('billing.price', 50)
            ->assertJsonPath('billing.interval', 'EVERY_30_DAYS')
            ->assertJsonPath('billing.admin.shopify_subscription_id', self::BASIC)
            ->assertJsonPath('billing.shopify_status', 'ACTIVE')
            ->assertJsonPath('subscriptions.0.shopify_subscription_id', self::BASIC);

        $this->assertNotNull($response->json('billing.renews_at'));
        $this->assertNotNull($response->json('billing.sync.last_synced_at'));

        $row = collect($this->withHeaders($this->adminHeaders())->getJson('/api/admin/stores')->json('data'))->firstWhere('id', $this->shop->id);
        $this->assertSame('active', $row['subscription']['state']);
        $this->assertSame('Basic', $row['plan']);
    }

    public function test_admin_sync_reconciles_now_and_reports_failure_without_changing_anything(): void
    {
        $this->onBasic();
        $this->setShopifyStatus(self::BASIC, 'CANCELLED');

        $this->withHeaders($this->adminHeaders())->postJson("/api/admin/stores/{$this->shop->id}/billing/sync")
            ->assertOk()
            ->assertJsonPath('synced', true)
            ->assertJsonPath('billing.state', 'cancelled');

        $this->shopifyBehaviour['unreachable'] = true;
        $this->withHeaders($this->adminHeaders())->postJson("/api/admin/stores/{$this->shop->id}/billing/sync")
            ->assertStatus(502)
            ->assertJsonPath('synced', false)
            ->assertJsonPath('billing.state', 'cancelled');
    }

    public function test_admin_cancel_requires_the_domain_typed_back_and_a_reason(): void
    {
        $this->onBasic();
        $url = "/api/admin/stores/{$this->shop->id}/billing/cancel";

        $this->withHeaders($this->adminHeaders())->postJson($url, ['confirm_domain' => 'wrong.myshopify.com', 'reason' => 'refund request', 'prorate' => true])->assertStatus(422);
        $this->withHeaders($this->adminHeaders())->postJson($url, ['confirm_domain' => self::DOMAIN, 'prorate' => true])->assertStatus(422);

        $this->assertCount(0, $this->cancelCalls());
        $this->assertSame('active', Subscription::sole()->status);
    }

    public function test_admin_cancel_cancels_on_shopify_with_the_chosen_proration_and_is_audited(): void
    {
        $this->onBasic();

        $this->withHeaders($this->adminHeaders())->postJson("/api/admin/stores/{$this->shop->id}/billing/cancel", [
            'confirm_domain' => self::DOMAIN, 'reason' => 'Merchant asked for a refund', 'prorate' => true,
        ])->assertOk()->assertJsonPath('billing.state', 'ended')->assertJsonPath('billing.cancellation_reason', 'admin');

        $this->assertTrue($this->cancelCalls()[0]['variables']['prorate']);
        $this->assertSame('CANCELLED', $this->shopifySubs[self::BASIC]['status']);

        $requested = BillingEvent::where('event', 'cancellation_requested')->sole();
        $this->assertSame('admin', $requested->source);
        $this->assertStringContainsString('Merchant asked for a refund', $requested->message);
        $this->assertStringContainsString('admin@example.com', $requested->message);

        // The merchant sees it immediately too.
        $this->billing(refresh: false)->assertJsonPath('subscription.state', 'ended')->assertJsonPath('current_plan.code', 'starter');
    }

    public function test_admin_cancel_never_fakes_a_local_cancellation_when_shopify_refuses(): void
    {
        $this->onBasic();
        $this->shopifyBehaviour['cancel_fails'] = true;

        $this->withHeaders($this->adminHeaders())->postJson("/api/admin/stores/{$this->shop->id}/billing/cancel", [
            'confirm_domain' => self::DOMAIN, 'reason' => 'Merchant asked for a refund', 'prorate' => false,
        ])->assertStatus(502);

        $this->assertSame('active', Subscription::sole()->status);
    }

    public function test_the_scheduled_sync_only_queues_shops_that_can_have_drifted(): void
    {
        Queue::fake();

        // Needs it: renewal due.
        $renewalDue = Shop::create(['shopify_domain' => 'due.myshopify.com', 'access_token' => 'shpat_a', 'token_expires_at' => now()->addHour()]);
        $renewalDue->forceFill(['billing_synced_at' => now()])->save();
        $this->onBasic(daysLeft: 0, shop: $renewalDue, gid: 'gid://shopify/AppSubscription/2');
        Subscription::where('shop_id', $renewalDue->id)->update(['current_period_end' => now()->subHour()]);

        // Needs it: open checkout.
        $checkout = Shop::create(['shopify_domain' => 'checkout.myshopify.com', 'access_token' => 'shpat_b', 'token_expires_at' => now()->addHour()]);
        $checkout->forceFill(['billing_synced_at' => now()])->save();
        Subscription::create(['shop_id' => $checkout->id, 'plan_id' => $this->basic->id, 'status' => 'pending', 'shopify_charge_id' => 'gid://shopify/AppSubscription/3']);

        // Doesn't: active, period in the future, synced recently.
        $fresh = Shop::create(['shopify_domain' => 'fresh.myshopify.com', 'access_token' => 'shpat_c', 'token_expires_at' => now()->addHour()]);
        $fresh->forceFill(['billing_synced_at' => now()])->save();
        $this->onBasic(daysLeft: 20, shop: $fresh, gid: 'gid://shopify/AppSubscription/4');

        // Doesn't: uninstalled, or free.
        $gone = Shop::create(['shopify_domain' => 'gone.myshopify.com', 'access_token' => null, 'uninstalled_at' => now()]);
        $this->onBasic(daysLeft: 0, shop: $gone, gid: 'gid://shopify/AppSubscription/5');
        Shop::create(['shopify_domain' => 'free.myshopify.com', 'access_token' => 'shpat_d', 'token_expires_at' => now()->addHour()]);

        $this->artisan('billing:sync')->assertSuccessful();

        $queued = collect(Queue::pushed(SyncShopBillingJob::class))->map(fn ($job) => $job->shopId)->sort()->values()->all();
        $this->assertSame([$renewalDue->id, $checkout->id], $queued);
    }

    public function test_the_sync_job_skips_uninstalled_shops(): void
    {
        $this->onBasic();
        $this->shop->forceFill(['uninstalled_at' => now(), 'access_token' => null])->save();

        (new SyncShopBillingJob($this->shop->id))->handle(app(\App\Services\ShopifyBilling::class));

        $this->assertSame([], $this->graphqlCalls);
    }
}
