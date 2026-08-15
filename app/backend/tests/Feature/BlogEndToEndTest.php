<?php

namespace Tests\Feature;

use App\Jobs\ProcessBatchJob;
use App\Models\MigrationBatch;
use App\Models\MigrationItem;
use App\Models\Shop;
use App\Models\StoreMigration;
use App\Models\WoocommerceConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Blogs, driven the way a real run drives them: plan a batch, let ProcessBatchJob
 * resolve it to source ids and fetch by id, then write to Shopify.
 *
 * The unit test for BlogMigrator passes a category array straight to
 * migrateItem(), which skips the entire WooCommerce read path — so a run that
 * reported "0 records" looked identical to one that worked.
 */
class BlogEndToEndTest extends TestCase
{
    use RefreshDatabase;

    private array $requested = [];

    private function migration(): StoreMigration
    {
        $shop = Shop::create([
            'shopify_domain' => 'blogs.myshopify.com',
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

        return StoreMigration::create([
            'shop_id' => $shop->id,
            'woocommerce_connection_id' => $conn->id,
            'status' => 'processing',
            'selected_entities' => ['blog'],
        ]);
    }

    /** A WordPress store with three post categories, behaving the way WP does. */
    private function fakeWordPress(array $categories): void
    {
        Http::fake(function ($request) use ($categories) {
            $url = $request->url();
            $this->requested[] = $url;

            if (str_contains($url, 'graphql.json')) {
                return Http::response([
                    'data' => ['blogCreate' => [
                        'blog' => ['id' => 'gid://shopify/Blog/1'],
                        'userErrors' => [],
                    ]],
                ], 200);
            }

            if (str_contains($url, '/wp/v2/categories')) {
                return Http::response($categories, 200, ['X-WP-Total' => (string) count($categories)]);
            }

            return Http::response([], 200);
        });
    }

    public function test_a_planned_blog_batch_actually_creates_blogs(): void
    {
        Queue::fake();

        $migration = $this->migration();

        $this->fakeWordPress([
            ['id' => 1, 'name' => 'Uncategorized', 'slug' => 'uncategorized'],
            ['id' => 7, 'name' => 'Company News', 'slug' => 'company-news'],
            ['id' => 9, 'name' => 'Guides', 'slug' => 'guides'],
        ]);

        MigrationBatch::create([
            'migration_id' => $migration->id,
            'entity_type' => 'blog',
            'batch_number' => 1,
            'page' => 1,
            'per_page' => 3,
            'processed_offset' => 0,
            'status' => 'pending',
        ]);

        (new ProcessBatchJob(MigrationBatch::first()->id))->handle(app(\App\Migration\MigratorRegistry::class));

        $items = MigrationItem::where('migration_id', $migration->id)->get();

        $this->assertCount(
            3,
            $items,
            "the batch produced no migration_items at all — requests made:\n"
                .implode("\n", $this->requested),
        );

        $this->assertSame(
            ['success', 'success', 'success'],
            $items->pluck('status')->all(),
            'blogs were read but not written: '.$items->pluck('error')->filter()->implode(' | '),
        );
    }
}
