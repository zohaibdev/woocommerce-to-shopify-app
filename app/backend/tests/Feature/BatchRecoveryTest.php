<?php

namespace Tests\Feature;

use App\Jobs\ProcessBatchJob;
use App\Jobs\RetryFailedItemsJob;
use App\Jobs\StartMigrationJob;
use App\Migration\MigratorRegistry;
use App\Models\MigrationBatch;
use App\Models\MigrationEntity;
use App\Models\MigrationItem;
use App\Models\MigrationLog;
use App\Models\Shop;
use App\Models\StoreMigration;
use App\Models\WoocommerceConnection;
use App\Support\SourceHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * What happens to a chunk of a large catalogue when things go wrong AROUND it,
 * rather than inside one record.
 *
 * The engine was good at surviving a bad record and bad at surviving a bad PAGE:
 * a chunk whose WooCommerce page could not be read produced no migration_items
 * at all, and so was invisible to the counters, to "retry failed items" and to
 * the downloadable report — a hundred products missing from the merchant's store
 * with every screen in the app reporting nothing wrong with them.
 *
 * These also cover the two things that make a 50k catalogue behave differently
 * from a 500 one: chunks addressed by id instead of by deep page offset, and a
 * watchdog that can see more than one stalled batch at a time.
 */
class BatchRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        SourceHealth::within(null);

        parent::tearDown();
    }

    private function migration(string $entity = 'product', int $total = 3): StoreMigration
    {
        $shop = Shop::create([
            'shopify_domain' => 'recovery.myshopify.com',
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
            'selected_entities' => [$entity],
            // Planning is a phase of its own now (see PlanBatchesJob), and
            // nothing may finalize a run while it is outstanding. A fixture that
            // hands out ready-made chunks is a run whose planning is done.
            'planned_at' => now(),
        ]);

        $migration->setRelation('shop', $shop);
        $migration->setRelation('connection', $conn);

        MigrationEntity::create([
            'migration_id' => $migration->id,
            'entity_type' => $entity,
            'status' => 'processing',
            'total_items' => $total,
        ]);

        return $migration;
    }

    private function batch(StoreMigration $migration, array $attributes = []): MigrationBatch
    {
        return MigrationBatch::create(array_merge([
            'migration_id' => $migration->id,
            'entity_type' => 'product',
            'batch_number' => 1,
            'page' => 1,
            'per_page' => 100,
            'processed_offset' => 0,
            'status' => 'pending',
        ], $attributes));
    }

    /** Shopify answers every productSet with a created product. */
    private function fakeShopify(array $wooPage): void
    {
        Http::fake(function ($request) use ($wooPage) {
            if (str_contains($request->url(), 'graphql.json')) {
                if (str_contains((string) data_get(json_decode($request->body(), true), 'query'), 'locations')) {
                    return Http::response(['data' => ['locations' => ['nodes' => [['id' => 'gid://shopify/Location/1']]]]], 200);
                }

                return Http::response([
                    'data' => ['productSet' => ['product' => ['id' => 'gid://shopify/Product/1'], 'userErrors' => []]],
                ], 200);
            }

            return Http::response($wooPage, 200);
        });
    }

    // ------------------------------------------------- unread chunks are visible

    /** @test */
    public function a_chunk_whose_page_could_not_be_read_is_retryable(): void
    {
        Queue::fake();

        $migration = $this->migration();

        // Failed with nothing read: the item loop never ran, so there are no
        // migration_items rows anywhere for the records it covered.
        $failed = $this->batch($migration, [
            'status' => 'failed',
            'error' => 'Fetch failed: cURL error 28: Operation timed out',
        ]);

        $this->assertSame(
            0,
            MigrationItem::where('migration_id', $migration->id)->count(),
            'precondition: an unread chunk leaves no item rows behind',
        );

        Http::fake();

        (new RetryFailedItemsJob($migration->id))->handle(app(MigratorRegistry::class));

        // The BATCH is the unit that gets retried, because nothing smaller exists.
        $this->assertSame('pending', $failed->fresh()->status);
        $this->assertNull($failed->fresh()->error);
        $this->assertSame(0, (int) $failed->fresh()->attempts, 'a merchant-initiated retry is a fresh start');

        $this->assertTrue(
            MigrationLog::where('migration_id', $migration->id)
                ->where('message', 'like', '%never read from WooCommerce%')
                ->exists(),
        );
    }

    /** @test */
    public function a_revived_chunk_resumes_rather_than_replaying_what_it_did(): void
    {
        Queue::fake();
        Http::fake();

        $migration = $this->migration();

        // Failed halfway: forty records already landed.
        $failed = $this->batch($migration, ['status' => 'failed', 'processed_offset' => 40]);

        (new RetryFailedItemsJob($migration->id))->handle(app(MigratorRegistry::class));

        $this->assertSame(40, (int) $failed->fresh()->processed_offset);
    }

    /** @test */
    public function the_retry_endpoint_is_offered_when_only_whole_chunks_failed(): void
    {
        Queue::fake();

        $migration = $this->migration();
        $this->batch($migration, ['status' => 'failed', 'error' => 'Fetch failed']);

        // Gating this on failed ITEMS refused the retry in exactly the case the
        // merchant had no other way out of.
        $response = $this->withHeaders(['X-Shop-Domain' => $migration->shop->shopify_domain])
            ->postJson("/api/migrations/{$migration->id}/retry-failed");

        $response->assertOk();
        $response->assertJsonPath('failed_batches', 1);

        Queue::assertPushed(RetryFailedItemsJob::class);
    }

    // ------------------------------------------------ chunks addressed by id

    /** @test */
    public function a_chunk_pins_its_source_ids_and_then_fetches_them_by_id(): void
    {
        Queue::fake();

        $migration = $this->migration(total: 2);
        $batch = $this->batch($migration);

        $this->fakeShopify([
            ['id' => 501, 'name' => 'One', 'type' => 'simple', 'status' => 'publish'],
            ['id' => 502, 'name' => 'Two', 'type' => 'simple', 'status' => 'publish'],
        ]);

        (new ProcessBatchJob($batch->id))->handle(app(MigratorRegistry::class));

        // The ids live on the row, so every later resume of this chunk is an
        // `include=` lookup rather than another deep OFFSET into WordPress.
        $this->assertSame([501, 502], $batch->fresh()->source_ids);

        Http::assertSent(fn ($r) => str_contains($r->url(), '_fields=id'));
        Http::assertSent(fn ($r) => str_contains($r->url(), 'include=501%2C502')
            || str_contains($r->url(), 'include=501,502'));
    }

    /** @test */
    public function a_record_deleted_mid_run_is_recorded_instead_of_shifting_the_page(): void
    {
        Queue::fake();

        $migration = $this->migration(total: 3);

        // Ids were pinned when the chunk was planned…
        $batch = $this->batch($migration, ['source_ids' => [601, 602, 603]]);

        // …and by the time it ran, 602 had been deleted in WooCommerce.
        $this->fakeShopify([
            ['id' => 601, 'name' => 'One', 'type' => 'simple', 'status' => 'publish'],
            ['id' => 603, 'name' => 'Three', 'type' => 'simple', 'status' => 'publish'],
        ]);

        (new ProcessBatchJob($batch->id))->handle(app(MigratorRegistry::class));

        // Under page/offset paging the deletion shifted 603 into 602's slot, and a
        // resume that had already passed that offset skipped it for good. Pinned
        // ids keep every record in its own slot.
        $this->assertSame('success', MigrationItem::where('woocommerce_id', 601)->value('status'));
        $this->assertSame('success', MigrationItem::where('woocommerce_id', 603)->value('status'));

        // The gap is recorded rather than left as a hole the counters can never fill.
        $gone = MigrationItem::where('woocommerce_id', 602)->first();
        $this->assertSame('skipped', $gone->status);
        $this->assertStringContainsString('No longer exists', $gone->error);

        $this->assertSame('completed', $batch->fresh()->status);
        $this->assertSame(3, (int) $batch->fresh()->processed_offset);
    }

    /** @test */
    public function a_source_that_cannot_be_listed_by_id_still_migrates_by_page(): void
    {
        Queue::fake();

        $migration = $this->migration(total: 1);
        $batch = $this->batch($migration);

        // A store that refuses the id listing (locked-down endpoint, a proxy that
        // mangles _fields) must fall back to paging, never read the refusal as an
        // empty page and skip records that are really there.
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'graphql.json')) {
                if (str_contains((string) data_get(json_decode($request->body(), true), 'query'), 'locations')) {
                    return Http::response(['data' => ['locations' => ['nodes' => [['id' => 'gid://shopify/Location/1']]]]], 200);
                }

                return Http::response([
                    'data' => ['productSet' => ['product' => ['id' => 'gid://shopify/Product/9'], 'userErrors' => []]],
                ], 200);
            }

            if (str_contains($request->url(), '_fields=id')) {
                return Http::response('forbidden', 403);
            }

            return Http::response([['id' => 700, 'name' => 'Fallback', 'type' => 'simple', 'status' => 'publish']], 200);
        });

        (new ProcessBatchJob($batch->id))->handle(app(MigratorRegistry::class));

        $this->assertNull($batch->fresh()->source_ids);
        $this->assertSame('success', MigrationItem::where('woocommerce_id', 700)->value('status'));
    }

    // ---------------------------------------------------------------- watchdog

    /** @test */
    public function the_watchdog_revives_every_stalled_chunk_not_just_the_oldest(): void
    {
        Queue::fake();

        $migration = $this->migration();

        // The oldest chunk is alive and working. Behind it, three more are held by
        // workers that died. The watchdog used to look only at the oldest, see a
        // healthy timestamp, and declare the whole migration fine.
        $healthy = $this->batch($migration, ['status' => 'processing', 'batch_number' => 1, 'page' => 1]);
        $healthy->forceFill(['updated_at' => now()])->save();

        $dead = collect(range(2, 4))->map(function (int $n) use ($migration) {
            $batch = $this->batch($migration, ['status' => 'processing', 'batch_number' => $n, 'page' => $n]);
            $batch->forceFill(['updated_at' => now()->subHour()])->save();

            return $batch;
        });

        $this->artisan('migration:watchdog')->assertSuccessful();

        $this->assertSame('processing', $healthy->fresh()->status, 'a working chunk is left alone');

        foreach ($dead as $batch) {
            $this->assertSame('pending', $batch->fresh()->status);
        }
    }

    /** @test */
    public function the_watchdog_leaves_queued_chunks_alone(): void
    {
        Queue::fake();

        $migration = $this->migration();

        // A run plans every chunk up front, so hundreds sit untouched for hours
        // waiting for a free concurrency slot. Those are queued, not stalled —
        // treating them as stalled rewrote the whole activity log every cycle.
        $working = $this->batch($migration, ['status' => 'processing', 'batch_number' => 1, 'page' => 1]);
        $working->forceFill(['updated_at' => now()])->save();

        $queued = $this->batch($migration, ['status' => 'pending', 'batch_number' => 2, 'page' => 2]);
        $queued->forceFill(['updated_at' => now()->subHour()])->save();

        $this->artisan('migration:watchdog')->assertSuccessful();

        $this->assertSame(
            0,
            MigrationLog::where('migration_id', $migration->id)->where('message', 'like', '%stalled%')->count(),
        );
    }

    /** @test */
    public function the_watchdog_restarts_a_run_whose_dispatches_were_all_lost(): void
    {
        Queue::fake();

        $migration = $this->migration();

        // Work outstanding, nothing holding any of it, nothing touched for ages:
        // a dispatch went missing and nothing would ever ask again.
        $orphan = $this->batch($migration, ['status' => 'pending']);
        $orphan->forceFill(['updated_at' => now()->subHour()])->save();

        $this->artisan('migration:watchdog')->assertSuccessful();

        Queue::assertPushed(ProcessBatchJob::class, fn ($job) => $job->batchId === $orphan->id);
    }

    // ----------------------------------------------------------------- planning

    /** @test */
    public function planning_is_given_longer_than_the_workers_default_timeout(): void
    {
        // Counting a large store is several WooCommerce round trips per entity,
        // and when the store hides X-WP-Total it becomes a page probe of ~20 more.
        // With no timeout of its own this job inherited the worker's 60s default,
        // was killed, retried three times and then marked the migration failed —
        // which the watchdog does not sweep. The biggest merchants were the ones
        // who could never start a migration at all.
        $timeout = (new StartMigrationJob(1))->timeout;

        $this->assertGreaterThan(60, $timeout);
        $this->assertLessThan(
            (int) config('queue.connections.database.retry_after'),
            $timeout,
            'above retry_after the queue starts a second copy of the planner',
        );
    }

    /** @test */
    public function a_chunk_with_no_planned_total_is_processed_rather_than_silently_skipped(): void
    {
        Queue::fake();

        $migration = $this->migration(total: 0);
        $batch = $this->batch($migration);

        $this->fakeShopify([['id' => 801, 'name' => 'Orphan', 'type' => 'simple', 'status' => 'publish']]);

        (new ProcessBatchJob($batch->id))->handle(app(MigratorRegistry::class));

        // The plan allowance is total_items minus what earlier pages used, so a
        // zero total sliced the chunk to nothing and marked it completed — a batch
        // that migrated not one record while reporting success.
        $this->assertSame('success', MigrationItem::where('woocommerce_id', 801)->value('status'));

        $this->assertTrue(
            MigrationLog::where('migration_id', $migration->id)
                ->where('message', 'like', '%no planned total%')
                ->exists(),
        );
    }
}
