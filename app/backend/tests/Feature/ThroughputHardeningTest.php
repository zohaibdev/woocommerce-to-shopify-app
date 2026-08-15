<?php

namespace Tests\Feature;

use App\Jobs\FinalizeMigrationJob;
use App\Migration\ImageSizeResolver;
use App\Migration\Migrators\CategoryMigrator;
use App\Migration\Migrators\CustomerMigrator;
use App\Migration\Migrators\ProductMigrator;
use App\Models\MigrationBatch;
use App\Models\MigrationEntity;
use App\Models\MigrationItem;
use App\Models\MigrationLog;
use App\Models\Shop;
use App\Models\StoreMigration;
use App\Models\WoocommerceConnection;
use App\Support\QueueKeeper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * How FAST a big migration goes, and whether it tells the truth when it does not.
 *
 * Correctness at scale is covered by ScaleHardeningTest and BatchRecoveryTest.
 * This suite is about the other half — the things that made a twenty-thousand
 * record run take days instead of hours, or finish quietly wrong. Every one of
 * them was silent: nothing failed, nothing errored, the run simply crawled or
 * came up short and nothing in the app said why.
 */
class ThroughputHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function shop(string $domain = 'throughput.myshopify.com'): Shop
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
            // Planning is a phase of its own now (see PlanBatchesJob), and
            // nothing may finalize a run while it is outstanding. A fixture that
            // hands out ready-made chunks is a run whose planning is done.
            'planned_at' => now(),
        ]);

        $migration->setRelation('shop', $shop);
        $migration->setRelation('connection', $conn);

        return $migration;
    }

    /**
     * Mapping rows for records that really did migrate.
     *
     * Not optional detail: FinalizeMigrationJob re-derives every counter from
     * migration_items before it decides anything, so an entity row with
     * hand-written counters and no mapping rows behind it is not the state any
     * real run reaches.
     */
    private function migratedItems(StoreMigration $migration, int $count): void
    {
        foreach (range(1, max(0, $count)) as $n) {
            MigrationItem::create([
                'migration_id' => $migration->id,
                'shop_id' => $migration->shop_id,
                'entity_type' => 'product',
                'woocommerce_id' => $n,
                'shopify_id' => "gid://shopify/Product/{$n}",
                'status' => 'success',
            ]);
        }
    }

    private function queueJobs(int $count): void
    {
        foreach (range(1, $count) as $ignored) {
            DB::table('jobs')->insert([
                'queue' => 'default',
                'payload' => json_encode(['displayName' => 'Fake']),
                'attempts' => 0,
                'reserved_at' => null,
                'available_at' => time() - 300,
                'created_at' => time() - 300,
            ]);
        }
    }

    // ------------------------------------------------------------ worker pool

    /** @test */
    public function the_self_starter_runs_a_pool_rather_than_a_single_worker(): void
    {
        // The engine works out from the shop's real Shopify budget that it may
        // run several batches at once and dispatches that many jobs. Starting one
        // worker made that decorative — the jobs simply queued behind each other,
        // which on a 20k catalogue is the difference between an afternoon and
        // overnight.
        config(['queue.auto_worker' => true, 'queue.default' => 'database', 'queue.auto_workers' => 4]);
        $this->queueJobs(10);

        if (! QueueKeeper::canSpawn()) {
            $this->markTestSkipped('proc_open is disabled on this host');
        }

        $this->assertSame(4, QueueKeeper::targetWorkers());

        $this->assertTrue(QueueKeeper::ensureRunning(), 'the first poll fills every free slot');

        // Every slot is leased for the length of a worker's life, so the polls
        // arriving three seconds later must not stack a second pool on top.
        $this->assertFalse(QueueKeeper::ensureRunning(), 'the slot leases must hold within the window');
    }

    /** @test */
    public function no_more_workers_are_started_than_there_is_work_for(): void
    {
        // One stray job does not need four PHP processes racing for it — that is
        // pure cost on a host where processes are metered.
        config(['queue.auto_worker' => true, 'queue.default' => 'database', 'queue.auto_workers' => 8]);
        $this->queueJobs(2);

        $this->assertSame(2, QueueKeeper::targetWorkers());
    }

    /** @test */
    public function the_worker_pool_can_still_be_switched_off_for_supervisor_hosts(): void
    {
        config(['queue.auto_worker' => false, 'queue.default' => 'database', 'queue.auto_workers' => 8]);
        $this->queueJobs(10);

        $this->assertFalse(QueueKeeper::ensureRunning());
    }

    /** @test */
    public function the_keepalive_command_reports_what_it_did(): void
    {
        config(['queue.auto_worker' => false, 'queue.default' => 'database']);

        // Runs every minute in production, so it must never throw and must say
        // something an operator reading a cron log can act on.
        $this->artisan('queue:keepalive')
            ->expectsOutputToContain('supervisor')
            ->assertSuccessful();
    }

    // ------------------------------------------- shopify refusing the request

    /** @test */
    public function a_denied_shopify_scope_is_named_instead_of_reported_as_no_id(): void
    {
        // ACCESS_DENIED arrives in the top-level `errors`, not in productSet's
        // `userErrors`, and leaves the mutation payload empty. Reading only the
        // userErrors meant every record in the run failed with "No id returned by
        // Shopify" — a sentence naming neither the cause nor the fix, on a
        // problem (Protected Customer Data approval, a missing scope) that is
        // entirely fixable once you know it is the problem.
        Http::fake(fn () => Http::response([
            'errors' => [[
                'message' => 'Access denied for productSet field. Required access: `write_products` access scope.',
                'extensions' => ['code' => 'ACCESS_DENIED'],
            ]],
        ], 200));

        $migration = $this->migration();

        $result = (new ProductMigrator)->migrateItem($migration, [
            'id' => 11,
            'name' => 'Denied',
            'type' => 'simple',
            'status' => 'publish',
        ]);

        $this->assertSame('failed', $result->status);
        $this->assertStringContainsString('Shopify denied access', (string) $result->error);
        $this->assertStringContainsString('write_products', (string) $result->error);
        $this->assertStringNotContainsString('No id returned', (string) $result->error);
    }

    /** @test */
    public function a_refused_request_is_not_retried_by_shedding_fields(): void
    {
        // Shedding exists for data Shopify objects to. A refusal is not that, and
        // trying three more times turns one clear "no" into four API calls per
        // record — 80,000 wasted calls on a 20k catalogue that was never going to
        // migrate until the scope was granted.
        Http::fake(fn () => Http::response([
            'errors' => [[
                'message' => 'Access denied for productSet field.',
                'extensions' => ['code' => 'ACCESS_DENIED'],
            ]],
        ], 200));

        $migration = $this->migration();

        (new ProductMigrator)->migrateItem($migration, [
            'id' => 12,
            'name' => 'Denied',
            'type' => 'simple',
            'status' => 'publish',
        ]);

        Http::assertSentCount(1);
    }

    /** @test */
    public function a_refused_collection_update_does_not_fall_through_to_a_duplicate(): void
    {
        // collectionUpdate answering with nothing used to be read as "that
        // collection is gone" and retried as a CREATE. A token that lost a scope
        // mid-run answers every update with nothing, so a re-run would have given
        // the merchant a second copy of every collection they had.
        Http::fake(fn () => Http::response([
            'errors' => [[
                'message' => 'Access denied for collectionUpdate field.',
                'extensions' => ['code' => 'ACCESS_DENIED'],
            ]],
        ], 200));

        $migration = $this->migration();

        $result = (new CategoryMigrator)->migrateItem($migration, ['id' => 5, 'name' => 'Shoes']);

        $this->assertSame('failed', $result->status);
        $this->assertStringContainsString('Shopify denied access', (string) $result->error);
        Http::assertSentCount(1);
    }

    /** @test */
    public function protected_customer_data_denial_is_explained_rather_than_shed_field_by_field(): void
    {
        // The app's actual go-live blocker. Until PCD approval lands, Shopify
        // denies every customer and every order at the top level — so this is the
        // first thing a merchant hits, and it used to arrive as raw JSON after
        // three retries that dropped the phone, then the addresses, then the
        // tags, none of which had anything to do with it.
        Http::fake(fn () => Http::response([
            'errors' => [[
                'message' => 'This app is not approved to access the Customer object.',
                'extensions' => ['code' => 'ACCESS_DENIED'],
            ]],
        ], 200));

        $migration = $this->migration();

        $result = (new CustomerMigrator)->migrateItem($migration, [
            'id' => 21,
            'email' => 'buyer@example.com',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
        ]);

        $this->assertSame('failed', $result->status);
        $this->assertStringContainsString('Protected Customer Data', (string) $result->error);
        Http::assertSentCount(1);
    }

    // ------------------------------------------------------------ image sizes

    /** @test */
    public function an_image_with_no_generated_sizes_is_only_asked_about_once(): void
    {
        // WordPress answers, but the attachment has no scaled copies — an SVG, an
        // externally hosted file, an attachment whose metadata was lost. That id
        // used to be left out of the size table entirely, so the very next
        // lookup asked about it again. prefetchVariationImages() runs once per
        // product, which made that one wasted request per variable product:
        // twenty thousand of them against the merchant's own host on a 20k run.
        Http::fake(fn () => Http::response([
            ['id' => 77, 'media_details' => ['sizes' => []]],
        ], 200));

        $migration = $this->migration(options: ['image_size' => 'standard']);
        $resolver = new ImageSizeResolver;

        $resolver->prefetch($migration, [77]);
        $resolver->prefetch($migration, [77]);
        $resolver->prefetch($migration, [77]);

        Http::assertSentCount(1);

        // And it still ships the original, which is the whole point of asking.
        $this->assertSame(
            'https://woo.example.com/original.jpg',
            $resolver->url($migration, 77, 'https://woo.example.com/original.jpg'),
        );
    }

    /** @test */
    public function a_smaller_generated_copy_is_still_preferred_when_there_is_one(): void
    {
        Http::fake(fn () => Http::response([[
            'id' => 88,
            'media_details' => ['sizes' => [
                'medium' => ['width' => 300, 'source_url' => 'https://woo.example.com/x-300.jpg'],
                'large' => ['width' => 1024, 'source_url' => 'https://woo.example.com/x-1024.jpg'],
                'huge' => ['width' => 3000, 'source_url' => 'https://woo.example.com/x-3000.jpg'],
            ]],
        ]], 200));

        $migration = $this->migration(options: ['image_size' => 'standard']);
        $resolver = new ImageSizeResolver;

        $resolver->prefetch($migration, [88]);

        $this->assertSame(
            'https://woo.example.com/x-1024.jpg',
            $resolver->url($migration, 88, 'https://woo.example.com/x-3000.jpg'),
        );
    }

    // ------------------------------------------------------------ reconciling

    /** @test */
    public function a_run_that_came_up_short_says_so_instead_of_finishing_quietly(): void
    {
        // A chunk is addressed by page number until the first time it runs. On a
        // run that takes hours, a record deleted at the source shifts every
        // not-yet-pinned page one place left, and a few records fall through the
        // gap between one chunk and the next. They have no row in
        // migration_items, so they are invisible to the counters, to the failed
        // list and to "Retry failed items" — the run delivered 8 of 10 products
        // and called itself finished.
        $migration = $this->migration();

        MigrationEntity::create([
            'migration_id' => $migration->id,
            'entity_type' => 'product',
            'status' => 'completed',
            'total_items' => 10,
        ]);

        // Eight products have mapping rows. The other two were never fetched at
        // all, so nothing anywhere records that they exist.
        $this->migratedItems($migration, 8);

        (new FinalizeMigrationJob($migration->id))->handle();

        $this->assertTrue(
            MigrationLog::where('migration_id', $migration->id)
                ->where('level', 'warning')
                ->where('message', 'like', '%never read from WooCommerce%')
                ->exists(),
            'a shortfall nothing else explains has to be said out loud',
        );
    }

    /** @test */
    public function a_shortfall_already_explained_by_an_unread_chunk_is_not_reported_twice(): void
    {
        // A batch that failed as a whole is already reported as `failed_batches`,
        // with its own banner and its own retry button. Saying it again in
        // different words would send the merchant looking for a second problem.
        $migration = $this->migration();

        MigrationEntity::create([
            'migration_id' => $migration->id,
            'entity_type' => 'product',
            'status' => 'completed_with_errors',
            'total_items' => 10,
        ]);

        $this->migratedItems($migration, 8);

        MigrationBatch::create([
            'migration_id' => $migration->id,
            'entity_type' => 'product',
            'batch_number' => 2,
            'page' => 2,
            'per_page' => 100,
            'processed_offset' => 0,
            'status' => 'failed',
            'error' => 'Fetch failed',
        ]);

        (new FinalizeMigrationJob($migration->id))->handle();

        $this->assertFalse(
            MigrationLog::where('migration_id', $migration->id)
                ->where('message', 'like', '%never read from WooCommerce%')
                ->exists(),
        );
    }

    /** @test */
    public function a_run_that_migrated_everything_it_planned_stays_quiet(): void
    {
        $migration = $this->migration();

        MigrationEntity::create([
            'migration_id' => $migration->id,
            'entity_type' => 'product',
            'status' => 'completed',
            'total_items' => 10,
        ]);

        $this->migratedItems($migration, 10);

        (new FinalizeMigrationJob($migration->id))->handle();

        $this->assertSame('completed', $migration->fresh()->status);
        $this->assertFalse(
            MigrationLog::where('migration_id', $migration->id)
                ->where('level', 'warning')
                ->exists(),
        );
    }

    // ---------------------------------------------------- queue configuration

    /** @test */
    public function every_queue_connection_waits_longer_than_the_longest_job(): void
    {
        // `retry_after` is how long the queue waits before deciding a running job
        // is dead and handing it to somebody else. Below a job's own timeout that
        // is not a safety net, it is a SECOND WORKER on the same batch: the page
        // replays, every item is written to Shopify twice, and once the retries
        // run out the migration freezes at "processing" with no error. That is
        // the worst bug this engine has had, and it was a config default.
        //
        // Locked for every driver, not just the one in use, because moving to
        // Redis is something you do on the day you go to a VPS — and Laravel's
        // stock Redis default is 90 seconds, which is shorter than a single
        // batch. Nothing in the app would have told you.
        $longestJobTimeout = max(
            (new \App\Jobs\StartMigrationJob(1))->timeout,
            (new \App\Jobs\ProcessBatchJob(1))->timeout,
            (new \App\Jobs\RetryFailedItemsJob(1))->timeout,
            (new FinalizeMigrationJob(1))->timeout,
        );

        foreach (['database', 'redis'] as $driver) {
            $retryAfter = (int) config("queue.connections.{$driver}.retry_after");

            $this->assertGreaterThan(
                $longestJobTimeout,
                $retryAfter,
                "queue.connections.{$driver}.retry_after ({$retryAfter}s) must exceed the longest job "
                    ."timeout ({$longestJobTimeout}s), or the queue will run a second copy of a live batch.",
            );
        }
    }

    /** @test */
    public function the_queue_depth_is_read_from_whichever_driver_is_configured(): void
    {
        // QueueKeeper::pending() used to count rows in the `jobs` table and
        // return 0 for anything else. On Redis that table still exists and is
        // permanently empty, so the admin panel showed "0 jobs waiting" with a
        // backlog behind it, and the self-starter saw nothing worth a worker.
        config(['queue.default' => 'database']);
        $this->queueJobs(3);

        $this->assertSame(3, QueueKeeper::pending());

        // An unreachable driver reads as "nothing to do" rather than throwing —
        // this runs in terminate() on every API request.
        config(['queue.default' => 'nonexistent-driver']);

        $this->assertSame(0, QueueKeeper::pending());
    }
}
