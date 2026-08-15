<?php

namespace Tests\Feature;

use App\Jobs\ProcessBatchJob;
use App\Migration\MigrationRunner;
use App\Migration\Migrators\ProductMigrator;
use App\Models\MigrationBatch;
use App\Models\MigrationEntity;
use App\Models\MigrationLog;
use App\Models\Shop;
use App\Models\StoreMigration;
use App\Models\WoocommerceConnection;
use App\Services\ShopifyPlan;
use App\Support\SourceHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The behaviours a 50,000-product merchant depends on and a 50-product one never
 * exercises: Shopify's hard per-product limits, catalogues too big for one page,
 * batches running in parallel, and telling the merchant when their own store is
 * what is holding everything up.
 */
class LargeCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        // The health context is process-wide; leaving it set would misattribute
        // any WooCommerce call a later test makes.
        SourceHealth::within(null);

        parent::tearDown();
    }

    private function migration(array $options = []): StoreMigration
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

        $migration = StoreMigration::create([
            'shop_id' => $shop->id,
            'woocommerce_connection_id' => $conn->id,
            'status' => 'processing',
            'selected_entities' => ['product'],
            'options' => $options,
        ]);

        $migration->setRelation('shop', $shop);
        $migration->setRelation('connection', $conn);

        return $migration;
    }

    /**
     * Fakes Shopify + WooCommerce and hands back the productSet input by
     * reference, so a test can assert on exactly what we tried to write.
     */
    private function capture(?array &$input, array $variationPages = []): void
    {
        Http::fake(function ($request) use (&$input, $variationPages) {
            $url = $request->url();

            if (str_contains($url, '/variations')) {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
                $page = (int) ($query['page'] ?? 1);

                return Http::response($variationPages[$page] ?? [], 200);
            }

            if (str_contains($url, 'graphql.json')) {
                $body = $request->data();

                // ShopifyClient sends `variables` as an object (Shopify rejects a
                // JSON array there), so read it with data_get rather than as an array.
                if (str_contains((string) ($body['query'] ?? ''), 'productSet')) {
                    $input = data_get($body, 'variables.input');
                }

                // One blob answering every query the migrator makes; each caller
                // reads only the branch it asked for.
                return Http::response([
                    'data' => [
                        'locations' => ['nodes' => [['id' => 'gid://shopify/Location/1']]],
                        'shop' => ['plan' => ['displayName' => 'Shopify Plus']],
                        'productSet' => [
                            'product' => ['id' => 'gid://shopify/Product/1'],
                            'userErrors' => [],
                        ],
                    ],
                ], 200);
            }

            return Http::response([], 200);
        });
    }

    /** A Woo variation carrying one value per attribute. */
    private function variation(int $id, array $values): array
    {
        return [
            'id' => $id,
            'sku' => "SKU-{$id}",
            'regular_price' => '10.00',
            'attributes' => collect($values)
                ->map(fn ($value, $name) => ['name' => $name, 'option' => $value])
                ->values()
                ->all(),
        ];
    }

    public function test_four_woo_attributes_collapse_into_shopifys_three_options(): void
    {
        // Colour x Size x Material x Fit — ordinary in apparel, impossible in
        // Shopify as four options, and previously lost outright.
        $variations = [];
        $id = 100;

        foreach (['Red', 'Blue'] as $colour) {
            foreach (['S', 'M'] as $size) {
                foreach (['Cotton', 'Wool'] as $material) {
                    foreach (['Slim', 'Regular'] as $fit) {
                        $variations[] = $this->variation($id++, [
                            'Colour' => $colour,
                            'Size' => $size,
                            'Material' => $material,
                            'Fit' => $fit,
                        ]);
                    }
                }
            }
        }

        $this->capture($input, [1 => $variations]);

        $migration = $this->migration();

        $result = app(ProductMigrator::class)->migrateItem($migration, [
            'id' => 7,
            'name' => 'Hoodie',
            'type' => 'variable',
            'attributes' => [
                ['name' => 'Colour', 'variation' => true, 'options' => ['Red', 'Blue']],
                ['name' => 'Size', 'variation' => true, 'options' => ['S', 'M']],
                ['name' => 'Material', 'variation' => true, 'options' => ['Cotton', 'Wool']],
                ['name' => 'Fit', 'variation' => true, 'options' => ['Slim', 'Regular']],
            ],
        ]);

        $this->assertSame('success', $result->status);

        // Three options, as Shopify demands...
        $this->assertCount(3, $input['productOptions']);
        $this->assertSame('Colour', $input['productOptions'][0]['name']);
        $this->assertSame('Size', $input['productOptions'][1]['name']);
        $this->assertSame('Material / Fit', $input['productOptions'][2]['name']);

        // ...and not one of the 16 variations lost along the way.
        $this->assertCount(16, $input['variants']);

        // The merchant is told what happened rather than left to discover it.
        $this->assertStringContainsString('combined into a single option', $result->warning);
    }

    public function test_three_or_fewer_attributes_are_left_alone(): void
    {
        $this->capture($input, [1 => [
            $this->variation(1, ['Colour' => 'Red', 'Size' => 'S']),
            $this->variation(2, ['Colour' => 'Blue', 'Size' => 'M']),
        ]]);

        $result = app(ProductMigrator::class)->migrateItem($this->migration(), [
            'id' => 8,
            'name' => 'Tee',
            'type' => 'variable',
            'attributes' => [
                ['name' => 'Colour', 'variation' => true, 'options' => ['Red', 'Blue']],
                ['name' => 'Size', 'variation' => true, 'options' => ['S', 'M']],
            ],
        ]);

        $this->assertCount(2, $input['productOptions']);
        $this->assertCount(2, $input['variants']);
        $this->assertNull($result->warning);
    }

    public function test_option_values_keep_woocommerces_declared_order(): void
    {
        $this->capture($input, [1 => [
            $this->variation(1, ['Size' => 'Large']),
            $this->variation(2, ['Size' => 'Small']),
            $this->variation(3, ['Size' => 'Medium']),
        ]]);

        app(ProductMigrator::class)->migrateItem($this->migration(), [
            'id' => 9,
            'name' => 'Tee',
            'type' => 'variable',
            'attributes' => [
                ['name' => 'Size', 'variation' => true, 'options' => ['Small', 'Medium', 'Large']],
            ],
        ]);

        $this->assertSame(
            ['Small', 'Medium', 'Large'],
            array_column($input['productOptions'][0]['values'], 'name'),
        );
    }

    /**
     * Shoe sizes, waist sizes, pack counts — numeric option values are everywhere
     * in a real catalogue, and PHP silently turns a numeric string used as an
     * array key back into an int. Shopify's schema types option values as String
     * and rejects an Int, so this has to survive the round trip as text.
     */
    public function test_numeric_option_values_stay_strings(): void
    {
        $this->capture($input, [1 => [
            $this->variation(1, ['Size' => '42']),
            $this->variation(2, ['Size' => '44']),
        ]]);

        app(ProductMigrator::class)->migrateItem($this->migration(), [
            'id' => 12,
            'name' => 'Boot',
            'type' => 'variable',
            // No declared options list, so values can only come from the variations.
            'attributes' => [
                ['name' => 'Size', 'variation' => true, 'options' => []],
            ],
        ]);

        foreach ($input['productOptions'][0]['values'] as $value) {
            $this->assertIsString($value['name'], 'Option values must reach Shopify as strings');
        }

        $this->assertSame(['42', '44'], array_column($input['productOptions'][0]['values'], 'name'));
    }

    public function test_variations_beyond_the_first_page_are_migrated(): void
    {
        // 100 on page one is exactly the boundary the old code stopped at.
        $page1 = [];
        for ($i = 1; $i <= 100; $i++) {
            $page1[] = $this->variation($i, ['Size' => "S{$i}"]);
        }

        $page2 = [];
        for ($i = 101; $i <= 130; $i++) {
            $page2[] = $this->variation($i, ['Size' => "S{$i}"]);
        }

        $this->capture($input, [1 => $page1, 2 => $page2]);

        app(ProductMigrator::class)->migrateItem($this->migration(), [
            'id' => 10,
            'name' => 'Many sizes',
            'type' => 'variable',
            'attributes' => [
                ['name' => 'Size', 'variation' => true, 'options' => []],
            ],
        ]);

        $this->assertCount(130, $input['variants']);
    }

    public function test_media_is_capped_at_shopifys_limit(): void
    {
        $images = [];
        for ($i = 1; $i <= 300; $i++) {
            $images[] = ['id' => $i, 'src' => "https://woo.example.com/img-{$i}.jpg", 'alt' => ''];
        }

        $this->capture($input);

        $result = app(ProductMigrator::class)->migrateItem(
            // `full` keeps the URLs untouched so this test is about the cap only.
            $this->migration(['image_size' => 'full']),
            ['id' => 11, 'name' => 'Gallery', 'type' => 'simple', 'images' => $images],
        );

        $this->assertCount(250, $input['files']);
        $this->assertStringContainsString('250', $result->warning);
    }

    public function test_a_batch_already_held_by_another_worker_is_not_processed_twice(): void
    {
        Queue::fake();
        Http::fake();

        $migration = $this->migration();

        MigrationEntity::create([
            'migration_id' => $migration->id,
            'entity_type' => 'product',
            'total_items' => 10,
        ]);

        // 'processing' means a live worker owns it. The claim must refuse.
        $batch = MigrationBatch::create([
            'migration_id' => $migration->id,
            'entity_type' => 'product',
            'batch_number' => 1,
            'page' => 1,
            'per_page' => 10,
            'processed_offset' => 0,
            'status' => 'processing',
        ]);

        (new ProcessBatchJob($batch->id))->handle(app(\App\Migration\MigratorRegistry::class));

        // No WooCommerce page was pulled and the offset was left exactly where
        // the owning worker had it — the two would otherwise race it forward past
        // records neither of them migrated.
        Http::assertNothingSent();
        $this->assertSame(0, (int) $batch->fresh()->processed_offset);
        $this->assertSame('processing', $batch->fresh()->status);
    }

    /** Create N pending batches of one entity for a migration. */
    private function batches(StoreMigration $migration, string $entityType, int $count, int $from = 1): void
    {
        for ($i = $from; $i < $from + $count; $i++) {
            MigrationBatch::create([
                'migration_id' => $migration->id,
                'entity_type' => $entityType,
                'batch_number' => $i,
                'page' => $i,
                'per_page' => 100,
                'processed_offset' => 0,
                'status' => 'pending',
            ]);
        }
    }

    public function test_batches_of_one_entity_are_dispatched_in_parallel(): void
    {
        Queue::fake();

        $migration = $this->migration();

        // A Standard-plan shop: 100 points/second works out at three workers.
        $migration->shop->forceFill(['api_restore_rate' => 100, 'api_bucket_size' => 2000])->save();

        $this->batches($migration, 'product', 10);

        MigrationRunner::advance($migration->id);

        Queue::assertPushed(ProcessBatchJob::class, 3);
    }

    public function test_an_entity_never_starts_before_the_one_it_depends_on_finishes(): void
    {
        Queue::fake();

        $migration = $this->migration();
        $migration->shop->forceFill(['api_restore_rate' => 1000])->save();

        // Products depend on categories, so nothing product-shaped may go out
        // while a single category batch is still outstanding.
        $this->batches($migration, 'category', 1);
        $this->batches($migration, 'product', 20, from: 2);

        MigrationRunner::advance($migration->id);

        Queue::assertPushed(ProcessBatchJob::class, 1);
    }

    public function test_slots_already_in_flight_are_not_handed_out_twice(): void
    {
        Queue::fake();

        $migration = $this->migration();
        $migration->shop->forceFill(['api_restore_rate' => 100])->save();

        $this->batches($migration, 'product', 10);

        // Two of the three slots are already occupied by live workers.
        MigrationBatch::where('migration_id', $migration->id)
            ->orderBy('id')
            ->limit(2)
            ->update(['status' => 'processing']);

        MigrationRunner::advance($migration->id);

        Queue::assertPushed(ProcessBatchJob::class, 1);
    }

    public function test_migration_speed_follows_the_merchants_shopify_plan(): void
    {
        $plan = app(ShopifyPlan::class);

        $shop = Shop::create([
            'shopify_domain' => 'a.myshopify.com',
            'access_token' => 'shpat_x',
            'token_expires_at' => now()->addHour(),
        ]);

        // Nothing measured yet — start cautiously rather than guessing high.
        $this->assertSame(2, $plan->concurrencyFor($shop));

        $plan->remember($shop, bucket: 2000, restoreRate: 100);
        $this->assertSame('standard', $plan->tier($shop->fresh()));
        $this->assertSame(3, $plan->concurrencyFor($shop->fresh()));

        $plan->remember($shop->fresh(), bucket: 20000, restoreRate: 1000);
        $this->assertSame('plus', $plan->tier($shop->fresh()));

        // A Plus store gets far more headroom, but never more than the ceiling —
        // past that the merchant's WooCommerce host, not Shopify, is the limit.
        $this->assertSame(
            (int) config('migration.max_concurrency'),
            $plan->concurrencyFor($shop->fresh()),
        );
    }

    /**
     * The report is streamed and chunked now. A run that fails thousands of
     * records used to assemble the whole thing in memory as one string, which is
     * an out-of-memory for precisely the merchants who need to read it.
     */
    public function test_the_log_download_streams_every_failed_item(): void
    {
        $migration = $this->migration();

        MigrationEntity::create([
            'migration_id' => $migration->id,
            'entity_type' => 'product',
            'total_items' => 2500,
            'failed_items' => 2500,
        ]);

        $rows = [];
        for ($i = 1; $i <= 2500; $i++) {
            $rows[] = [
                'migration_id' => $migration->id,
                'shop_id' => $migration->shop_id,
                'entity_type' => 'product',
                'woocommerce_id' => $i,
                'status' => 'failed',
                'error' => "boom {$i}",
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        collect($rows)->chunk(500)->each(fn ($c) => \App\Models\MigrationItem::insert($c->all()));

        $response = $this->withHeaders(['X-Shop-Domain' => $migration->shop->shopify_domain])
            ->get("/api/migrations/{$migration->id}/download");

        $response->assertOk();

        $body = $response->streamedContent();

        // Header count and body content must agree — the count() and the chunked
        // read are two separate queries over the same builder.
        $this->assertStringContainsString('FAILED ITEMS (2500)', $body);
        $this->assertStringContainsString('woo #1: boom 1', $body);
        $this->assertStringContainsString('woo #2500: boom 2500', $body);
        $this->assertSame(2500, substr_count($body, 'boom '));
    }

    public function test_a_slow_woocommerce_host_is_reported_to_the_merchant(): void
    {
        $migration = $this->migration();

        SourceHealth::within($migration->id);

        foreach (range(1, 5) as $ignored) {
            SourceHealth::record(14.0);
        }

        $snapshot = SourceHealth::snapshot($migration->id);

        $this->assertSame('very_slow', $snapshot['status']);
        $this->assertStringContainsString('waiting on your store', $snapshot['message']);

        // Said once, on the transition — not once per request.
        $warnings = MigrationLog::where('migration_id', $migration->id)
            ->where('level', 'warning')
            ->count();

        $this->assertSame(1, $warnings);
    }

    public function test_a_dead_woocommerce_host_is_reported_as_failing(): void
    {
        $migration = $this->migration();

        SourceHealth::within($migration->id);

        foreach (range(1, 3) as $ignored) {
            SourceHealth::record(30.0, failed: true);
        }

        $snapshot = SourceHealth::snapshot($migration->id);

        $this->assertSame('failing', $snapshot['status']);
        $this->assertStringContainsString('stopped responding', $snapshot['message']);
    }

    public function test_a_healthy_host_says_nothing(): void
    {
        $migration = $this->migration();

        SourceHealth::within($migration->id);

        foreach (range(1, 5) as $ignored) {
            SourceHealth::record(0.4);
        }

        $this->assertSame('healthy', SourceHealth::snapshot($migration->id)['status']);
        $this->assertSame(0, MigrationLog::where('migration_id', $migration->id)->count());
    }
}
