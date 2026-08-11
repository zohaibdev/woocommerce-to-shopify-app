<?php

namespace Tests\Feature;

use App\Jobs\StartMigrationJob;
use App\Models\MigrationEntity;
use App\Models\MigrationLog;
use App\Models\Plan;
use App\Models\Shop;
use App\Models\StoreMigration;
use App\Models\Subscription;
use App\Models\WoocommerceConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Proves the plan caps are actually applied when a migration starts:
 * a 10-product plan must migrate 10, not the 250 available — until the
 * merchant changes plan.
 */
class PlanLimitsTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.shopify.api_key' => 'test_key',
            'services.shopify.api_secret' => 'test_secret',
        ]);

        $this->seedPlans();

        $this->shop = Shop::create([
            'shopify_domain' => 'demo.myshopify.com',
            'access_token' => 'shpat_x',
            'token_expires_at' => now()->addHour(),
        ]);

        WoocommerceConnection::create([
            'shop_id' => $this->shop->id,
            'store_url' => 'https://woo.example.com',
            'consumer_key' => 'ck',
            'consumer_secret' => 'cs',
            'status' => 'connected',
        ]);

        // WooCommerce reports 250 products / 80 customers available.
        Http::fake([
            '*/wp-json/wc/v3/products*' => Http::response([], 200, ['X-WP-Total' => '250']),
            '*/wp-json/wc/v3/customers*' => Http::response([], 200, ['X-WP-Total' => '80']),
            '*/wp-json/wc/v3/orders*' => Http::response([], 200, ['X-WP-Total' => '40']),
            '*' => Http::response([], 200, ['X-WP-Total' => '0']),
        ]);
    }

    private function seedPlans(): void
    {
        Plan::create(['code' => 'starter', 'name' => 'Starter', 'price' => 0, 'interval' => 'monthly', 'active' => true,
            'limits' => ['products' => 10, 'customers' => 10, 'orders' => 0, 'metafields' => false]]);
        Plan::create(['code' => 'pro', 'name' => 'Pro', 'price' => 49, 'interval' => 'monthly', 'active' => true,
            'limits' => ['products' => 20000, 'customers' => 20000, 'orders' => 20000, 'metafields' => true]]);
    }

    private function runMigration(array $entities): StoreMigration
    {
        $migration = StoreMigration::create([
            'shop_id' => $this->shop->id,
            'woocommerce_connection_id' => $this->shop->woocommerceConnection->id,
            'status' => 'pending',
            'selected_entities' => $entities,
        ]);

        (new StartMigrationJob($migration->id))->handle(app(\App\Migration\MigratorRegistry::class));

        return $migration->fresh();
    }

    private function subscribe(string $code): void
    {
        Subscription::create([
            'shop_id' => $this->shop->id,
            'plan_id' => Plan::where('code', $code)->value('id'),
            'status' => 'active',
            'started_at' => now(),
        ]);
        $this->shop->refresh();
    }

    public function test_free_shop_defaults_to_starter_plan(): void
    {
        $this->assertSame('Starter', $this->shop->currentPlan()->name);
        $this->assertSame(10, $this->shop->limitFor('product'));
        $this->assertSame(10, $this->shop->limitFor('customer'));
        $this->assertSame(0, $this->shop->limitFor('order'));
    }

    public function test_starter_plan_caps_products_at_ten(): void
    {
        $migration = $this->runMigration(['product']);

        $entity = MigrationEntity::where('migration_id', $migration->id)
            ->where('entity_type', 'product')->first();

        $this->assertSame(10, $entity->total_items, '250 available, but the plan allows 10');

        // The merchant must be told why, not silently truncated.
        $this->assertTrue(
            MigrationLog::where('migration_id', $migration->id)
                ->where('level', 'warning')
                ->where('message', 'like', '%Plan limit reached%')
                ->exists(),
        );
    }

    public function test_orders_are_skipped_entirely_on_starter(): void
    {
        $migration = $this->runMigration(['order']);

        $entity = MigrationEntity::where('migration_id', $migration->id)
            ->where('entity_type', 'order')->first();

        $this->assertSame(0, $entity->total_items);
        $this->assertSame('completed', $entity->status);
    }

    public function test_upgrading_the_plan_lifts_the_cap(): void
    {
        // Same store, now on Pro.
        $this->subscribe('pro');

        $this->assertSame('Pro', $this->shop->currentPlan()->name);

        $migration = $this->runMigration(['product', 'customer', 'order']);

        $byType = MigrationEntity::where('migration_id', $migration->id)
            ->get()->keyBy('entity_type');

        $this->assertSame(250, $byType['product']->total_items, 'Pro allows all 250');
        $this->assertSame(80, $byType['customer']->total_items);
        $this->assertSame(40, $byType['order']->total_items, 'orders now included');
    }

    public function test_changing_plan_limits_takes_effect_without_a_deploy(): void
    {
        // Super admin edits the Starter plan in the DB.
        Plan::where('code', 'starter')->update([
            'limits' => ['products' => 25, 'customers' => 10, 'orders' => 0, 'metafields' => false],
        ]);
        $this->shop->refresh();

        $migration = $this->runMigration(['product']);

        $entity = MigrationEntity::where('migration_id', $migration->id)
            ->where('entity_type', 'product')->first();

        $this->assertSame(25, $entity->total_items, 'new limit applies immediately');
    }
}
