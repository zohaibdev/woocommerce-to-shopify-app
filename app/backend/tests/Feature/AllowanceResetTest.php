<?php

namespace Tests\Feature;

use App\Models\MigrationItem;
use App\Models\Plan;
use App\Models\Shop;
use App\Models\Subscription;
use App\Support\PlanAllowance;
use App\Support\PlanPool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A monthly plan's allowance comes back every month.
 *
 * It used to be measured over ALL TIME, which quietly turned a recurring
 * subscription into a one-off purchase: the merchant spent their 2,000 records
 * once and then paid $19 a month to be told they were "exhausted". The period is
 * derived arithmetically from the subscription's start date — see
 * App\Support\BillingPeriod — so no webhook can be missed and no column can
 * drift.
 */
class AllowanceResetTest extends TestCase
{
    use RefreshDatabase;

    private function shopOn(array $limits, string $subscribedAt = '-3 months'): Shop
    {
        $shop = Shop::create([
            'shopify_domain' => 'reset.myshopify.com',
            'access_token' => 'shpat_x',
            'token_expires_at' => now()->addHour(),
        ]);

        $plan = Plan::create([
            'code' => 'monthly-test',
            'name' => 'Monthly Test',
            'price' => 19,
            'interval' => 'monthly',
            'limits' => $limits,
            'active' => true,
        ]);

        Subscription::create([
            'shop_id' => $shop->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'started_at' => now()->parse($subscribedAt),
        ]);

        $shop->forgetPlan();

        return $shop;
    }

    /** Records migrated $when ago. */
    private function migrated(Shop $shop, string $entityType, int $count, string $when): void
    {
        $connection = \App\Models\WoocommerceConnection::create([
            'shop_id' => $shop->id,
            'store_url' => 'https://woo.example.com',
            'consumer_key' => 'ck',
            'consumer_secret' => 'cs',
            'status' => 'connected',
        ]);

        $migration = \App\Models\StoreMigration::create([
            'shop_id' => $shop->id,
            'woocommerce_connection_id' => $connection->id,
            'status' => 'completed',
            'selected_entities' => [$entityType],
            'created_at' => now()->parse($when),
        ]);

        for ($i = 1; $i <= $count; $i++) {
            $item = MigrationItem::create([
                'migration_id' => $migration->id,
                'shop_id' => $shop->id,
                'entity_type' => $entityType,
                'woocommerce_id' => $i,
                'shopify_id' => "gid://shopify/X/{$i}",
                'status' => 'success',
            ]);

            // created_at is NOT fillable, so passing it to create() silently
            // stamped every row with now() — and the first version of this suite
            // "passed" while proving nothing at all. Backdate it explicitly.
            $item->forceFill([
                'created_at' => now()->parse($when),
                'updated_at' => now()->parse($when),
            ])->saveQuietly();
        }
    }

    public function test_a_spend_from_an_earlier_period_does_not_count_against_this_one(): void
    {
        $shop = $this->shopOn(['products' => 10]);

        // Spent in full three months ago. Two invoices have been paid since, and
        // each of them has to buy something.
        $this->migrated($shop, 'product', 10, '-3 months');

        $verdict = PlanAllowance::forEntity($shop, 'product', 500);

        $this->assertSame(0, $verdict['used'], 'an old spend is not this month\'s');
        $this->assertSame(10, $verdict['remaining']);
        $this->assertFalse($verdict['exhausted']);
        $this->assertSame(10, $verdict['migratable']);
    }

    public function test_a_spend_inside_this_period_still_counts(): void
    {
        $shop = $this->shopOn(['products' => 10]);

        // The reset must not become a way to migrate without limit: everything
        // done since the current period began is still spent.
        $this->migrated($shop, 'product', 10, 'now');

        $verdict = PlanAllowance::forEntity($shop, 'product', 500);

        $this->assertSame(10, $verdict['used']);
        $this->assertSame(0, $verdict['remaining']);
        $this->assertTrue($verdict['exhausted']);
        $this->assertStringContainsString('resets on', (string) $verdict['reason']);
    }

    public function test_the_pooled_budget_resets_too(): void
    {
        $shop = $this->shopOn(['total' => 100]);

        $this->migrated($shop, 'product', 100, '-6 months');

        $this->assertSame(100, PlanPool::for($shop)->remaining(), 'the shared budget renews as well');
    }

    public function test_the_pooled_budget_still_counts_this_period(): void
    {
        $shop = $this->shopOn(['total' => 100]);

        $this->migrated($shop, 'product', 100, 'now');

        $this->assertSame(0, PlanPool::for($shop)->remaining());
    }

    public function test_the_period_is_anchored_to_the_merchants_own_billing_date(): void
    {
        // Subscribed on the 20th, so their month runs 20th-to-20th — which is
        // when Shopify actually charges them. Anchoring to the 1st would hand
        // someone who subscribed on the 28th a fresh allowance three days later.
        $shop = $this->shopOn(['products' => 10], '2026-05-20 09:00:00');

        $start = \App\Support\BillingPeriod::currentStart($shop);

        $this->assertSame(20, $start->day);
        $this->assertTrue($start->lessThanOrEqualTo(now()));
        $this->assertTrue(\App\Support\BillingPeriod::nextReset($shop)->greaterThan(now()));
    }

    public function test_a_free_shop_with_no_subscription_also_gets_a_monthly_period(): void
    {
        $shop = Shop::create([
            'shopify_domain' => 'free.myshopify.com',
            'access_token' => 'shpat_x',
            'token_expires_at' => now()->addHour(),
        ]);
        $shop->forceFill(['created_at' => now()->subMonths(4)])->saveQuietly();

        // Without an anchor of its own the Starter plan's 10 products would be a
        // LIFETIME cap while every paid plan reset — a difference no free user
        // would ever be told about.
        $this->assertTrue(
            \App\Support\BillingPeriod::currentStart($shop)->greaterThan(now()->subMonth()->subDay()),
            'a free shop still gets a current month',
        );
    }
}
