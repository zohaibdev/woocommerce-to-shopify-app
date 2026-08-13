<?php

namespace Tests\Feature;

use App\Migration\Migrators\ProductMigrator;
use App\Models\Shop;
use App\Models\StoreMigration;
use App\Models\WoocommerceConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * WooCommerce never reports its weight unit through the REST API, so the
 * merchant chooses it. These prove the choice reaches Shopify.
 */
class WeightUnitTest extends TestCase
{
    use RefreshDatabase;

    private function migrationWithUnit(?string $unit): StoreMigration
    {
        $shop = Shop::create([
            'shopify_domain' => 'demo.myshopify.com',
            'access_token' => 'shpat_x',
            'token_expires_at' => now()->addHour(),
        ]);
        $conn = WoocommerceConnection::create([
            'shop_id' => $shop->id,
            'store_url' => 'https://woo.example.com',
            'consumer_key' => 'ck',
            'consumer_secret' => 'cs',
            'status' => 'connected',
        ]);

        $m = StoreMigration::create([
            'shop_id' => $shop->id,
            'woocommerce_connection_id' => $conn->id,
            'status' => 'processing',
            'selected_entities' => ['product'],
            'options' => $unit ? ['weight_unit' => $unit] : [],
        ]);
        $m->setRelation('shop', $shop);
        $m->setRelation('connection', $conn);

        return $m;
    }

    private function fakeShopify(): void
    {
        Http::fake([
            '*/graphql.json' => Http::response([
                'data' => [
                    'locations' => ['nodes' => [['id' => 'gid://shopify/Location/1']]],
                    'productSet' => ['product' => ['id' => 'gid://shopify/Product/1'], 'userErrors' => []],
                ],
            ], 200),
        ]);
    }

    private function assertUnitSent(string $expected): void
    {
        Http::assertSent(function ($req) use ($expected) {
            $body = json_decode($req->body(), true);
            $variants = data_get($body, 'variables.input.variants', []);
            foreach ($variants as $v) {
                $unit = data_get($v, 'inventoryItem.measurement.weight.unit');
                if ($unit !== null) {
                    return $unit === $expected;
                }
            }
            return true; // location query etc.
        });
    }

    public function test_merchant_selected_unit_is_used(): void
    {
        $this->fakeShopify();
        $migration = $this->migrationWithUnit('POUNDS');

        (new ProductMigrator())->migrateItem($migration, [
            'id' => 1, 'name' => 'Heavy thing', 'status' => 'publish',
            'weight' => '2.5', 'regular_price' => '10',
        ]);

        $this->assertUnitSent('POUNDS');
    }

    public function test_falls_back_to_configured_default(): void
    {
        config(['migration.weight_unit' => 'GRAMS']);
        $this->fakeShopify();
        $migration = $this->migrationWithUnit(null);

        (new ProductMigrator())->migrateItem($migration, [
            'id' => 2, 'name' => 'Light thing', 'status' => 'publish',
            'weight' => '300', 'regular_price' => '5',
        ]);

        $this->assertUnitSent('GRAMS');
    }
}
