<?php

namespace Tests\Feature;

use App\Migration\Migrators\CategoryMigrator;
use App\Migration\Migrators\CustomerMigrator;
use App\Migration\Migrators\OrderMigrator;
use App\Migration\Migrators\ProductMigrator;
use App\Models\Shop;
use App\Models\StoreMigration;
use App\Models\WoocommerceConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * WooCommerce data is messy in ways Shopify refuses — a deleted image, a local
 * phone format, a tag that is too long. Any one of those used to fail the whole
 * record. Now the offending OPTIONAL field is shed, the record lands, and the
 * merchant is told exactly what was left behind.
 *
 * The other half matters just as much: a rejection on something REQUIRED must
 * still fail loudly, or real problems disappear.
 */
class FieldSheddingTest extends TestCase
{
    use RefreshDatabase;

    private function migration(array $entities = ['product']): StoreMigration
    {
        $shop = Shop::create([
            'shopify_domain' => 'shedding.myshopify.com',
            'access_token' => 'shpat_test',
            'token_expires_at' => now()->addHour(),
        ]);

        $connection = WoocommerceConnection::create([
            'shop_id' => $shop->id,
            'store_url' => 'https://woo.example.com',
            'consumer_key' => 'ck',
            'consumer_secret' => 'cs',
            'status' => 'connected',
        ]);

        $migration = StoreMigration::create([
            'shop_id' => $shop->id,
            'woocommerce_connection_id' => $connection->id,
            'status' => 'processing',
            'selected_entities' => $entities,
        ]);

        $migration->setRelation('shop', $shop);
        $migration->setRelation('connection', $connection);

        return $migration;
    }

    /** Answers the location query, then walks the given productSet responses. */
    private function fakeProductSet(array $responses): void
    {
        $i = 0;
        Http::fake(function ($request) use (&$i, $responses) {
            $query = (string) data_get(json_decode($request->body(), true), 'query');

            if (str_contains($query, 'locations')) {
                return Http::response(['data' => ['locations' => ['nodes' => [['id' => 'gid://shopify/Location/1']]]]], 200);
            }

            if (! str_contains($query, 'productSet')) {
                return Http::response(['data' => []], 200);
            }

            return Http::response(['data' => ['productSet' => $responses[min($i++, count($responses) - 1)]]], 200);
        });
    }

    public function test_a_product_sheds_metafields_and_survives(): void
    {
        $this->fakeProductSet([
            ['product' => null, 'userErrors' => [['field' => ['metafields'], 'message' => 'Value is invalid']]],
            ['product' => ['id' => 'gid://shopify/Product/1'], 'userErrors' => []],
        ]);

        $result = (new ProductMigrator())->migrateItem($this->migration(), [
            'id' => 1,
            'name' => 'Widget',
            'status' => 'publish',
            'meta_data' => [['key' => 'colour', 'value' => 'red']],
        ]);

        $this->assertSame('success', $result->status);
        $this->assertStringContainsString('Custom fields skipped', (string) $result->warning);
    }

    public function test_a_product_sheds_several_bad_fields_in_turn(): void
    {
        $this->fakeProductSet([
            ['product' => null, 'userErrors' => [['field' => null, 'message' => 'Image upload failed. failed to download.']]],
            ['product' => null, 'userErrors' => [['field' => ['tags'], 'message' => 'Tag is too long']]],
            ['product' => ['id' => 'gid://shopify/Product/2'], 'userErrors' => []],
        ]);

        $result = (new ProductMigrator())->migrateItem($this->migration(), [
            'id' => 2,
            'name' => 'Widget',
            'status' => 'publish',
            'images' => [['src' => 'https://woo.example.com/gone.jpg']],
            'tags' => [['name' => str_repeat('x', 300)]],
        ]);

        $this->assertSame('success', $result->status, 'two bad fields should be shed one after another');
        $this->assertStringContainsString('Images skipped', (string) $result->warning);
        $this->assertStringContainsString('Tags skipped', (string) $result->warning);
    }

    public function test_a_required_field_rejection_still_fails_the_product(): void
    {
        $this->fakeProductSet([
            ['product' => null, 'userErrors' => [['field' => ['variants'], 'message' => 'Variant price is invalid']]],
        ]);

        $result = (new ProductMigrator())->migrateItem($this->migration(), [
            'id' => 3,
            'name' => 'Widget',
            'status' => 'publish',
        ]);

        $this->assertSame('failed', $result->status, 'variants are not droppable — this must surface');
    }

    public function test_a_mixed_error_set_fails_rather_than_hiding_the_real_problem(): void
    {
        $this->fakeProductSet([
            ['product' => null, 'userErrors' => [
                ['field' => ['tags'], 'message' => 'Tag is too long'],
                ['field' => ['title'], 'message' => 'Title cannot be blank'],
            ]],
        ]);

        $result = (new ProductMigrator())->migrateItem($this->migration(), [
            'id' => 4,
            'name' => 'Widget',
            'status' => 'publish',
        ]);

        $this->assertSame('failed', $result->status);
    }

