<?php

namespace Tests\Feature;

use App\Jobs\StartMigrationJob;
use App\Migration\MigratorRegistry;
use App\Models\MigrationEntity;
use App\Models\MigrationItem;
use App\Models\MigrationLog;
use App\Models\Plan;
use App\Models\Shop;
use App\Models\StoreMigration;
use App\Models\Subscription;
use App\Models\WoocommerceConnection;
use App\Support\PlanAllowance;
use App\Support\PlanPool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The Pro plan sells ONE budget of records, spent however the merchant likes
 * across products, customers and orders — not 20,000 of each. Three separate
 * caps made the headline number meaningless: a store with 20,000 products and a
 * store with 60,000 records across all three bought exactly the same plan.
 *
 * The arithmetic has to hold in two places that are easy to let drift apart: the
 * wizard's preview, and what StartMigrationJob actually plans.
 */
class PlanPoolTest extends TestCase
{
    use RefreshDatabase;

    private function shopOnPool(int $total = 20000): Shop
    {
        $shop = Shop::create([
            'shopify_domain' => 'pool.myshopify.com',
            'access_token' => 'shpat_x',
            'token_expires_at' => now()->addHour(),
        ]);

        $plan = Plan::create([
            'code' => 'pro',
            'name' => 'Pro',
            'price' => 49,
            'interval' => 'monthly',
            'active' => true,
            'limits' => ['total' => $total, 'metafields' => true],
        ]);

        Subscription::create([
            'shop_id' => $shop->id,
            'plan_id' => $plan->id,
            'status' => 'active',
        ]);

        WoocommerceConnection::create([
            'shop_id' => $shop->id,
            'store_url' => 'https://woo.example.com',
            'consumer_key' => 'ck',
            'consumer_secret' => 'cs',
            'status' => 'connected',
        ]);

        return $shop->refresh();
    }

    private function migrated(Shop $shop, string $entityType, int $count, int $from = 1): void
    {
        $migration = StoreMigration::create([
            'shop_id' => $shop->id,
            'woocommerce_connection_id' => $shop->woocommerceConnection->id,
            'status' => 'completed',
            'selected_entities' => [$entityType],
        ]);

        for ($i = $from; $i < $from + $count; $i++) {
            MigrationItem::create([
                'migration_id' => $migration->id,
                'shop_id' => $shop->id,
                'entity_type' => $entityType,
                'woocommerce_id' => $i,
                'shopify_id' => "gid://shopify/X/{$i}",
                'status' => 'success',
            ]);
        }
    }

    // ------------------------------------------------------------- arithmetic

    /** @test */
    public function the_budget_is_shared_across_entities_not_repeated_per_entity(): void
    {
        $shop = $this->shopOnPool(20000);
        $pool = PlanPool::for($shop);

        // 15,000 products leaves 5,000 for everything else — under the old
        // per-entity caps customers would have had a fresh 20,000 of their own.
        $this->assertSame(15000, $pool->claim('product', 15000));
        $this->assertSame(5000, $pool->claim('customer', 9000));
        $this->assertSame(0, $pool->claim('order', 4000));
    }

    /** @test */
    public function categories_do_not_spend_the_budget(): void
    {
        $shop = $this->shopOnPool(100);
        $pool = PlanPool::for($shop);

        // Charging a merchant's record budget for the collections their products
        // need would be a surprise, and no plan has ever metered them.
        $this->assertSame(4000, $pool->claim('category', 4000));
        $this->assertSame(100, $pool->claim('product', 100));
    }

    /** @test */
    public function re_migrating_records_already_counted_costs_nothing(): void
    {
        $shop = $this->shopOnPool(20000);

        // The shop has already migrated 15,000 products under this pool.
        $this->migrated($shop, 'product', 15000);

        $pool = PlanPool::for($shop);

        $this->assertSame(15000, $pool->used());
        $this->assertSame(5000, $pool->remaining());

        // Counting spent budget rather than DISTINCT records would let this shop
        // re-run its catalogue exactly once and then refuse to touch it again.
        $this->assertSame(15000, $pool->claim('product', 15000));

        // …and it can still grow into what is left.
        $pool2 = PlanPool::for($shop);
        $this->assertSame(20000, $pool2->claim('product', 40000));
    }

    /** @test */
    public function a_plan_without_a_total_keeps_the_old_per_entity_behaviour(): void
    {
        $shop = Shop::create([
            'shopify_domain' => 'perentity.myshopify.com',
            'access_token' => 'shpat_x',
            'token_expires_at' => now()->addHour(),
        ]);

        Plan::create([
            'code' => 'starter',
            'name' => 'Starter',
            'price' => 0,
            'interval' => 'monthly',
            'active' => true,
            'limits' => ['products' => 10, 'customers' => 10, 'orders' => 0],
        ]);

        $pool = PlanPool::for($shop->refresh());

        $this->assertFalse($pool->isActive());
        $this->assertSame(99999, $pool->claim('product', 99999), 'an inactive pool caps nothing');
    }

    // ----------------------------------------------------------- preview + run

    /** @test */
    public function the_preview_reports_the_shared_budget_and_what_is_left(): void
    {
        $shop = $this->shopOnPool(20000);
        $this->migrated($shop, 'customer', 3000);

        Http::fake(['*' => Http::response([], 200, ['X-WP-Total' => '500'])]);

        $response = $this->withHeaders(['X-Shop-Domain' => $shop->shopify_domain])
            ->getJson('/api/woocommerce/preview');

        $response->assertOk();
        $response->assertJsonPath('pool.limit', 20000);
        $response->assertJsonPath('pool.used', 3000);
        $response->assertJsonPath('pool.remaining', 17000);
    }

