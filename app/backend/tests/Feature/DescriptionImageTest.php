<?php

namespace Tests\Feature;

use App\Migration\Migrators\ProductMigrator;
use App\Models\MigrationFile;
use App\Models\Shop;
use App\Models\StoreMigration;
use App\Models\WoocommerceConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The images INSIDE a description, which were the one part of a product that
 * never actually migrated.
 *
 * Gallery photos were always copied to Shopify. The size chart, the fabric
 * close-up, the photos that make up half of what a long-form product page says —
 * those live in descriptionHtml, which passed through verbatim, so every one of
 * them still pointed at the merchant's WooCommerce server. That works right up
 * until the old store is switched off, which is the entire point of migrating,
 * and then they all break at once on the store the merchant now depends on.
 *
 * The feature is opt-in because it costs an upload per new image, so most of
 * these tests are about the things that keep it affordable and safe: not
 * uploading the same banner twenty thousand times, not touching images that
 * aren't the merchant's, and never letting an image problem cost the product.
 */
class DescriptionImageTest extends TestCase
{
    use RefreshDatabase;

    private function migration(bool $rehost = true): StoreMigration
    {
        $shop = Shop::create([
            'shopify_domain' => 'descimg.myshopify.com',
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

        $migration = StoreMigration::create([
            'shop_id' => $shop->id,
            'woocommerce_connection_id' => $conn->id,
            'status' => 'processing',
            'selected_entities' => ['product'],
            'planned_at' => now(),
            'options' => ['rehost_description_images' => $rehost],
        ]);

        $migration->setRelation('shop', $shop);
        $migration->setRelation('connection', $conn);

        return $migration;
    }

    /**
     * Shopify: fileCreate returns a ready file, productSet succeeds.
     *
     * @param  array<int, string>  $capturedDescription  filled with what productSet was sent
     */
    private function fakeShopify(?array &$captured = null, string $cdnUrl = 'https://cdn.shopify.com/files/chart.png'): void
    {
        Http::fake(function ($request) use (&$captured, $cdnUrl) {
            $body = $request->data();
            $query = (string) ($body['query'] ?? '');

            if (str_contains($query, 'fileCreate')) {
                return Http::response(['data' => ['fileCreate' => [
                    'files' => [['id' => 'gid://shopify/MediaImage/1', 'fileStatus' => 'READY', 'image' => ['url' => $cdnUrl]]],
                    'userErrors' => [],
                ]]], 200);
            }

            if (str_contains($query, 'productSet')) {
                // ShopifyClient sends `variables` as an OBJECT — Shopify rejects
                // a JSON array there — so it arrives as stdClass, not an array.
                $captured = (array) (((array) ($body['variables'] ?? []))['input'] ?? []);

                return Http::response(['data' => ['productSet' => [
                    'product' => ['id' => 'gid://shopify/Product/1'],
                    'userErrors' => [],
                ]]], 200);
            }

            return Http::response(['data' => []], 200);
        });
    }

    private function productWith(string $description): array
    {
        return [
            'id' => 1,
            'name' => 'Jacket',
            'type' => 'simple',
            'status' => 'publish',
            'description' => $description,
        ];
    }

    /** @test */
    public function a_description_image_is_re_hosted_and_the_html_rewritten(): void
    {
        $this->fakeShopify($captured);

        $migration = $this->migration();

        (new ProductMigrator)->migrateItem($migration, $this->productWith(
            '<p>Sizing</p><img src="https://woo.example.com/wp-content/uploads/chart.png" alt="chart">',
        ));

        $this->assertStringContainsString('https://cdn.shopify.com/files/chart.png', $captured['descriptionHtml']);
        $this->assertStringNotContainsString('woo.example.com', $captured['descriptionHtml']);
    }

    /** @test */
    public function the_same_image_across_many_products_is_uploaded_once(): void
    {
        // The whole reason this is affordable. Description images are template
        // furniture — one banner pasted into every product — so without the
        // per-shop record a 20,000-product store would upload the same file
        // twenty thousand times, each one a mutation plus a poll.
        $this->fakeShopify();

        $migration = $this->migration();
        $html = '<img src="https://woo.example.com/wp-content/uploads/banner.png">';

        foreach ([1, 2, 3] as $id) {
            (new ProductMigrator)->migrateItem($migration, [
                'id' => $id,
                'name' => "Product {$id}",
                'type' => 'simple',
                'status' => 'publish',
                'description' => $html,
            ]);
        }

        $this->assertSame(1, MigrationFile::where('shop_id', $migration->shop_id)->count());

        $uploads = collect(Http::recorded())
            ->filter(fn ($pair) => str_contains((string) ($pair[0]->data()['query'] ?? ''), 'fileCreate'))
            ->count();

        $this->assertSame(1, $uploads, 'one upload, then the record is reused');
    }

    /** @test */
    public function images_hosted_somewhere_else_are_left_alone(): void
    {
        // A supplier's CDN or a YouTube thumbnail is not going anywhere when the
        // WooCommerce box is switched off, and copying somebody else's asset into
        // the merchant's Shopify files is not ours to do.
        $this->fakeShopify($captured);

        $migration = $this->migration();

        (new ProductMigrator)->migrateItem($migration, $this->productWith(
            '<img src="https://images.supplier.com/spec.png">',
        ));

        $this->assertStringContainsString('images.supplier.com/spec.png', $captured['descriptionHtml']);
        $this->assertSame(0, MigrationFile::count());
    }

    /** @test */
    public function nothing_happens_unless_the_merchant_asked_for_it(): void
    {
        // Opt-in: a merchant keeping their WooCommerce store online does not need
        // this, and it makes every product slower.
        $this->fakeShopify($captured);

        $migration = $this->migration(rehost: false);

        (new ProductMigrator)->migrateItem($migration, $this->productWith(
            '<img src="https://woo.example.com/wp-content/uploads/chart.png">',
        ));

        $this->assertStringContainsString('woo.example.com', $captured['descriptionHtml']);
        $this->assertSame(0, MigrationFile::count());
    }

    /** @test */
    public function an_image_shopify_refuses_costs_a_warning_not_the_product(): void
    {
        Http::fake(function ($request) {
            $body = $request->data();
            $query = (string) ($body['query'] ?? '');

            if (str_contains($query, 'fileCreate')) {
                return Http::response(['data' => ['fileCreate' => [
                    'files' => [],
                    'userErrors' => [['field' => ['files'], 'message' => 'Could not download the file']],
                ]]], 200);
            }

            return Http::response(['data' => ['productSet' => [
                'product' => ['id' => 'gid://shopify/Product/1'],
                'userErrors' => [],
            ]]], 200);
        });

        $migration = $this->migration();

        $result = (new ProductMigrator)->migrateItem($migration, $this->productWith(
            '<img src="https://woo.example.com/wp-content/uploads/gone.png">',
        ));

        $this->assertSame('success', $result->status, 'a dead image must never cost the product');
        $this->assertStringContainsString('still point at your WooCommerce store', (string) $result->warning);
    }

    /** @test */
    public function a_description_with_no_images_costs_nothing(): void
    {
        $this->fakeShopify();

        $migration = $this->migration();

        (new ProductMigrator)->migrateItem($migration, $this->productWith('<p>Just words.</p>'));

        $uploads = collect(Http::recorded())
            ->filter(fn ($pair) => str_contains((string) ($pair[0]->data()['query'] ?? ''), 'fileCreate'))
            ->count();

        $this->assertSame(0, $uploads);
    }

    /** @test */
    public function a_data_uri_is_not_mistaken_for_a_file_to_download(): void
    {
        $this->fakeShopify($captured);

        $migration = $this->migration();

        $inline = '<img src="data:image/gif;base64,R0lGODlhAQABAAAAACw=">';

        (new ProductMigrator)->migrateItem($migration, $this->productWith($inline));

        $this->assertStringContainsString('data:image/gif', $captured['descriptionHtml']);
        $this->assertSame(0, MigrationFile::count());
    }
}
