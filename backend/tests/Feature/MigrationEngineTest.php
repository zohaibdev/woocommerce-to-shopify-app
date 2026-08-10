<?php

namespace Tests\Feature;

use App\Jobs\StartMigrationJob;
use App\Migration\Migrators\ProductMigrator;
use App\Migration\MigratorRegistry;
use App\Models\MigrationItem;
use App\Models\Shop;
use App\Models\StoreMigration;
use App\Models\WoocommerceConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MigrationEngineTest extends TestCase
{
    use RefreshDatabase;

    private function shopWithConnection(): Shop
    {
        $shop = Shop::create([
            'shopify_domain' => 'test.myshopify.com',
            'access_token' => 'shpat_test',
        ]);
        WoocommerceConnection::create([
            'shop_id' => $shop->id,
            'store_url' => 'https://woo.example.com',
            'consumer_key' => 'ck_test',
            'consumer_secret' => 'cs_test',
            'status' => 'connected',
        ]);

        return $shop;
    }

    public function test_creating_a_migration_enqueues_the_start_job(): void
    {
        Queue::fake();
        $shop = $this->shopWithConnection();

        $res = $this->withHeader('X-Shop-Domain', $shop->shopify_domain)
            ->postJson('/api/migrations', ['entities' => ['product', 'category']]);

        $res->assertCreated();
        Queue::assertPushed(StartMigrationJob::class);
        $this->assertDatabaseHas('store_migrations', ['shop_id' => $shop->id, 'status' => 'pending']);
    }

    public function test_selecting_variations_forces_attributes(): void
    {
        Queue::fake();
        $shop = $this->shopWithConnection();

        $this->withHeader('X-Shop-Domain', $shop->shopify_domain)
            ->postJson('/api/migrations', ['entities' => ['variation']])
            ->assertCreated();

        $migration = StoreMigration::first();
        $this->assertContains('attribute', $migration->selected_entities);
    }

    public function test_dependency_ordering_puts_categories_before_products(): void
    {
        $order = app(MigratorRegistry::class)->order(['product', 'category', 'media']);

        $this->assertLessThan(
            array_search('product', $order, true),
            array_search('category', $order, true),
            'category must be ordered before product',
        );
        $this->assertLessThan(
            array_search('media', $order, true),
            array_search('product', $order, true),
            'product must be ordered before media',
        );
    }

    public function test_product_migration_is_idempotent(): void
    {
        // One fake serves both the location lookup and the productSet mutation.
        Http::fake([
            '*/graphql.json' => Http::response([
                'data' => [
                    'locations' => ['nodes' => [['id' => 'gid://shopify/Location/1', 'isActive' => true]]],
                    'productSet' => ['product' => ['id' => 'gid://shopify/Product/123'], 'userErrors' => []],
                ],
            ], 200),
        ]);

        $shop = $this->shopWithConnection();
        $migration = StoreMigration::create([
            'shop_id' => $shop->id,
            'woocommerce_connection_id' => $shop->woocommerceConnection->id,
            'status' => 'processing',
            'selected_entities' => ['product'],
        ]);
        $migration->setRelation('shop', $shop);
        $migration->setRelation('connection', $shop->woocommerceConnection);

        $migrator = new ProductMigrator();
        $woo = ['id' => 999, 'name' => 'Widget', 'status' => 'publish'];

        $first = $migrator->migrateItem($migration, $woo);
        $second = $migrator->migrateItem($migration, $woo);

        $this->assertSame('success', $first->status);
        $this->assertSame('gid://shopify/Product/123', $first->shopifyId);
        // Products upsert: the re-run UPDATES in place (never a duplicate).
        $this->assertSame('success', $second->status);

        // Still only ONE mapping row for this source object (no duplication).
        $this->assertSame(1, MigrationItem::where('woocommerce_id', 999)->count());

        // The second run targets the existing product id (an update, not a new create).
        Http::assertSent(function ($req) {
            $body = json_decode($req->body(), true);
            return data_get($body, 'variables.input.id') === 'gid://shopify/Product/123';
        });
    }

    public function test_custom_tags_are_applied_to_products(): void
    {
        Http::fake([
            '*/graphql.json' => Http::response([
                'data' => [
                    'locations' => ['nodes' => [['id' => 'gid://shopify/Location/1', 'isActive' => true]]],
                    'productSet' => ['product' => ['id' => 'gid://shopify/Product/1'], 'userErrors' => []],
                ],
            ], 200),
        ]);

        $shop = $this->shopWithConnection();
        $migration = StoreMigration::create([
            'shop_id' => $shop->id,
            'woocommerce_connection_id' => $shop->woocommerceConnection->id,
            'status' => 'processing',
            'selected_entities' => ['product'],
            'options' => ['custom_tags' => ['migrated', 'from-woo']],
        ]);
        $migration->setRelation('shop', $shop);
        $migration->setRelation('connection', $shop->woocommerceConnection);

        (new ProductMigrator())->migrateItem($migration, ['id' => 5, 'name' => 'Tagged', 'status' => 'publish']);

        Http::assertSent(function ($req) {
            $body = json_decode($req->body(), true);
            $tags = data_get($body, 'variables.input.tags', []);
            return in_array('migrated', $tags, true) && in_array('from-woo', $tags, true);
        });
    }
}
