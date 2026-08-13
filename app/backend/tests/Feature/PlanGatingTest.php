<?php

namespace Tests\Feature;

use App\Models\MigrationItem;
use App\Models\Plan;
use App\Models\Shop;
use App\Models\StoreMigration;
use App\Models\Subscription;
use App\Models\WoocommerceConnection;
use App\Support\PlanAllowance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The wizard used to offer every entity regardless of plan: a free merchant could
 * tick 11,744 products, start a run, and only afterwards find a buried log line
 * saying 10 were migrated. The preview now carries the plan verdict so the UI can
 * disable what can't be migrated, and the API refuses it outright.
 */
class PlanGatingTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        Plan::create(['code' => 'starter', 'name' => 'Starter', 'price' => 0, 'interval' => 'monthly', 'active' => true,
            'limits' => ['products' => 10, 'customers' => 10, 'orders' => 0, 'metafields' => false]]);
        Plan::create(['code' => 'pro', 'name' => 'Pro', 'price' => 49, 'interval' => 'monthly', 'active' => true,
            'limits' => ['products' => null, 'customers' => null, 'orders' => null, 'metafields' => true]]);

        $this->shop = Shop::create([
            'shopify_domain' => 'gating.myshopify.com',
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

        Http::fake([
            '*/wp-json/wc/v3/products/categories*' => Http::response([], 200, ['X-WP-Total' => '112']),
            '*/wp-json/wc/v3/products*' => Http::response([], 200, ['X-WP-Total' => '11744']),
            '*/wp-json/wc/v3/customers*' => Http::response([], 200, ['X-WP-Total' => '3652']),
            '*/wp-json/wc/v3/orders*' => Http::response([], 200, ['X-WP-Total' => '2612']),
            '*' => Http::response([], 200, ['X-WP-Total' => '0']),
        ]);
    }

    private function preview(): array
    {
        return $this->withHeader('X-Shop-Domain', $this->shop->shopify_domain)
            ->getJson('/api/woocommerce/preview')
            ->assertOk()
            ->json();
    }

    private function entity(array $preview, string $type): array
    {
        return collect($preview['counts'])->firstWhere('type', $type);
    }

    /** Pretend a past run already migrated $count products for this shop. */
    private function alreadyMigratedProducts(int $count): void
    {
        $migration = StoreMigration::create([
            'shop_id' => $this->shop->id,
            'woocommerce_connection_id' => $this->shop->woocommerceConnection->id,
            'status' => 'completed',
            'selected_entities' => ['product'],
        ]);

        foreach (range(1, $count) as $i) {
            MigrationItem::create([
                'migration_id' => $migration->id,
                'shop_id' => $this->shop->id,
                'entity_type' => 'product',
                'woocommerce_id' => $i,
                'shopify_id' => "gid://shopify/Product/{$i}",
                'status' => 'success',
            ]);
        }
    }

    public function test_preview_reports_the_plan_verdict_per_entity(): void
    {
        $preview = $this->preview();

        $this->assertSame('Starter', $preview['plan']['name']);

        // Capped but usable.
        $product = $this->entity($preview, 'product');
        $this->assertSame(11744, $product['count']);
        $this->assertSame(10, $product['limit']);
        $this->assertSame(10, $product['migratable'], 'the preview must promise only what the run will do');
        $this->assertFalse($product['locked']);
        $this->assertFalse($product['exhausted']);

        // Not in the plan at all.
        $order = $this->entity($preview, 'order');
        $this->assertTrue($order['locked']);
        $this->assertSame(0, $order['migratable']);
        $this->assertStringContainsString('Starter', $order['reason']);

        // Unmetered entity stays unlimited.
        $category = $this->entity($preview, 'category');
        $this->assertNull($category['limit']);
        $this->assertSame(112, $category['migratable']);
    }

    public function test_a_spent_allowance_is_reported_as_exhausted(): void
    {
        // The merchant has already migrated their 10 free products.
        $this->alreadyMigratedProducts(10);

        $product = $this->entity($this->preview(), 'product');

        $this->assertSame(10, $product['used']);
        $this->assertSame(0, $product['remaining']);
        $this->assertTrue($product['exhausted'], 'more products exist than the plan will ever allow');
    }

    public function test_a_fully_migrated_store_within_the_cap_is_not_exhausted(): void
    {
        // Only 5 products exist in Woo and all 5 are migrated — re-running is a
        // legitimate refresh, so the option must stay available.
        Http::fake([
            '*/wp-json/wc/v3/products*' => Http::response([], 200, ['X-WP-Total' => '5']),
            '*' => Http::response([], 200, ['X-WP-Total' => '0']),
        ]);

        $this->alreadyMigratedProducts(5);

        $allowance = PlanAllowance::forEntity($this->shop->fresh(), 'product', 5);

        $this->assertFalse($allowance['exhausted']);
        $this->assertSame(5, $allowance['migratable']);
    }

    public function test_upgrading_unlocks_everything(): void
    {
        Subscription::create([
            'shop_id' => $this->shop->id,
            'plan_id' => Plan::where('code', 'pro')->value('id'),
            'status' => 'active',
            'started_at' => now(),
        ]);

        $preview = $this->preview();

        foreach (['product', 'customer', 'order'] as $type) {
            $entity = $this->entity($preview, $type);
            $this->assertFalse($entity['locked'], "{$type} must be unlocked on Pro");
            $this->assertNull($entity['limit']);
            $this->assertNull($entity['reason']);
        }
    }

    public function test_the_api_refuses_an_entity_the_plan_does_not_include(): void
    {
        Queue::fake();

        $this->withHeader('X-Shop-Domain', $this->shop->shopify_domain)
            ->postJson('/api/migrations', ['entities' => ['order']])
            ->assertStatus(422);

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('store_migrations', 0);
    }

    public function test_a_capped_entity_can_still_be_migrated(): void
    {
        Queue::fake();

        // Products are capped at 10, not locked — the merchant may still run it.
        $this->withHeader('X-Shop-Domain', $this->shop->shopify_domain)
            ->postJson('/api/migrations', ['entities' => ['product', 'category']])
            ->assertCreated();
    }
}