    public function test_an_order_sheds_its_payment_record_but_keeps_the_order(): void
    {
        $i = 0;
        Http::fake(function () use (&$i) {
            return Http::response(['data' => ['orderCreate' => ++$i === 1
                ? ['order' => null, 'userErrors' => [['field' => ['transactions'], 'message' => 'Transaction amount is invalid']]]
                : ['order' => ['id' => 'gid://shopify/Order/5'], 'userErrors' => []]]], 200);
        });

        $result = (new OrderMigrator())->migrateItem($this->migration(['order']), [
            'id' => 5,
            'currency' => 'USD',
            'status' => 'completed',
            'date_created' => '2024-01-01T10:00:00',
            'billing' => ['email' => 'buyer@example.com'],
            'line_items' => [['name' => 'Thing', 'quantity' => 1, 'price' => '10.00']],
        ]);

        $this->assertSame('success', $result->status);
        $this->assertStringContainsString('Payment record skipped', (string) $result->warning);
    }

    public function test_a_category_sheds_its_image(): void
    {
        $i = 0;
        Http::fake(function () use (&$i) {
            return Http::response(['data' => ['collectionCreate' => ++$i === 1
                ? ['collection' => null, 'userErrors' => [['field' => null, 'message' => 'Image upload failed. file not found.']]]
                : ['collection' => ['id' => 'gid://shopify/Collection/6'], 'userErrors' => []]]], 200);
        });

        $result = (new CategoryMigrator())->migrateItem($this->migration(['category']), [
            'id' => 6,
            'name' => 'Bags',
            'image' => ['src' => 'https://woo.example.com/gone.jpg'],
        ]);

        $this->assertSame('success', $result->status);
        $this->assertStringContainsString('Image skipped', (string) $result->warning);
    }

    public function test_a_customer_sheds_a_rejected_address(): void
    {
        $i = 0;
        Http::fake(function () use (&$i) {
            return Http::response(['data' => ['customerCreate' => ++$i === 1
                ? ['customer' => null, 'userErrors' => [['field' => ['addresses'], 'message' => 'Country is not supported']]]
                : ['customer' => ['id' => 'gid://shopify/Customer/7'], 'userErrors' => []]]], 200);
        });

        $result = (new CustomerMigrator())->migrateItem($this->migration(['customer']), [
            'id' => 7,
            'email' => 'buyer@example.com',
            'billing' => ['address_1' => '1 Main St', 'country' => 'XX'],
        ]);

        $this->assertSame('success', $result->status);
        $this->assertStringContainsString('Addresses skipped', (string) $result->warning);
    }

    // ---------------------------------------------------------------------
    // Optimisation: stop re-offering media a store cannot serve
    // ---------------------------------------------------------------------

    public function test_it_stops_sending_images_once_the_store_clearly_cannot_serve_them(): void
    {
        Cache::flush();

        // A store whose /uploads folder is gone: anything carrying images fails,
        // the same request without them succeeds.
        $productSetCalls = 0;
        Http::fake(function ($request) use (&$productSetCalls) {
            $body = json_decode($request->body(), true);

            if (str_contains((string) ($body['query'] ?? ''), 'locations')) {
                return Http::response(['data' => ['locations' => ['nodes' => [['id' => 'gid://shopify/Location/1']]]]], 200);
            }

            $productSetCalls++;

            return Http::response(['data' => ['productSet' => data_get($body, 'variables.input.files') !== null
                ? ['product' => null, 'userErrors' => [['field' => null, 'message' => 'Image upload failed. file not found.']]]
                : ['product' => ['id' => 'gid://shopify/Product/9'], 'userErrors' => []]]], 200);
        });

        $migration = $this->migration();
        $migrator = new ProductMigrator();

        $product = fn (int $id) => [
            'id' => $id,
            'name' => "Widget {$id}",
            'status' => 'publish',
            'images' => [['src' => 'https://woo.example.com/gone.jpg']],
        ];

        // Five products whose images Shopify refuses — two calls each.
        foreach (range(1, 5) as $i) {
            $migrator->migrateItem($migration, $product(100 + $i));
        }

        $this->assertSame(10, $productSetCalls, 'each rejected product costs a retry');
        $this->assertTrue(
            Cache::get("migration:{$migration->id}:media_unreachable"),
            'after repeated media rejections the run should stop offering images',
        );

        // The sixth product goes out WITHOUT files on the first attempt — one call,
        // not two. Over a large catalogue that halves the Shopify traffic.
        $result = $migrator->migrateItem($migration, $product(200));

        $this->assertSame('success', $result->status);
        $this->assertSame(11, $productSetCalls, 'no wasted retry once the store is known to be unreachable');
    }
}
