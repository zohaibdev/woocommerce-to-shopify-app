<?php

namespace Tests\Feature;

use App\Jobs\FinalizeMigrationJob;
use App\Jobs\ProcessBatchJob;
use App\Migration\MigratorRegistry;
use App\Migration\Migrators\CategoryMigrator;
use App\Migration\Migrators\ProductMigrator;
use App\Models\MigrationBatch;
use App\Models\MigrationEntity;
use App\Models\MigrationItem;
use App\Models\MigrationLog;
use App\Models\Shop;
use App\Models\StoreMigration;
use App\Models\WoocommerceConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Regression cover for the production stall of migration #11, where a category
 * batch that ran longer than the queue's retry_after was started a second time
 * concurrently. That replay inflated the counters (100/112 processed while only
 * 24 collections existed), and once the retries ran out the Bus chain died,
 * so products and customers were never dispatched at all and the migration sat
 * at "processing" forever.
 *
 * The rules being locked in: a batch never stops the run, replaying a batch never
 * inflates the numbers, a missing image never fails the record, and the plan cap
 * holds at the item level, not just at planning time.
 */
class MigrationResilienceTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'shopify_domain' => 'resilience.myshopify.com',
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

    private function migration(array $entities = ['category']): StoreMigration
    {
        $migration = StoreMigration::create([
            'shop_id' => $this->shop->id,
            'woocommerce_connection_id' => $this->shop->woocommerceConnection->id,
            'status' => 'processing',
            'selected_entities' => $entities,
        ]);

        $migration->setRelation('shop', $this->shop);
        $migration->setRelation('connection', $this->shop->woocommerceConnection);

        return $migration;
    }

    private function batch(StoreMigration $migration, string $entityType, int $perPage = 100, int $number = 1): MigrationBatch
    {
        return MigrationBatch::create([
            'migration_id' => $migration->id,
            'entity_type' => $entityType,
            'batch_number' => $number,
            'page' => $number,
            'per_page' => $perPage,
            'status' => 'pending',
        ]);
    }

    /** @param array<int, array<string, mixed>> $categories */
    private function fakeWooCategories(array $categories): void
    {
        Http::fake([
            '*/wp-json/wc/v3/products/categories*' => Http::response($categories, 200, ['X-WP-Total' => (string) count($categories)]),
            '*/graphql.json' => Http::response([
                'data' => ['collectionCreate' => ['collection' => ['id' => 'gid://shopify/Collection/1'], 'userErrors' => []]],
            ], 200),
        ]);
    }

    // ---------------------------------------------------------------------
    // A broken batch must not take the migration down with it
    // ---------------------------------------------------------------------

    public function test_a_batch_that_gives_up_still_advances_the_migration(): void
    {
        Queue::fake();

        $migration = $this->migration(['category', 'product']);
        $first = $this->batch($migration, 'category');
        $second = $this->batch($migration, 'product', 100, 1);

        // The queue has exhausted every attempt on the first batch.
        (new ProcessBatchJob($first->id))->failed(new \RuntimeException('WooCommerce timed out'));

        $this->assertSame('failed', $first->fresh()->status);

        // The run continues with the next batch instead of freezing.
        Queue::assertPushed(ProcessBatchJob::class, fn (ProcessBatchJob $job) => $job->batchId === $second->id);

        $this->assertTrue(
            MigrationLog::where('migration_id', $migration->id)->where('level', 'error')->exists(),
            'the merchant must see why the batch was abandoned',
        );
    }

    public function test_the_last_batch_hands_over_to_the_finalizer(): void
    {
        Queue::fake();

        $migration = $this->migration();
        $only = $this->batch($migration, 'category');

        (new ProcessBatchJob($only->id))->failed(new \RuntimeException('boom'));

        Queue::assertPushed(FinalizeMigrationJob::class);
    }

    public function test_finalizer_closes_a_run_that_lost_its_worker(): void
    {
        $migration = $this->migration();
        $this->batch($migration, 'category')->update(['status' => 'failed']);

        MigrationEntity::create([
            'migration_id' => $migration->id,
            'entity_type' => 'category',
            'status' => 'processing',
            'total_items' => 1,
        ]);

        (new FinalizeMigrationJob($migration->id))->handle();

        // Never left hanging at "processing" — this was the production symptom.
        $this->assertSame('failed', $migration->fresh()->status);
        $this->assertNotNull($migration->fresh()->completed_at);
    }

    // ---------------------------------------------------------------------
    // Replaying a batch must not inflate the counters
    // ---------------------------------------------------------------------

    public function test_replaying_a_batch_does_not_double_count(): void
    {
        Queue::fake();

        // Two categories: one migrates, one fails outright (not a media error).
        Http::fake([
            '*/wp-json/wc/v3/products/categories*' => Http::response([
                ['id' => 10, 'name' => 'Good'],
                ['id' => 11, 'name' => 'Bad'],
            ], 200),
            '*/graphql.json' => Http::sequence()
                ->push(['data' => ['collectionCreate' => ['collection' => ['id' => 'gid://shopify/Collection/10'], 'userErrors' => []]]], 200)
                ->push(['data' => ['collectionCreate' => ['collection' => null, 'userErrors' => [['field' => 'title', 'message' => 'Title is invalid']]]]], 200)
                ->push(['data' => ['collectionCreate' => ['collection' => ['id' => 'gid://shopify/Collection/10'], 'userErrors' => []]]], 200)
                ->push(['data' => ['collectionCreate' => ['collection' => null, 'userErrors' => [['field' => 'title', 'message' => 'Title is invalid']]]]], 200),
        ]);

        $migration = $this->migration();
        $batch = $this->batch($migration, 'category');

        MigrationEntity::create([
            'migration_id' => $migration->id,
            'entity_type' => 'category',
            'status' => 'processing',
            'total_items' => 2,
        ]);

        $registry = app(MigratorRegistry::class);

        (new ProcessBatchJob($batch->id))->handle($registry);

        $entity = MigrationEntity::where('migration_id', $migration->id)->first();
        $this->assertSame(2, $entity->processed_items);
        $this->assertSame(1, $entity->successful_items);
        $this->assertSame(1, $entity->failed_items);

        // Simulate the old bug: the queue decides the job died and runs the whole
        // page again from the top.
        $batch->update(['status' => 'pending', 'processed_offset' => 0]);
        (new ProcessBatchJob($batch->id))->handle($registry);

        $entity->refresh();
        $this->assertSame(2, $entity->processed_items, 'a replay must not push processed past the total');
        $this->assertSame(1, $entity->successful_items);
        $this->assertSame(1, $entity->failed_items, 'the same failing item must not be counted twice');

        // And still exactly one mapping row per source category.
        $this->assertSame(2, MigrationItem::where('migration_id', $migration->id)->count());
    }

    public function test_a_resumed_batch_skips_what_it_already_did(): void
    {
        Queue::fake();
        $this->fakeWooCategories([
            ['id' => 21, 'name' => 'One'],
            ['id' => 22, 'name' => 'Two'],
            ['id' => 23, 'name' => 'Three'],
        ]);

        $migration = $this->migration();
        $batch = $this->batch($migration, 'category');
        $batch->update(['processed_offset' => 2]); // a killed worker got through two

        MigrationEntity::create([
            'migration_id' => $migration->id,
            'entity_type' => 'category',
            'status' => 'processing',
            'total_items' => 3,
        ]);

        (new ProcessBatchJob($batch->id))->handle(app(MigratorRegistry::class));

        // Only the third category was touched on this run.
        $this->assertSame([23], MigrationItem::where('migration_id', $migration->id)
            ->pluck('woocommerce_id')->all());
        $this->assertSame(3, $batch->fresh()->processed_offset);
    }

    // ---------------------------------------------------------------------
    // A missing image must never fail the record (SPECIFICATION.md §6.4)
    // ---------------------------------------------------------------------

    public function test_a_category_whose_image_is_gone_is_still_created(): void
    {
        $imageError = [['field' => null, 'message' => 'Image upload failed. Image https://woo.example.com/wp-content/uploads/Bagpack.jpg failed to download. - file not found. Check that file can be found at your URL.']];

        Http::fake([
            '*/graphql.json' => Http::sequence()
                ->push(['data' => ['collectionCreate' => ['collection' => null, 'userErrors' => $imageError]]], 200)
                ->push(['data' => ['collectionCreate' => ['collection' => ['id' => 'gid://shopify/Collection/77'], 'userErrors' => []]]], 200),
        ]);

        $migration = $this->migration();

        $result = (new CategoryMigrator())->migrateItem($migration, [
            'id' => 242,
            'name' => 'Bags',
            'image' => ['src' => 'https://woo.example.com/wp-content/uploads/Bagpack.jpg'],
        ]);

        $this->assertSame('success', $result->status, 'a deleted image must not fail the whole category');
        $this->assertSame('gid://shopify/Collection/77', $result->shopifyId);
        $this->assertNotNull($result->warning);

        // The retry must have dropped the image rather than sending it again.
        Http::assertSent(fn ($req) => data_get(json_decode($req->body(), true), 'variables.input.image') === null);
    }

    public function test_a_product_whose_images_are_gone_is_still_created(): void
    {
        $imageError = [['field' => null, 'message' => 'Image upload failed. Image https://woo.example.com/wp-content/uploads/x.jpg failed to download. - file not found.']];

        // Keyed off the query rather than a fixed sequence: the migrator memoises
        // the location lookup statically, so whether that call happens at all
        // depends on what ran before this test.
        $attempts = 0;
        Http::fake(function ($request) use (&$attempts, $imageError) {
            if (str_contains((string) data_get(json_decode($request->body(), true), 'query'), 'locations')) {
                return Http::response(['data' => ['locations' => ['nodes' => [['id' => 'gid://shopify/Location/1']]]]], 200);
            }

            return Http::response(['data' => ['productSet' => ++$attempts === 1
                ? ['product' => null, 'userErrors' => $imageError]
                : ['product' => ['id' => 'gid://shopify/Product/88'], 'userErrors' => []]]], 200);
        });

        $migration = $this->migration(['product']);

        $result = (new ProductMigrator())->migrateItem($migration, [
            'id' => 500,
            'name' => 'Widget',
            'status' => 'publish',
            'images' => [['src' => 'https://woo.example.com/wp-content/uploads/x.jpg', 'alt' => 'x']],
        ]);

        $this->assertSame('success', $result->status, 'a deleted image must not fail the whole product');
        $this->assertSame('gid://shopify/Product/88', $result->shopifyId);
        $this->assertNotNull($result->warning);

        Http::assertSent(function ($req) {
            $body = json_decode($req->body(), true);

            return str_contains((string) ($body['query'] ?? ''), 'productSet')
                && data_get($body, 'variables.input.files') === null;
        });
    }

    public function test_a_real_error_still_fails_the_item(): void
    {
        Http::fake([
            '*/graphql.json' => Http::response([
                'data' => ['collectionCreate' => ['collection' => null, 'userErrors' => [['field' => 'title', 'message' => 'Title can not be blank']]]],
            ], 200),
        ]);

        $result = (new CategoryMigrator())->migrateItem($this->migration(), ['id' => 300, 'name' => '']);

        $this->assertSame('failed', $result->status, 'only media errors are forgiven');
    }

    // ---------------------------------------------------------------------
    // The plan cap has to hold at the item level too
    // ---------------------------------------------------------------------

    public function test_a_batch_migrates_no_more_items_than_the_plan_allows(): void
    {
        Queue::fake();

        // WooCommerce hands back a full page even though the plan allows only 3.
        $this->fakeWooCategories(collect(range(1, 10))
            ->map(fn (int $i) => ['id' => 100 + $i, 'name' => "Cat {$i}"])->all());

        $migration = $this->migration();
        $batch = $this->batch($migration, 'category', 100);

        MigrationEntity::create([
            'migration_id' => $migration->id,
            'entity_type' => 'category',
            'status' => 'processing',
            'total_items' => 3, // capped by the plan at planning time
        ]);

        (new ProcessBatchJob($batch->id))->handle(app(MigratorRegistry::class));

        $this->assertSame(3, MigrationItem::where('migration_id', $migration->id)->count(),
            'the page returned 10 but only the 3 the plan allows may be written');
    }
}
