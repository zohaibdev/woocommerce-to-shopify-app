<?php

namespace Tests\Feature;

use App\Models\BillingEvent;
use App\Models\Plan;
use App\Models\Shop;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\FakesShopifyBilling;
use Tests\TestCase;

/**
 * The billing lifecycle end to end, against a stateful fake of the Shopify
 * Billing API: subscribe, approve, upgrade/downgrade, decline, abandon, cancel,
 * freeze, renew, uninstall/reinstall — and the failure scenarios around each
 * (A–N in the billing audit).
 */
class BillingLifecycleTest extends TestCase
{
    use FakesShopifyBilling;
    use RefreshDatabase;

    private const BASIC = 'gid://shopify/AppSubscription/1001';

    private Shop $shop;

    private Plan $starter;

    private Plan $basic;

    private Plan $pro;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureShopify();

        $this->shop = Shop::create([
            'shopify_domain' => self::DOMAIN,
            'access_token' => 'shpat_x',
            'token_expires_at' => now()->addHour(),
            'installed_at' => now()->subDays(10),
        ]);

        $this->starter = Plan::create(['code' => 'starter', 'name' => 'Starter', 'price' => 0, 'interval' => 'monthly', 'active' => true, 'limits' => ['products' => 10]]);
        $this->basic = Plan::create(['code' => 'basic', 'name' => 'Basic', 'price' => 50, 'interval' => 'monthly', 'active' => true, 'limits' => ['products' => 2000]]);
        $this->pro = Plan::create(['code' => 'pro', 'name' => 'Pro', 'price' => 160, 'interval' => 'monthly', 'active' => true, 'limits' => ['products' => 15000]]);
    }

    /** Basic, live on Shopify and recorded locally, with $daysLeft in the period. */
    private function onBasic(int $daysLeft = 20): Subscription
    {
        $this->shopifySub(self::BASIC, 'Basic', 'basic', 50, 'ACTIVE', now()->addDays($daysLeft));

        return Subscription::create([
            'shop_id' => $this->shop->id,
            'plan_id' => $this->basic->id,
            'status' => 'active',
            'shopify_charge_id' => self::BASIC,
            'shopify_status' => 'ACTIVE',
            'price' => 50,
            'currency' => 'USD',
            'interval' => 'EVERY_30_DAYS',
            'started_at' => now()->subDays(30 - $daysLeft),
            'current_period_end' => now()->addDays($daysLeft),
        ]);
    }

    private function entitled(): string
    {
        $shop = $this->shop->fresh();

        return $shop->currentPlan()->code;
    }

    // --- Subscription creation ------------------------------------------------

    public function test_subscribing_creates_one_pending_checkout_and_grants_nothing_until_approved(): void
    {
        $response = $this->subscribeTo('basic')
            ->assertOk()
            ->assertJsonPath('result', 'confirmation_required')
            ->assertJsonPath('reused', false)
            ->assertJsonPath('subscription.state', 'pending')
            ->assertJsonPath('subscription.plan.code', 'basic')
            ->assertJsonPath('current_plan.code', 'starter');

        $this->assertStringStartsWith('https://'.self::DOMAIN.'/admin/charges/confirm', $response->json('confirmation_url'));
        $this->assertSame(1, $this->createCalls());

        $create = collect($this->graphqlCalls)->first(fn ($c) => str_contains($c['query'], 'appSubscriptionCreate'));
        $this->assertSame('STANDARD', $create['variables']['replacementBehavior']);
        $this->assertSame(50, (int) $create['variables']['lineItems'][0]['plan']['appRecurringPricingDetails']['price']['amount']);
        $this->assertStringContainsString('sig=', $create['variables']['returnUrl']);

        $row = Subscription::sole();
        $this->assertSame('pending', $row->status);
        $this->assertSame('PENDING', $row->shopify_status);
        $this->assertSame(['checkout_started'], $this->events());
    }

    // --- Scenario A: upgrade succeeds ----------------------------------------------

    public function test_scenario_a_upgrade_is_confirmed_and_the_previous_plan_is_recorded(): void
    {
        $old = $this->onBasic(daysLeft: 12);

        $gid = $this->subscribeTo('pro')->assertOk()->json('subscription.pending_change') ? Subscription::where('plan_id', $this->pro->id)->value('shopify_charge_id') : null;
        $this->assertNotNull($gid);

        $this->approveOnShopify($gid, now()->addDays(30));
        $this->billingCallback('pro', $gid)->assertRedirectContains('billing=success');

        $this->assertSame('pro', $this->entitled());
        $this->assertSame('cancelled', $old->fresh()->status);
        $this->assertSame('replaced', $old->fresh()->cancellation_reason);
        // A replaced plan does not linger: Shopify settles it by proration.
        $this->assertTrue($old->fresh()->ends_at->lte(now()));

        $this->billing()
            ->assertJsonPath('subscription.state', 'active')
            ->assertJsonPath('subscription.plan.code', 'pro')
            ->assertJsonPath('subscription.previous.plan.code', 'basic')
            ->assertJsonPath('subscription.previous.reason', 'replaced')
            ->assertJsonPath('history.0.event', 'plan_changed');

        $this->assertSame(['checkout_started', 'previous_plan_ended', 'plan_changed'], $this->events());
    }

    public function test_the_change_preview_follows_shopifys_standard_replacement_rules(): void
    {
        $this->onBasic();

        $plans = collect($this->billing()->json('plans'))->keyBy('code');

        $this->assertSame('upgrade', $plans['pro']['change']['type']);
        $this->assertSame('on_approval', $plans['pro']['change']['timing']);
        $this->assertSame('charge_difference', $plans['pro']['change']['proration']);
        $this->assertSame('cancel', $plans['starter']['change']['type']);
        $this->assertSame('current', $plans['basic']['change']['type']);

        // Annual → monthly is deferred to the next billing cycle.
        $annual = Plan::create(['code' => 'basic-annual', 'name' => 'Basic Annual', 'price' => 500, 'interval' => 'yearly', 'active' => true, 'limits' => []]);
        $current = Subscription::create(['shop_id' => $this->shop->id, 'plan_id' => $annual->id, 'status' => 'cancelled', 'price' => 500, 'interval' => 'ANNUAL', 'current_period_end' => now()->addMonths(8)]);
        $preview = \App\Services\ShopifyBilling::changePreview($current, $this->pro);
        $this->assertSame('next_billing_cycle', $preview['timing']);
        $this->assertNotNull($preview['effective_at']);
    }

    // --- Scenario B: the API call fails ----------------------------------------------

    public function test_scenario_b_a_failed_create_shows_a_safe_message_and_changes_nothing(): void
    {
        $this->onBasic();
        $this->shopifyBehaviour['create_fails'] = true;

        $response = $this->subscribeTo('pro')->assertStatus(502)->assertJsonPath('reason', 'shopify_error');

        $this->assertStringContainsString('Your current plan hasn\'t changed', $response->json('error'));
        $this->assertStringNotContainsString('GraphQL', $response->json('error'));
        $this->assertNotEmpty($response->json('reference'));

        $this->assertSame(1, Subscription::count());
        $this->assertSame('basic', $this->entitled());

        $failure = BillingEvent::where('event', 'billing_operation_failed')->sole();
        $this->assertSame($response->json('reference'), $failure->context['reference']);
        $this->assertStringContainsString('pricing plan invalid', $failure->context['error']);
    }

    // --- Scenario C: approved, callback lost ---------------------------------------

    public function test_scenario_c_an_approval_whose_callback_never_arrives_is_picked_up_by_sync(): void
    {
        $this->subscribeTo('basic')->assertOk();
        $gid = Subscription::sole()->shopify_charge_id;

        $this->approveOnShopify($gid);

        $this->billing()->assertJsonPath('subscription.state', 'active')->assertJsonPath('current_plan.code', 'basic');
        $this->assertContains('subscription_activated', $this->events());
    }

    // --- Scenario D: repeated clicks --------------------------------------------------

    public function test_scenario_d_repeated_clicks_reuse_one_checkout_and_never_create_a_second_charge(): void
    {
        $first = $this->subscribeTo('pro')->assertOk()->json('confirmation_url');
        $second = $this->subscribeTo('pro')->assertOk()->assertJsonPath('reused', true)->json('confirmation_url');
        $third = $this->subscribeTo('pro')->assertOk()->assertJsonPath('reused', true)->json('confirmation_url');

        $this->assertSame($first, $second);
        $this->assertSame($first, $third);
        $this->assertSame(1, $this->createCalls());
        $this->assertSame(1, Subscription::where('status', 'pending')->count());
    }

    public function test_scenario_d_a_concurrent_billing_operation_is_refused_not_duplicated(): void
    {
        $lock = Cache::lock("billing:shop:{$this->shop->id}", 60);
        $this->assertTrue($lock->get());

        $this->subscribeTo('pro')->assertStatus(409)->assertJsonPath('reason', 'in_progress');
        $this->assertSame(0, $this->createCalls());

        $lock->release();
    }

    public function test_subscribing_to_the_plan_already_active_is_refused(): void
    {
        $this->onBasic();

        $this->subscribeTo('basic')->assertStatus(422)->assertJsonPath('reason', 'already_subscribed');
        $this->assertSame(0, $this->createCalls());
    }

    // --- Scenario E: checkout abandoned ---------------------------------------------

    public function test_scenario_e_an_abandoned_checkout_expires_and_the_old_plan_is_untouched(): void
    {
        $this->onBasic();
        $this->subscribeTo('pro')->assertOk();
        $gid = Subscription::where('plan_id', $this->pro->id)->value('shopify_charge_id');

        $this->billing()->assertJsonPath('subscription.pending_change.status', 'awaiting_approval')
            ->assertJsonPath('subscription.actions.can_continue_checkout', true);

        $this->travel(3)->days();
        $this->setShopifyStatus($gid, 'EXPIRED');

        $this->billing()
            ->assertJsonPath('subscription.state', 'active')
            ->assertJsonPath('subscription.plan.code', 'basic')
            ->assertJsonPath('subscription.pending_change', null);

        $this->assertSame('expired', Subscription::where('shopify_charge_id', $gid)->value('status'));
        $this->assertContains('checkout_expired', $this->events());
    }

    // --- Scenario F: declined ---------------------------------------------------------

    public function test_scenario_f_a_declined_subscription_grants_nothing_and_says_so(): void
    {
        $this->onBasic();
        $this->subscribeTo('pro')->assertOk();
        $gid = Subscription::where('plan_id', $this->pro->id)->value('shopify_charge_id');

        $this->setShopifyStatus($gid, 'DECLINED');
        $this->subscriptionWebhook($gid, 'DECLINED')->assertOk();

        $this->assertSame('declined', Subscription::where('shopify_charge_id', $gid)->value('status'));
        $this->assertSame('basic', $this->entitled());
        $this->billing()->assertJsonPath('history.0.event', 'checkout_declined');
    }

    // --- Scenarios G & H: webhook vs callback ordering ----------------------------

    public function test_scenario_g_webhook_before_callback(): void
    {
        $this->onBasic();
        $this->subscribeTo('pro')->assertOk();
        $gid = Subscription::where('plan_id', $this->pro->id)->value('shopify_charge_id');
        $this->approveOnShopify($gid);

        // Shopify's webhooks for the change, old subscription's first.
        $this->subscriptionWebhook(self::BASIC, 'CANCELLED')->assertOk();
        $this->subscriptionWebhook($gid, 'ACTIVE')->assertOk();
        $this->assertSame('pro', $this->entitled());

        $this->billingCallback('pro', $gid)->assertRedirectContains('billing=success');

        $this->assertSame('pro', $this->entitled());
        $this->assertSame(1, BillingEvent::where('event', 'plan_changed')->count());
        $this->assertSame(0, BillingEvent::where('event', 'subscription_cancelled')->count(), 'a replaced plan is not reported as a cancellation');
    }

    public function test_scenario_h_callback_before_webhook(): void
    {
        $this->onBasic();
        $this->subscribeTo('pro')->assertOk();
        $gid = Subscription::where('plan_id', $this->pro->id)->value('shopify_charge_id');
        $this->approveOnShopify($gid);

        $this->billingCallback('pro', $gid)->assertRedirectContains('billing=success');
        $this->subscriptionWebhook(self::BASIC, 'CANCELLED')->assertOk();
        $this->subscriptionWebhook($gid, 'ACTIVE')->assertOk();

        $this->assertSame('pro', $this->entitled());
        $this->assertSame(1, BillingEvent::where('event', 'plan_changed')->count());
        $this->assertSame(1, Subscription::where('status', 'active')->count());
    }

    // --- Scenario I: duplicate / out-of-order webhooks ----------------------------

    public function test_scenario_i_a_duplicate_delivery_is_processed_once(): void
    {
        $this->onBasic();
        $this->setShopifyStatus(self::BASIC, 'FROZEN');

        $this->subscriptionWebhook(self::BASIC, 'FROZEN', deliveryId: 'delivery-1')->assertOk()->assertJsonMissingPath('duplicate');
        $this->subscriptionWebhook(self::BASIC, 'FROZEN', deliveryId: 'delivery-1')->assertOk()->assertJsonPath('duplicate', true);

        $this->assertSame(1, BillingEvent::where('event', 'subscription_frozen')->count());
    }

    public function test_an_older_webhook_never_overwrites_a_newer_state(): void
    {
        $this->onBasic();
        $this->shop->forceFill(['access_token' => null])->save(); // webhook payload is all we have

        $this->subscriptionWebhook(self::BASIC, 'ACTIVE', now())->assertOk();
        $this->subscriptionWebhook(self::BASIC, 'FROZEN', now()->subMinutes(5))->assertOk(); // delayed, older

        $this->assertSame('active', Subscription::sole()->status);
    }

    // --- Scenario J: new plan while the old one is active -------------------------

    public function test_scenario_j_the_old_plan_keeps_running_until_the_new_one_is_approved(): void
    {
        $this->onBasic();
        $this->subscribeTo('pro')->assertOk();

        $this->assertSame('basic', $this->entitled());
        $this->assertSame(1, Subscription::where('status', 'active')->count());

        $this->billing()
            ->assertJsonPath('subscription.state', 'active')
            ->assertJsonPath('subscription.plan.code', 'basic')
            ->assertJsonPath('subscription.pending_change.plan.code', 'pro');
    }

    // --- Scenario K: cancellation --------------------------------------------------

    public function test_scenario_k_cancelling_cancels_on_shopify_and_keeps_the_paid_period(): void
    {
        $this->onBasic(daysLeft: 9);

        $preview = $this->billing()->json('subscription.cancellation');
        $this->assertFalse($preview['prorate']);
        $this->assertNotNull($preview['access_until']);

        $this->app()->postJson('/api/billing/cancel')
            ->assertOk()
            ->assertJsonPath('subscription.state', 'cancelled')
            ->assertJsonPath('subscription.cancellation_reason', 'merchant')
            ->assertJsonPath('subscription.renews_at', null)
            ->assertJsonPath('subscription.access_until', $preview['access_until'])
            ->assertJsonPath('current_plan.code', 'basic');

        $calls = $this->cancelCalls();
        $this->assertCount(1, $calls);
        $this->assertSame(self::BASIC, $calls[0]['variables']['id']);
        $this->assertFalse($calls[0]['variables']['prorate']);
        $this->assertSame('CANCELLED', $this->shopifySubs[self::BASIC]['status']);
        $this->assertSame(['cancellation_requested', 'subscription_cancelled'], $this->events());
    }

    public function test_scenario_k_a_prorated_cancellation_ends_the_plan_at_once(): void
    {
        config(['services.shopify.billing_prorate_cancellations' => true]);
        $this->onBasic();

        $this->billing()->assertJsonPath('subscription.cancellation.access_until', null);

        $this->app()->postJson('/api/billing/cancel')
            ->assertOk()
            ->assertJsonPath('subscription.state', 'ended')
            ->assertJsonPath('current_plan.code', 'starter');

        $this->assertTrue($this->cancelCalls()[0]['variables']['prorate']);
    }

    public function test_a_cancel_shopify_cannot_confirm_is_refused_and_changes_nothing(): void
    {
        $this->onBasic();
        $this->shopifyBehaviour['cancel_fails'] = true;

        $response = $this->app()->postJson('/api/billing/cancel')->assertStatus(502);
        $this->assertStringNotContainsString('Internal', $response->json('error'));

        $this->assertSame('active', Subscription::sole()->status);
        $this->assertSame('basic', $this->entitled());
        $this->assertContains('billing_operation_failed', $this->events());
    }

    public function test_switching_to_the_free_plan_is_a_real_cancellation(): void
    {
        $this->onBasic();

        $this->subscribeTo('starter')->assertOk()->assertJsonPath('result', 'cancelled')->assertJsonPath('confirmation_url', null);

        $this->assertCount(1, $this->cancelCalls());
        $this->assertSame('cancelled', Subscription::sole()->status);
        $this->assertSame(0, Subscription::where('plan_id', $this->starter->id)->count(), 'free is the fallback, not a row');
    }

    // --- Scenario L: "resume" -------------------------------------------------------

    public function test_scenario_l_a_cancelled_plan_is_resumed_by_approving_a_new_subscription(): void
    {
        $this->onBasic();
        $this->app()->postJson('/api/billing/cancel')->assertOk();

        // The Billing API has no un-cancel: CANCELLED is terminal.
        $this->billing()->assertJsonPath('subscription.actions.can_resubscribe', true)
            ->assertJsonPath('subscription.actions.can_cancel', false);

        $this->subscribeTo('basic')->assertOk()->assertJsonPath('result', 'confirmation_required');
        $gid = Subscription::where('status', 'pending')->value('shopify_charge_id');
        $this->approveOnShopify($gid);
        $this->billingCallback('basic', $gid)->assertRedirectContains('billing=success');

        $this->billing()->assertJsonPath('subscription.state', 'active')->assertJsonPath('subscription.plan.code', 'basic');
        $this->assertSame(1, Subscription::where('status', 'active')->count());
    }

    // --- Scenario M: expiry, freeze, renewal -----------------------------------------

    public function test_scenario_m_paid_through_access_ends_on_the_paid_date(): void
    {
        $this->onBasic(daysLeft: 3);
        $this->app()->postJson('/api/billing/cancel')->assertOk();
        $this->assertSame('basic', $this->entitled());

        $this->travel(4)->days();

        $this->assertSame('starter', $this->entitled());
        $this->billing()->assertJsonPath('subscription.state', 'ended')->assertJsonPath('subscription.plan.code', 'basic');
    }

    public function test_a_frozen_subscription_pauses_paid_features_until_payment_resumes(): void
    {
        $this->onBasic();

        $this->setShopifyStatus(self::BASIC, 'FROZEN');
        $this->subscriptionWebhook(self::BASIC, 'FROZEN')->assertOk();

        $this->assertSame('starter', $this->entitled());
        $this->billing()->assertJsonPath('subscription.state', 'frozen')->assertJsonPath('subscription.entitled_plan.code', 'starter');

        $this->approveOnShopify(self::BASIC);
        $this->subscriptionWebhook(self::BASIC, 'ACTIVE', now()->addMinute())->assertOk();

        $this->assertSame('basic', $this->entitled());
        $this->assertContains('subscription_unfrozen', $this->events());
    }

    public function test_a_renewal_advances_the_period_and_is_recorded(): void
    {
        $this->onBasic(daysLeft: 1);

        $this->travel(2)->days();
        $this->shopifySubs[self::BASIC]['currentPeriodEnd'] = now()->addDays(29)->toIso8601String();

        $this->billing()->assertJsonPath('subscription.state', 'active');

        $this->assertTrue(Subscription::sole()->current_period_end->isAfter(now()->addDays(28)));
        $this->assertContains('subscription_renewed', $this->events());
    }

    public function test_an_unconfirmable_renewal_does_not_grant_paid_features_forever(): void
    {
        $this->onBasic(daysLeft: 1);
        $this->shopifyBehaviour['unreachable'] = true;

        $this->travel(9)->days();

        $this->assertSame('starter', $this->entitled());
        $this->billing()->assertJsonPath('subscription.state', 'unknown')
            ->assertJsonPath('subscription.sync.failing', true);

        $this->shopifyBehaviour['unreachable'] = false;
        $this->shopifySubs[self::BASIC]['currentPeriodEnd'] = now()->addDays(21)->toIso8601String();
        Cache::flush();

        $this->billing()->assertJsonPath('subscription.state', 'active');
        $this->assertSame('basic', $this->entitled());
    }

    // --- Scenario N: Shopify and the database disagree --------------------------------

    public function test_scenario_n_a_stale_local_active_subscription_is_corrected_from_shopify(): void
    {
        $this->onBasic(daysLeft: 6);
        $this->setShopifyStatus(self::BASIC, 'CANCELLED'); // cancelled while we weren't told

        $this->billing()
            ->assertJsonPath('subscription.state', 'cancelled')
            ->assertJsonPath('subscription.cancellation_reason', 'shopify')
            ->assertJsonPath('subscription_status', null);
    }

    public function test_scenario_n_a_live_shopify_subscription_missing_locally_is_adopted(): void
    {
        $this->shopifySub('gid://shopify/AppSubscription/7777', 'Pro', 'pro', 160, 'ACTIVE', now()->addDays(15));

        $this->billing()->assertJsonPath('subscription.state', 'active')->assertJsonPath('current_plan.code', 'pro');

        $row = Subscription::sole();
        $this->assertSame('gid://shopify/AppSubscription/7777', $row->shopify_charge_id);
        $this->assertSame('160.00', $row->price);
    }

    public function test_reconciling_a_replacement_records_each_ending_once(): void
    {
        // The replacement's row is OLDER than the row it replaces (checkout opened
        // before the old plan was recorded), so it is reconciled first.
        $newer = 'gid://shopify/AppSubscription/2002';
        Subscription::create(['shop_id' => $this->shop->id, 'plan_id' => $this->pro->id, 'status' => 'pending', 'shopify_charge_id' => $newer, 'created_at' => now()->subHour()]);
        $this->onBasic();

        $this->shopifySub($newer, 'Pro', 'pro', 160, 'PENDING');
        $this->approveOnShopify($newer);

        $this->billing()->assertJsonPath('subscription.plan.code', 'pro');
        $this->billing()->assertJsonPath('subscription.plan.code', 'pro'); // idempotent

        $this->assertSame(1, BillingEvent::where('event', 'previous_plan_ended')->count());
        $this->assertSame(1, BillingEvent::where('event', 'plan_changed')->count());
        $this->assertSame(1, Subscription::where('status', 'active')->count());
    }

    public function test_a_local_subscription_with_no_shopify_charge_ends_when_shopify_has_none(): void
    {
        Subscription::create(['shop_id' => $this->shop->id, 'plan_id' => $this->basic->id, 'status' => 'active', 'started_at' => now()->subMonth()]);

        $this->billing()->assertJsonPath('subscription.state', 'ended')->assertJsonPath('current_plan.code', 'starter');
    }

    public function test_a_failed_lookup_never_downgrades_a_paying_merchant(): void
    {
        $this->onBasic();
        $this->shopifyBehaviour['unreachable'] = true;

        $this->billing()->assertOk()->assertJsonPath('subscription.state', 'active')->assertJsonPath('current_plan.code', 'basic');
        $this->assertSame('billing_sync_failed', BillingEvent::latest('id')->value('event'));
    }

    public function test_a_revoked_token_is_replaced_so_billing_is_not_stale(): void
    {
        $this->onBasic();
        $this->setShopifyStatus(self::BASIC, 'CANCELLED');
        $this->shopifyBehaviour['revoked_token'] = 'shpat_x';

        $this->billing()->assertOk()->assertJsonPath('subscription.state', 'cancelled');
        $this->assertSame('shpat_reinstalled', $this->shop->fresh()->access_token);
    }

    // --- Uninstall / reinstall ------------------------------------------------------

    public function test_uninstall_cancels_and_reinstall_shows_the_paid_period_the_same_in_app_and_admin(): void
    {
        $this->onBasic(daysLeft: 20);

        $this->signedWebhook('app-uninstalled', ['id' => 1])->assertOk();

        $sub = Subscription::sole();
        $this->assertSame('cancelled', $sub->status);
        $this->assertSame('uninstalled', $sub->cancellation_reason);
        $this->assertTrue($sub->ends_at->isAfter(now()->addDays(19)), 'Shopify: merchants may use the rest of the paid period');

        // Reinstall: first embedded request exchanges the session token.
        $this->shopifySubs = [];
        $this->billing(refresh: false)
            ->assertOk()
            ->assertJsonPath('subscription.state', 'cancelled')
            ->assertJsonPath('subscription.cancellation_reason', 'uninstalled')
            ->assertJsonPath('subscription.renews_at', null)
            ->assertJsonPath('subscription.actions.can_resubscribe', true)
            ->assertJsonPath('current_plan.code', 'basic');

        $this->assertNull($this->shop->fresh()->uninstalled_at);
        $this->assertContains('app_reinstalled', $this->events());

        $admin = $this->withHeaders($this->adminHeaders())->getJson("/api/admin/stores/{$this->shop->id}")
            ->assertOk()
            ->assertJsonPath('store.plan', 'Basic')
            ->assertJsonPath('billing.state', 'cancelled')
            ->assertJsonPath('billing.cancellation_reason', 'uninstalled');

        $this->assertSame($admin->json('billing.access_until'), $this->billing(refresh: false)->json('subscription.access_until'));
    }

    // --- Validation & security ------------------------------------------------------

    public function test_an_unknown_or_inactive_plan_is_refused(): void
    {
        $this->subscribeTo('does-not-exist')->assertStatus(422)->assertJsonPath('reason', 'invalid_plan');

        $this->pro->update(['active' => false]);
        $this->subscribeTo('pro')->assertStatus(422)->assertJsonPath('reason', 'invalid_plan');

        $this->assertSame(0, $this->createCalls());
    }

    public function test_the_price_always_comes_from_the_server(): void
    {
        $this->app()->postJson('/api/billing/subscribe', ['plan' => 'pro', 'price' => 1, 'amount' => 1])->assertOk();

        $create = collect($this->graphqlCalls)->first(fn ($c) => str_contains($c['query'], 'appSubscriptionCreate'));
        $this->assertSame(160, (int) $create['variables']['lineItems'][0]['plan']['appRecurringPricingDetails']['price']['amount']);
    }

    public function test_a_callback_for_another_shops_charge_grants_nothing(): void
    {
        $other = Shop::create(['shopify_domain' => 'other.myshopify.com', 'access_token' => 'shpat_o', 'token_expires_at' => now()->addHour()]);
        Subscription::create(['shop_id' => $other->id, 'plan_id' => $this->pro->id, 'status' => 'pending', 'shopify_charge_id' => 'gid://shopify/AppSubscription/4444']);

        $this->billingCallback('pro', 'gid://shopify/AppSubscription/4444')->assertRedirectContains('billing=processing');

        $this->assertSame('starter', $this->entitled());
        $this->assertNull(Subscription::where('shopify_charge_id', 'gid://shopify/AppSubscription/4444')->value('approved_at'));
    }

    public function test_an_unsigned_callback_only_triggers_a_reconcile(): void
    {
        $this->subscribeTo('pro')->assertOk();
        $row = Subscription::sole();

        $this->billingCallback('pro', $row->shopify_charge_id, signed: false)->assertRedirectContains('billing=processing');

        $this->assertNull($row->fresh()->approved_at);
        $this->assertSame('pending', $row->fresh()->status);
    }

    public function test_billing_requires_a_session_outside_local_development(): void
    {
        $this->app['env'] = 'production';

        $this->getJson('/api/billing')->assertStatus(401);
        $this->withHeader('X-Shop-Domain', self::DOMAIN)->getJson('/api/billing')->assertStatus(401);
        $this->postJson('/api/billing/cancel')->assertStatus(401);
    }

    public function test_tokens_and_confirmation_links_never_leak_into_history_or_admin_views(): void
    {
        $this->onBasic();
        $this->subscribeTo('pro')->assertOk();

        $admin = $this->withHeaders($this->adminHeaders())->getJson("/api/admin/stores/{$this->shop->id}")->assertOk()->getContent();

        $this->assertStringNotContainsString('shpat_', $admin);
        $this->assertStringNotContainsString('charges/confirm', $admin);
        $this->assertStringNotContainsString('shpat_', json_encode(BillingEvent::all()->toArray()));
    }

    public function test_the_database_refuses_a_second_active_subscription(): void
    {
        $this->onBasic();

        $this->expectException(\Illuminate\Database\QueryException::class);

        Subscription::create(['shop_id' => $this->shop->id, 'plan_id' => $this->pro->id, 'status' => 'active']);
    }
}
