<?php

namespace Tests\Feature;

use App\Migration\MigrationRunner;
use App\Migration\MigratorRegistry;
use App\Jobs\ProcessBatchJob;
use App\Models\MigrationBatch;
use App\Models\MigrationEntity;
use App\Models\MigrationLog;
use App\Models\Shop;
use App\Models\StoreMigration;
use App\Models\WoocommerceConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * A correct migration that looks dead is still a broken migration. On production
 * the first numbers took minutes to appear: no entity rows existed until planning
 * finished, counters were only written once per 35-second chunk, and the merchant
 * had no log view at all. These lock in the feedback.
 */
class MigrationVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'shopify_domain' => 'visible.myshopify.com',
            'access_token' => 'shpat_test',
            'token_expires_at' => now()->addHour(),
        ]);

        WoocommerceConnection::create([
            'shop_id' => $this->shop->id,
            'store_url' => 'https://woo.example.com',
            'consumer_key' => 'ck',
            'consumer_secret' => 'cs',
            'status' => 'connected',
        ]);
    }

    public function test_entity_rows_exist_the_moment_the_migration_is_created(): void
    {
        Queue::fake();

        $response = $this->withHeader('X-Shop-Domain', $this->shop->shopify_domain)
            ->postJson('/api/migrations', ['entities' => ['product', 'category']])
            ->assertCreated();

        // The details page draws from this — an empty array is what made the run
        // look like it had hung before planning finished.
        $types = collect($response->json('entities'))->pluck('type')->sort()->values()->all();

        $this->assertSame(['category', 'product'], $types);
    }

    public function test_counters_are_written_after_every_item_not_just_at_the_end(): void
    {
        Queue::fake();

        // First category succeeds, the second gets throttled. A throttle returns
        // from the job WITHOUT running the end-of-batch sync — so if the first
        // item's success is on record, it can only have been written per item.
        $graphqlCalls = 0;
        Http::fake(function ($request) use (&$graphqlCalls) {
            if (str_contains($request->url(), '/wp-json/')) {
                return Http::response([
                    ['id' => 1, 'name' => 'One'],
                    ['id' => 2, 'name' => 'Two'],
                    ['id' => 3, 'name' => 'Three'],
                ], 200);
            }

            if (++$graphqlCalls > 1) {
                return Http::response('', 429, ['Retry-After' => '5']);
            }

            return Http::response([
                'data' => ['collectionCreate' => ['collection' => ['id' => 'gid://shopify/Collection/1'], 'userErrors' => []]],
            ], 200);
        });

        $migration = StoreMigration::create([
            'shop_id' => $this->shop->id,
            'woocommerce_connection_id' => $this->shop->woocommerceConnection->id,
            'status' => 'processing',
            'selected_entities' => ['category'],
        ]);
        $migration->setRelation('shop', $this->shop);
        $migration->setRelation('connection', $this->shop->woocommerceConnection);

        $entity = MigrationEntity::create([
            'migration_id' => $migration->id,
            'entity_type' => 'category',
            'status' => 'processing',
            'total_items' => 3,
        ]);

        $batch = MigrationBatch::create([
            'migration_id' => $migration->id,
            'entity_type' => 'category',
            'batch_number' => 1,
            'page' => 1,
            'per_page' => 100,
            'status' => 'pending',
        ]);

        (new ProcessBatchJob($batch->id))->handle(app(MigratorRegistry::class));

        $entity->refresh();

        // The batch never completed — it was re-queued for the throttle window —
        // yet the first item's progress is already visible to the merchant.
        //
        // It stays "processing" while it waits, deliberately. Releasing it to
        // "pending" made the chunk look free to inFlight(), so a concurrent
        // advance() handed the same chunk straight back out to a second job.
        $this->assertSame('processing', $batch->fresh()->status);
        $this->assertSame(1, $entity->successful_items);
        $this->assertSame(1, $entity->processed_items);
    }

    public function test_planning_writes_progress_the_merchant_can_read(): void
    {
        $migration = StoreMigration::create([
            'shop_id' => $this->shop->id,
            'woocommerce_connection_id' => $this->shop->woocommerceConnection->id,
            'status' => 'processing',
            'selected_entities' => ['category'],
        ]);

        MigrationRunner::log($migration->id, 'info', null, 'Reading your WooCommerce store…');
        MigrationRunner::log($migration->id, 'info', 'category', 'Found 112 category records — migrating 112.');

        $response = $this->withHeader('X-Shop-Domain', $this->shop->shopify_domain)
            ->getJson("/api/migrations/{$migration->id}/logs")
            ->assertOk();

        $messages = collect($response->json('data'))->pluck('message');

        $this->assertTrue($messages->contains('Reading your WooCommerce store…'));
        $this->assertTrue($messages->contains(fn ($m) => str_contains($m, 'Found 112')));
    }

    public function test_logs_are_scoped_to_the_owning_shop(): void
    {
        $other = Shop::create([
            'shopify_domain' => 'intruder.myshopify.com',
            'access_token' => 'shpat_other',
            'token_expires_at' => now()->addHour(),
        ]);

        $migration = StoreMigration::create([
            'shop_id' => $this->shop->id,
            'woocommerce_connection_id' => $this->shop->woocommerceConnection->id,
            'status' => 'processing',
            'selected_entities' => ['category'],
        ]);

        MigrationLog::create([
            'migration_id' => $migration->id,
            'level' => 'info',
            'message' => 'secret',
            'created_at' => now(),
        ]);

        // Multi-tenant rule: another shop must never read this migration's log.
        $this->withHeader('X-Shop-Domain', $other->shopify_domain)
            ->getJson("/api/migrations/{$migration->id}/logs")
            ->assertNotFound();
    }
}
