<?php

namespace Tests\Feature;

use App\Jobs\ProcessBatchJob;
use App\Jobs\RetryFailedItemsJob;
use App\Migration\MigratorRegistry;
use App\Models\MigrationBatch;
use App\Models\MigrationEntity;
use App\Models\MigrationItem;
use App\Models\Shop;
use App\Models\StoreMigration;
use App\Models\WoocommerceConnection;
use App\Services\Exceptions\ShopifyThrottledException;
use App\Services\ShopifyClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Shopify rate limits some mutations — orderCreate above all — SEPARATELY from
 * the GraphQL cost budget, and reports it as a plain userError rather than a 429
 * or a THROTTLED code.
 *
 * Undetected, that read as a permanent failure: migration #20 landed exactly five
 * orders per attempt and marked the other 30 failed, three times over. It is
 * throttling, and must pause the batch instead of burning the records.
 */
class SoftThrottleTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'shopify_domain' => 'throttle.myshopify.com',
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

    private function migration(): StoreMigration
    {
        $migration = StoreMigration::create([
            'shop_id' => $this->shop->id,
            'woocommerce_connection_id' => $this->shop->woocommerceConnection->id,
            'status' => 'processing',
            'selected_entities' => ['order'],
        ]);

        $migration->setRelation('shop', $this->shop);
        $migration->setRelation('connection', $this->shop->woocommerceConnection);

        return $migration;
    }

    public function test_a_too_many_attempts_user_error_is_treated_as_throttling(): void
    {
        Http::fake([
            '*/graphql.json' => Http::response([
                'data' => ['orderCreate' => ['order' => null, 'userErrors' => [
                    ['field' => null, 'message' => 'Too many attempts. Please try again later.'],
                ]]],
            ], 200),
        ]);

        $this->expectException(ShopifyThrottledException::class);

        (new ShopifyClient($this->shop))->graphql('mutation { orderCreate { order { id } } }');
    }

    public function test_an_ordinary_user_error_is_not_mistaken_for_throttling(): void
    {
        Http::fake([
            '*/graphql.json' => Http::response([
                'data' => ['orderCreate' => ['order' => null, 'userErrors' => [
                    ['field' => ['email'], 'message' => 'Email is invalid'],
                ]]],
            ], 200),
        ]);

        $body = (new ShopifyClient($this->shop))->graphql('mutation { orderCreate { order { id } } }');

        $this->assertSame('Email is invalid', $body['data']['orderCreate']['userErrors'][0]['message']);
    }

    public function test_a_throttled_batch_is_re_queued_instead_of_failing_the_orders(): void
    {
        Queue::fake();

        Http::fake([
            '*/wp-json/wc/v3/orders*' => Http::response([
                ['id' => 1, 'currency' => 'USD', 'status' => 'completed', 'date_created' => '2024-01-01T10:00:00',
                    'billing' => ['email' => 'a@example.com'], 'line_items' => [['name' => 'X', 'quantity' => 1, 'price' => '5.00']]],
                ['id' => 2, 'currency' => 'USD', 'status' => 'completed', 'date_created' => '2024-01-01T10:00:00',
                    'billing' => ['email' => 'b@example.com'], 'line_items' => [['name' => 'Y', 'quantity' => 1, 'price' => '5.00']]],
            ], 200),
            '*/graphql.json' => Http::sequence()
                ->push(['data' => ['orderCreate' => ['order' => ['id' => 'gid://shopify/Order/1'], 'userErrors' => []]]], 200)
                ->push(['data' => ['orderCreate' => ['order' => null, 'userErrors' => [
                    ['field' => null, 'message' => 'Too many attempts. Please try again later.'],
                ]]]], 200),
        ]);

        $migration = $this->migration();

        MigrationEntity::create([
            'migration_id' => $migration->id,
            'entity_type' => 'order',
            'status' => 'processing',
            'total_items' => 2,
        ]);

        $batch = MigrationBatch::create([
            'migration_id' => $migration->id,
            'entity_type' => 'order',
            'batch_number' => 1,
            'page' => 1,
            'per_page' => 100,
            'status' => 'pending',
        ]);

        (new ProcessBatchJob($batch->id))->handle(app(MigratorRegistry::class));

        // The first order landed; the throttled one is NOT recorded as failed.
        $this->assertSame(1, MigrationItem::where('migration_id', $migration->id)->where('status', 'success')->count());
        $this->assertSame(0, MigrationItem::where('migration_id', $migration->id)->where('status', 'failed')->count());

        // The batch waits and resumes rather than ending.
        $this->assertSame('pending', $batch->fresh()->status);
        Queue::assertPushed(ProcessBatchJob::class, fn ($job) => $job->batchId === $batch->id);
    }

    public function test_a_throttled_retry_run_also_waits_instead_of_failing(): void
    {
        Queue::fake();

        Http::fake([
            '*/wp-json/wc/v3/orders*' => Http::response([
                ['id' => 9, 'currency' => 'USD', 'status' => 'completed', 'date_created' => '2024-01-01T10:00:00',
                    'billing' => ['email' => 'c@example.com'], 'line_items' => [['name' => 'Z', 'quantity' => 1, 'price' => '5.00']]],
            ], 200),
            '*/graphql.json' => Http::response([
                'data' => ['orderCreate' => ['order' => null, 'userErrors' => [
                    ['field' => null, 'message' => 'Too many attempts. Please try again later.'],
                ]]],
            ], 200),
        ]);

        $migration = $this->migration();

        MigrationEntity::create([
            'migration_id' => $migration->id,
            'entity_type' => 'order',
            'status' => 'completed_with_errors',
            'total_items' => 1,
        ]);

        MigrationItem::create([
            'migration_id' => $migration->id,
            'shop_id' => $this->shop->id,
            'entity_type' => 'order',
            'woocommerce_id' => 9,
            'status' => 'failed',
            'error' => 'Too many attempts. Please try again later.',
        ]);

        (new RetryFailedItemsJob($migration->id))->handle(app(MigratorRegistry::class));

        // Still failed (nothing changed), but the run is queued to try again —
        // it does not burn the retry and finish.
        Queue::assertPushed(RetryFailedItemsJob::class);
    }

    public function test_the_batch_job_survives_a_slow_record(): void
    {
        // The time budget is only checked BETWEEN items, so the hard timeout has to
        // leave room for one long record. At Laravel's 60s default a single heavy
        // product killed the whole batch.
        $job = new ProcessBatchJob(1);

        $this->assertGreaterThan(60, $job->timeout);
        $this->assertLessThan(
            (int) config('queue.connections.database.retry_after'),
            $job->timeout,
            'a job must be killed before the queue hands it to a second worker',
        );
    }
}
