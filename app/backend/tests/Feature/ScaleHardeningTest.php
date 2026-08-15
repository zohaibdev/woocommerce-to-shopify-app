<?php

namespace Tests\Feature;

use App\Jobs\ProcessBatchJob;
use App\Migration\MigrationRunner;
use App\Migration\Migrators\ProductMigrator;
use App\Models\MigrationBatch;
use App\Models\MigrationEntity;
use App\Models\MigrationItem;
use App\Models\MigrationLog;
use App\Models\Shop;
use App\Models\StoreMigration;
use App\Models\WoocommerceConnection;
use App\Services\WooCommerceClient;
use App\Support\SourceHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The failure modes that only a real, messy, large store produces — every one of
 * these was found by reading the code against a 50,000-record catalogue rather
 * than by anything the existing suite was exercising.
 */
class ScaleHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        SourceHealth::within(null);

        parent::tearDown();
    }

    private function shop(string $domain = 'scale.myshopify.com'): Shop
    {
        return Shop::create([
            'shopify_domain' => $domain,
            'access_token' => 'shpat_x',
            'token_expires_at' => now()->addHour(),
        ]);
    }

    private function migration(?Shop $shop = null, array $options = []): StoreMigration
    {
        $shop ??= $this->shop();

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

    private function batchOf(StoreMigration $migration, int $total = 1): MigrationBatch
    {
        MigrationEntity::create([
            'migration_id' => $migration->id,
            'entity_type' => 'product',
            'status' => 'processing',
            'total_items' => $total,
        ]);

        return MigrationBatch::create([
            'migration_id' => $migration->id,
            'entity_type' => 'product',
            'batch_number' => 1,
            'page' => 1,
            'per_page' => 100,
            'processed_offset' => 0,
            'status' => 'pending',
        ]);
    }

    // ---------------------------------------------------------------- logging

    /** @test */
    public function an_enormous_error_message_is_stored_instead_of_killing_the_batch(): void
    {
        $migration = $this->migration();

        // A Shopify userError set encoded as JSON, of the size real ones reach.
        MigrationRunner::log($migration->id, 'error', 'product', str_repeat('x', 40_000));

        $this->assertSame(1, MigrationLog::where('migration_id', $migration->id)->count());
    }

    /** @test */
    public function a_log_write_can_never_take_the_migration_down_with_it(): void
    {
        // The row it belongs to does not exist, so the insert violates the foreign
        // key. The run must not care: this is a diary entry, not the work.
        MigrationRunner::log(999_999, 'error', 'product', 'orphan');

        $this->assertTrue(true);
    }

    // ------------------------------------------------------- concurrent runs

    /** @test */
    public function a_second_migration_cannot_start_while_one_is_running(): void
    {
        Queue::fake();

        $shop = $this->shop();
        $this->migration($shop); // status: processing

        $response = $this->withHeaders(['X-Shop-Domain' => $shop->shopify_domain])
            ->postJson('/api/migrations', ['entities' => ['product']]);

        $response->assertStatus(409);
        $this->assertSame(1, StoreMigration::where('shop_id', $shop->id)->count());
    }

    /** @test */
    public function a_new_migration_is_allowed_once_the_previous_one_finished(): void
    {
        Queue::fake();

        $shop = $this->shop();
        $this->migration($shop)->update(['status' => 'completed']);

        $this->withHeaders(['X-Shop-Domain' => $shop->shopify_domain])
            ->postJson('/api/migrations', ['entities' => ['product']])
            ->assertCreated();

        $this->assertSame(2, StoreMigration::where('shop_id', $shop->id)->count());
    }

    // ------------------------------------------------------- counting fallback

    /** @test */
    public function a_store_that_hides_the_count_header_is_counted_by_probing(): void
    {
        // 250 products: pages 1 and 2 are full, page 3 has 50, page 4+ is empty.
        // Crucially NO X-WP-Total header — which used to be read as "zero
        // products", so the run completed instantly having migrated nothing.
        Http::fake(function ($request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $page = (int) ($query['page'] ?? 1);
            $per = (int) ($query['per_page'] ?? 100);

            $remaining = max(0, 250 - ($page - 1) * $per);
            $size = min($per, $remaining);

            return Http::response(array_fill(0, $size, ['id' => 1]), 200);
        });

        $connection = $this->migration()->connection;

        $this->assertSame(250, (new WooCommerceClient($connection))->total('products'));
    }

    /** @test */
    public function the_count_header_is_still_preferred_when_the_store_sends_one(): void
    {
        Http::fake(fn () => Http::response([['id' => 1]], 200, ['X-WP-Total' => '4321']));

        $connection = $this->migration()->connection;

        $this->assertSame(4321, (new WooCommerceClient($connection))->total('products'));
    }

    /** @test */
    public function expired_credentials_are_reported_instead_of_read_as_an_empty_store(): void
    {
        // The most common WooCommerce problem there is. Returning 0 here told the
        // merchant they had no products and finished the run "successfully".
        Http::fake(fn () => Http::response(['code' => 'woocommerce_rest_cannot_view'], 401));

        $connection = $this->migration()->connection;

        $this->expectException(\App\Services\Exceptions\WooCommerceUnavailableException::class);

        (new WooCommerceClient($connection))->total('products');
    }

    /** @test */
    public function the_wizard_is_told_why_every_count_came_back_empty(): void
    {
        Http::fake(fn () => Http::response(['code' => 'woocommerce_rest_cannot_view'], 401));

        $shop = $this->shop();
        $this->migration($shop);

        $response = $this->withHeaders(['X-Shop-Domain' => $shop->shopify_domain])
            ->getJson('/api/woocommerce/preview');

        $response->assertOk();
        $this->assertStringContainsString('401', (string) $response->json('source_error'));
        // And it still answers the rest of the wizard's questions.
        $this->assertIsBool($response->json('email_delivery_configured'));
    }

    /** @test */
    public function a_source_that_cannot_be_counted_fails_only_its_own_entity(): void
    {
        Queue::fake();

        $migration = $this->migration();
        $migration->update(['selected_entities' => ['product']]);

        Http::fake(function ($request) {
            if (str_contains($request->url(), 'graphql.json')) {
                return Http::response(['data' => ['shop' => ['plan' => ['displayName' => 'Basic']]]], 200);
            }

            return Http::response(['code' => 'woocommerce_rest_cannot_view'], 401);
        });

        (new \App\Jobs\StartMigrationJob($migration->id))->handle(app(\App\Migration\MigratorRegistry::class));

        $this->assertSame('failed', MigrationEntity::where('migration_id', $migration->id)->value('status'));
        $this->assertTrue(
            MigrationLog::where('migration_id', $migration->id)
                ->where('level', 'error')
                ->where('message', 'like', '%401%')
                ->exists(),
        );
    }

    // -------------------------------------------------------- wizard contract

    /** @test */
    public function the_shop_endpoint_carries_what_the_ui_needs_to_hide_new_migration(): void
    {
        $shop = $this->shop();
        $migration = $this->migration($shop); // processing

        $response = $this->withHeaders(['X-Shop-Domain' => $shop->shopify_domain])
            ->getJson('/api/shop');

        $response->assertOk()->assertJsonPath('active_migration_id', $migration->id);

        $migration->update(['status' => 'completed']);

        $this->withHeaders(['X-Shop-Domain' => $shop->shopify_domain])
            ->getJson('/api/shop')
            ->assertJsonPath('active_migration_id', null);
    }

    /** @test */
    public function the_completion_email_address_survives_into_the_migration(): void
    {
        Queue::fake();

        $shop = $this->shop();
        $this->migration($shop)->update(['status' => 'completed']);

        $this->withHeaders(['X-Shop-Domain' => $shop->shopify_domain])
            ->postJson('/api/migrations', [
                'entities' => ['product'],
                'notify_email' => 'owner@example.com',
                'image_size' => 'large',
            ])
            ->assertCreated();

        $created = StoreMigration::where('shop_id', $shop->id)->latest('id')->first();

        $this->assertSame('owner@example.com', $created->options['notify_email']);
        $this->assertSame('large', $created->options['image_size']);
    }

    /** @test */
    public function the_stored_connection_is_readable_without_calling_the_merchants_store(): void
    {
        // Any HTTP call at all means the page is testing rather than reading.
        Http::fake(fn () => Http::response([], 500));

        $shop = $this->shop();
        $this->migration($shop);

        $this->withHeaders(['X-Shop-Domain' => $shop->shopify_domain])
            ->getJson('/api/woocommerce/connection')
            ->assertOk()
            ->assertJsonPath('store_url', 'https://woo.example.com')
            ->assertJsonPath('status', 'connected');

        Http::assertNothingSent();
    }

    /** @test */
    public function a_failed_test_still_returns_the_connection_so_the_page_can_explain_itself(): void
    {
        Http::fake(fn () => Http::response(['message' => 'Sorry, you cannot list resources.'], 401));

        $shop = $this->shop();
        $this->migration($shop);

        $response = $this->withHeaders(['X-Shop-Domain' => $shop->shopify_domain])
            ->postJson('/api/woocommerce/test');

        // 422 is what tells the client the credentials failed — but the body has
        // to carry the connection, or the page renders as "nothing connected".
        $response->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('store_url', 'https://woo.example.com');

        $this->assertNotNull($response->json('error'));
    }

    // ------------------------------------------------------------- throttling

    /** @test */
    public function a_batch_that_is_throttled_forever_is_given_up_on_instead_of_looping(): void
    {
        Queue::fake();

        $migration = $this->migration();
        $batch = $this->batchOf($migration);

        Http::fake(function ($request) {
            if (str_contains($request->url(), 'graphql.json')) {
                // A soft rate limit that never clears, reported as a userError.
                return Http::response([
                    'data' => ['productSet' => [
                        'product' => null,
                        'userErrors' => [['field' => null, 'message' => 'Too many attempts. Please try again later.']],
                    ]],
                ], 200);
            }

            return Http::response([['id' => 7, 'name' => 'Thing', 'type' => 'simple', 'status' => 'publish']], 200);
        });

        // Each run hands the chunk to a fresh job, which is exactly why nothing
        // bounded this before: every one of them started its attempt counter over.
        //
        // The lease is what the real queue carries between those jobs — the batch
        // is never released, so a continuation identifies itself by the `attempts`
        // value it was handed rather than by finding the row free.
        $lease = null;

        for ($i = 0; $i < 15; $i++) {
            (new ProcessBatchJob($batch->id, $lease))->handle(app(\App\Migration\MigratorRegistry::class));
            $batch->refresh();

            if ($batch->status === 'failed') {
                break;
            }

            $lease = (int) $batch->attempts;
        }

        $this->assertSame('failed', $batch->status);
        $this->assertTrue(
            MigrationLog::where('migration_id', $migration->id)
                ->where('level', 'error')
                ->where('message', 'like', '%rate limited%')
                ->exists(),
        );
    }

    /** @test */
    public function merchant_data_that_merely_mentions_throttling_is_not_mistaken_for_it(): void
    {
        Queue::fake();

        $migration = $this->migration();
        $batch = $this->batchOf($migration);

        // The whole-body scan this replaces matched the PRODUCT TITLE and parked
        // the batch for a minute, forever, on a store that was perfectly healthy.
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'graphql.json')) {
                return Http::response([
                    'data' => ['productSet' => [
                        'product' => ['id' => 'gid://shopify/Product/1'],
                        'userErrors' => [],
                    ]],
                ], 200);
            }

            return Http::response([[
                'id' => 7,
                'name' => 'Throttled Valve — exceeded rate limit edition',
                'type' => 'simple',
                'status' => 'publish',
            ]], 200);
        });

        (new ProcessBatchJob($batch->id))->handle(app(\App\Migration\MigratorRegistry::class));

        $this->assertSame('completed', $batch->refresh()->status);
        $this->assertSame(
            'success',
            MigrationItem::where('migration_id', $migration->id)->value('status'),
        );
    }

    // ------------------------------------------------------ duplicate recovery

    /** @test */
    public function an_interrupted_write_adopts_the_product_shopify_already_has(): void
    {
        $migration = $this->migration();

        // Exactly the state a worker killed mid-write leaves behind: we tried,
        // and we never found out what happened.
        MigrationItem::create([
            'migration_id' => $migration->id,
            'shop_id' => $migration->shop_id,
            'entity_type' => 'product',
            'woocommerce_id' => 42,
            'shopify_id' => null,
            'status' => 'pending',
            'attempts' => 1,
        ]);

        $setCalls = 0;

        Http::fake(function ($request) use (&$setCalls) {
            $query = (string) data_get($request->data(), 'query');

            if (str_contains($query, 'findVariant')) {
                return Http::response(['data' => ['productVariants' => ['nodes' => [
                    ['product' => ['id' => 'gid://shopify/Product/555']],
                ]]]], 200);
            }

            if (str_contains($query, 'productSet')) {
                $setCalls++;

                return Http::response(['data' => ['productSet' => [
                    'product' => ['id' => 'gid://shopify/Product/555'],
                    'userErrors' => [],
                ]]], 200);
            }

            return Http::response(['data' => ['locations' => ['nodes' => [['id' => 'gid://shopify/Location/1']]]]], 200);
        });

        $result = app(ProductMigrator::class)->migrateItem($migration, [
            'id' => 42,
            'sku' => 'WIDGET-1',
            'slug' => 'widget',
            'name' => 'Widget',
            'type' => 'simple',
            'status' => 'publish',
        ]);

        // Products upsert, so it is written — but as an UPDATE of the product that
        // already exists, not as a second one.
        $this->assertSame('success', $result->status);
        $this->assertSame('gid://shopify/Product/555', $result->shopifyId);
        $this->assertSame(1, $setCalls);

        $mapping = MigrationItem::where('woocommerce_id', 42)->first();
        $this->assertSame('gid://shopify/Product/555', $mapping->shopify_id);
    }

    /** @test */
    public function a_first_attempt_never_pays_for_a_recovery_lookup(): void
    {
        $migration = $this->migration();
        $lookups = 0;

        Http::fake(function ($request) use (&$lookups) {
            $query = (string) data_get($request->data(), 'query');

            if (str_contains($query, 'findVariant') || str_contains($query, 'findProduct')) {
                $lookups++;
            }

            if (str_contains($query, 'productSet')) {
                return Http::response(['data' => ['productSet' => [
                    'product' => ['id' => 'gid://shopify/Product/1'],
                    'userErrors' => [],
                ]]], 200);
            }

            return Http::response(['data' => ['locations' => ['nodes' => [['id' => 'gid://shopify/Location/1']]]]], 200);
        });

        app(ProductMigrator::class)->migrateItem($migration, [
            'id' => 1,
            'sku' => 'A',
            'slug' => 'a',
            'name' => 'A',
            'type' => 'simple',
            'status' => 'publish',
        ]);

        $this->assertSame(0, $lookups);
        $this->assertSame(1, (int) MigrationItem::where('woocommerce_id', 1)->value('attempts'));
    }

    // ------------------------------------------------------------ cancellation

    /** @test */
    public function a_cancelled_batch_is_handed_back_rather_than_left_in_flight(): void
    {
        Queue::fake();

        $migration = $this->migration();
        $batch = $this->batchOf($migration, total: 2);

        Http::fake(function ($request) use ($migration) {
            if (str_contains($request->url(), 'graphql.json')) {
                return Http::response(['data' => ['productSet' => [
                    'product' => ['id' => 'gid://shopify/Product/1'],
                    'userErrors' => [],
                ]]], 200);
            }

            // The merchant presses Cancel while the page is being downloaded, so
            // the batch is already claimed and the loop is about to start.
            StoreMigration::where('id', $migration->id)->update(['status' => 'cancelled']);

            return Http::response([
                ['id' => 1, 'name' => 'One', 'type' => 'simple', 'status' => 'publish'],
                ['id' => 2, 'name' => 'Two', 'type' => 'simple', 'status' => 'publish'],
            ], 200);
        });

        (new ProcessBatchJob($batch->id))->handle(app(\App\Migration\MigratorRegistry::class));

        // Left at "processing" it counted against the shop's concurrency budget
        // forever, so a resumed run had no free slot and went nowhere.
        $this->assertSame('pending', $batch->refresh()->status);
    }

    // ------------------------------------------------------------- cache safety

    /** @test */
    public function a_cache_backend_that_refuses_the_page_does_not_fail_the_batch(): void
    {
        Queue::fake();

        $migration = $this->migration();
        $batch = $this->batchOf($migration);

        Http::fake(function ($request) {
            if (str_contains($request->url(), 'graphql.json')) {
                return Http::response(['data' => ['productSet' => [
                    'product' => ['id' => 'gid://shopify/Product/1'],
                    'userErrors' => [],
                ]]], 200);
            }

            return Http::response([['id' => 1, 'name' => 'One', 'type' => 'simple', 'status' => 'publish']], 200);
        });

        // Stands in for the database cache store rejecting an oversized blob
        // (max_allowed_packet), which is a live risk on shared hosting.
        Cache::shouldReceive('get')->andThrow(new \RuntimeException('cache down'));
        Cache::shouldReceive('put')->andThrow(new \RuntimeException('cache down'));
        Cache::shouldReceive('forget')->andThrow(new \RuntimeException('cache down'));
        Cache::shouldReceive('add')->andThrow(new \RuntimeException('cache down'));
        Cache::shouldReceive('increment')->andThrow(new \RuntimeException('cache down'));
        Cache::shouldReceive('remember')->andReturnUsing(fn ($k, $t, $cb) => $cb());
        Cache::shouldReceive('lock')->andThrow(new \RuntimeException('cache down'));

        (new ProcessBatchJob($batch->id))->handle(app(\App\Migration\MigratorRegistry::class));

        $this->assertSame('completed', $batch->refresh()->status);
    }
}