    /** @test */
    public function the_run_caps_entities_against_the_shared_budget_in_dependency_order(): void
    {
        Queue::fake();

        $shop = $this->shopOnPool(1000);

        $migration = StoreMigration::create([
            'shop_id' => $shop->id,
            'woocommerce_connection_id' => $shop->woocommerceConnection->id,
            'status' => 'pending',
            'selected_entities' => ['product', 'customer'],
        ]);

        // 800 of each available, against a 1,000 shared budget.
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'graphql.json')) {
                return Http::response(['data' => ['shop' => ['plan' => ['displayName' => 'Basic']]]], 200);
            }

            return Http::response([], 200, ['X-WP-Total' => '800']);
        });

        (new StartMigrationJob($migration->id))->handle(app(MigratorRegistry::class));

        $totals = MigrationEntity::where('migration_id', $migration->id)
            ->pluck('total_items', 'entity_type');

        // Allocation follows the registry's dependency order. Products carry no
        // dependency here (categories were not selected), so they are visited
        // first and take what they need; customers get what is left. The rule
        // that matters is that the two together never exceed the budget.
        $this->assertSame(800, (int) $totals['product']);
        $this->assertSame(200, (int) $totals['customer']);
        $this->assertSame(1000, (int) $totals['customer'] + (int) $totals['product']);

        $this->assertTrue(
            MigrationLog::where('migration_id', $migration->id)
                ->where('message', 'like', '%records in total across products, customers and orders%')
                ->exists(),
            'the merchant is told why the second entity was cut short',
        );
    }

    /** @test */
    public function an_exhausted_budget_reads_as_exhausted_not_merely_capped(): void
    {
        $shop = $this->shopOnPool(1000);
        $this->migrated($shop, 'product', 1000);

        $pool = PlanPool::for($shop);
        $verdict = PlanAllowance::forEntity($shop, 'customer', 500, $pool);

        $this->assertTrue($verdict['pooled']);
        $this->assertTrue($verdict['exhausted']);
        $this->assertSame(0, $verdict['migratable']);
        $this->assertStringContainsString('1,000 records', $verdict['reason']);
    }

    // ----------------------------------------------------------- admin panel

    /** @test */
    public function editing_a_pooled_plan_in_the_admin_panel_keeps_its_pool(): void
    {
        $admin = \App\Models\AdminUser::create([
            'email' => 'admin@example.com',
            'name' => 'Admin',
            'password' => 'secret123',
            'is_super' => true,
        ]);

        $token = 'tok_'.str_repeat('a', 60);
        $admin->forceFill(['api_token_hash' => hash('sha256', $token)])->save();

        $plan = Plan::create([
            'code' => 'pro',
            'name' => 'Pro',
            'price' => 49,
            'interval' => 'monthly',
            'active' => true,
            'limits' => ['total' => 20000, 'metafields' => true, 'contact_only' => false],
        ]);

        // The admin changes nothing but the price. The limits payload is merged
        // against a list of defaults, and `total` was missing from that list — so
        // simply opening this plan and saving turned a 20,000-record pooled plan
        // into an unlimited one, with nothing anywhere to show it had happened.
        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->patchJson("/api/admin/plans/{$plan->id}", [
                'code' => 'pro',
                'name' => 'Pro',
                'price' => 59,
                'limits' => ['total' => 20000, 'metafields' => true],
            ]);

        $response->assertOk();

        $this->assertSame(20000, $plan->fresh()->limits['total']);
        $this->assertSame(20000, $plan->fresh()->poolLimit());
    }

    /** @test */
    public function the_admin_panel_can_turn_pooling_on_and_off(): void
    {
        $admin = \App\Models\AdminUser::create([
            'email' => 'admin2@example.com',
            'name' => 'Admin',
            'password' => 'secret123',
            'is_super' => true,
        ]);

        $token = 'tok_'.str_repeat('b', 60);
        $admin->forceFill(['api_token_hash' => hash('sha256', $token)])->save();

        $plan = Plan::create([
            'code' => 'basic',
            'name' => 'Basic',
            'price' => 19,
            'interval' => 'monthly',
            'active' => true,
            'limits' => ['products' => 2000, 'customers' => 2000, 'orders' => 1000, 'metafields' => true],
        ]);

        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->patchJson("/api/admin/plans/{$plan->id}", [
                'code' => 'basic',
                'name' => 'Basic',
                'price' => 19,
                'limits' => ['total' => 5000, 'metafields' => true],
            ])->assertOk();

        $this->assertSame(5000, $plan->fresh()->poolLimit());

        // …and back off again.
        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->patchJson("/api/admin/plans/{$plan->id}", [
                'code' => 'basic',
                'name' => 'Basic',
                'price' => 19,
                'limits' => ['products' => 2000, 'metafields' => true],
            ])->assertOk();

        $this->assertNull($plan->fresh()->poolLimit());
    }

    // ------------------------------------------------------------- enterprise

    /** @test */
    public function a_contact_only_plan_cannot_be_bought_through_shopify_billing(): void
    {
        $shop = $this->shopOnPool();

        Plan::create([
            'code' => 'enterprise',
            'name' => 'Enterprise',
            'price' => 149,
            'interval' => 'monthly',
            'active' => true,
            'limits' => ['products' => null, 'contact_only' => true],
        ]);

        // Sending the merchant to a Shopify charge screen would bill them for a
        // subscription that is not what they think they are buying.
        $response = $this->withHeaders(['X-Shop-Domain' => $shop->shopify_domain])
            ->postJson('/api/billing/subscribe', ['plan' => 'enterprise']);

        $response->assertStatus(422);
        $response->assertJsonPath('contact_only', true);
    }
}
