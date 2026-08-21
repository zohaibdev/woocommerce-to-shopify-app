<?php

namespace Tests\Feature;

use App\Jobs\ProcessBatchJob;
use App\Migration\MigrationRunner;
use App\Migration\MigratorRegistry;
use App\Models\AdminUser;
use App\Models\MigrationBatch;
use App\Models\MigrationEntity;
use App\Models\MigrationLog;
use App\Models\Shop;
use App\Models\StoreMigration;
use App\Models\WoocommerceConnection;
use App\Support\MigrationDiagnostics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Why a run is slow, and the machinery that answers it.
 *
 * These are the two halves of the same production problem. An order migration
 * delivering four records a minute, with three worker processes busy out of
 * eight, produced no signal anywhere in the app that distinguished "Shopify is
 * pacing us" from "the merchant's host is slow" from "only two workers are
 * ALLOWED to run orders" — so the covering tests are about the honesty of the
 * diagnosis as much as about the speed-ups:
 *
 *  - a soft throttle (Shopify's own orderCreate limit, reported as a plain
 *    userError with no timing) probes early instead of sleeping a flat minute,
 *  - a wait Shopify actually specified is honoured exactly rather than second
 *    guessed,
 *  - the short probes do not spend the give-up budget, which would turn a slow
 *    store into a failed one,
 *  - the per-entity ceiling that explains the idle workers is configuration
 *    rather than a constant, and
 *  - every job leaves a debug line naming its worker and where its time went,
 *    which the merchant never sees and the operator always can.
 */
class MigrationDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'shopify_domain' => 'diag.myshopify.com',
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

    private function migration(array $entities = ['order']): StoreMigration
    {
        $migration = StoreMigration::create([
            'shop_id' => $this->shop->id,
            'woocommerce_connection_id' => $this->shop->woocommerceConnection->id,
            'status' => 'processing',
            'selected_entities' => $entities,
            'started_at' => now()->subMinutes(10),
        ]);

        $migration->setRelation('shop', $this->shop);
        $migration->setRelation('connection', $this->shop->woocommerceConnection);

        return $migration;
    }

    /** One order in WooCommerce, and a Shopify that refuses it as rate limited. */
    private function throttledOrderRun(): array
    {
        Http::fake([
            '*/wp-json/wc/v3/orders*' => Http::response([
                [
                    'id' => 1, 'currency' => 'USD', 'status' => 'completed',
                    'date_created' => '2024-01-01T10:00:00',
                    'billing' => ['email' => 'a@example.com'],
                    'line_items' => [['name' => 'X', 'quantity' => 1, 'price' => '5.00']],
                ],
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
            'status' => 'processing',
            'total_items' => 1,
        ]);

        $batch = MigrationBatch::create([
            'migration_id' => $migration->id,
            'entity_type' => 'order',
            'batch_number' => 1,
            'page' => 1,
            'per_page' => 100,
            'status' => 'pending',
        ]);

        return [$migration, $batch];
    }

    // -----------------------------------------------------------------
    // Speed
    // -----------------------------------------------------------------

    public function test_a_soft_throttle_probes_early_instead_of_sleeping_a_full_minute(): void
    {
        Queue::fake();
        config(['migration.soft_throttle_backoff' => [5, 10, 20, 40, 60]]);

        [, $batch] = $this->throttledOrderRun();

        (new ProcessBatchJob($batch->id))->handle(app(MigratorRegistry::class));

        // Shopify sends NO timing with this refusal, so the wait is our guess and
        // the first guess must be cheap. A flat 60s here meant a batch that
        // managed ~5 orders between refusals spent most of every minute holding a
        // concurrency slot and doing nothing.
        Queue::assertPushed(ProcessBatchJob::class, function (ProcessBatchJob $job) {
            return $job->batchId !== null && $job->delay === 5;
        });
    }

    public function test_the_probe_backs_off_once_it_has_been_refused(): void
    {
        Queue::fake();
        config(['migration.soft_throttle_backoff' => [5, 10, 20, 40, 60]]);

        [, $batch] = $this->throttledOrderRun();

        // Four runs of the same chunk, none of which migrates anything: the wait
        // has to climb, or a store whose window really is a minute would be
        // hammered every five seconds.
        $delays = [];

        foreach (range(1, 4) as $_) {
            (new ProcessBatchJob($batch->id, (int) $batch->fresh()->attempts))
                ->handle(app(MigratorRegistry::class));
        }

        Queue::pushed(ProcessBatchJob::class)->each(function (ProcessBatchJob $job) use (&$delays) {
            $delays[] = $job->delay;
        });

        $this->assertSame([5, 10, 20, 40], $delays, 'the backoff must escalate, not repeat');
    }

    public function test_progress_resets_the_backoff(): void
    {
        Queue::fake();
        config(['migration.soft_throttle_backoff' => [5, 10, 20, 40, 60]]);

        Http::fake([
            '*/wp-json/wc/v3/orders*' => Http::response([
                ['id' => 1, 'currency' => 'USD', 'status' => 'completed', 'date_created' => '2024-01-01T10:00:00',
                    'billing' => ['email' => 'a@example.com'],
                    'line_items' => [['name' => 'X', 'quantity' => 1, 'price' => '5.00']]],
                ['id' => 2, 'currency' => 'USD', 'status' => 'completed', 'date_created' => '2024-01-01T10:00:00',
                    'billing' => ['email' => 'b@example.com'],
                    'line_items' => [['name' => 'Y', 'quantity' => 1, 'price' => '5.00']]],
            ], 200),
            '*/graphql.json' => Http::sequence()
                // Refused, then one lands, then refused again.
                ->push(['data' => ['orderCreate' => ['order' => null, 'userErrors' => [
                    ['field' => null, 'message' => 'Too many attempts. Please try again later.'],
                ]]]], 200)
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
        (new ProcessBatchJob($batch->id, (int) $batch->fresh()->attempts))->handle(app(MigratorRegistry::class));

        $delays = [];
        Queue::pushed(ProcessBatchJob::class)->each(function (ProcessBatchJob $j) use (&$delays) {
            $delays[] = $j->delay;
        });

        // A busy shop legitimately throttles over and over. Escalating through
        // pauses that DID migrate something would slow a healthy run to a crawl
        // for no reason, so only stalled pauses count.
        $this->assertSame([5, 5], $delays);
    }

    public function test_short_probes_do_not_spend_the_give_up_budget(): void
    {
        Queue::fake();
        config(['migration.soft_throttle_backoff' => [5, 10, 20, 40, 60]]);

        [$migration, $batch] = $this->throttledOrderRun();

        // Five stalled waits is the whole ladder. If the ramp were spent out of
        // the give-up budget rather than added to it, a store with a genuine
        // sixty-second window would have its chunk abandoned within a couple of
        // minutes of trying — turning slow into failed.
        foreach (range(1, 5) as $_) {
            (new ProcessBatchJob($batch->id, (int) $batch->fresh()->attempts))
                ->handle(app(MigratorRegistry::class));
        }

        $this->assertSame('processing', $batch->fresh()->status);
        $this->assertSame(
            0,
            MigrationLog::where('migration_id', $migration->id)->where('level', 'error')->count(),
        );
    }

    public function test_a_wait_shopify_specified_is_honoured_exactly(): void
    {
        Queue::fake();
        config(['migration.soft_throttle_backoff' => [5, 10, 20, 40, 60]]);

        Http::fake([
            '*/wp-json/wc/v3/orders*' => Http::response([
                ['id' => 1, 'currency' => 'USD', 'status' => 'completed', 'date_created' => '2024-01-01T10:00:00',
                    'billing' => ['email' => 'a@example.com'],
                    'line_items' => [['name' => 'X', 'quantity' => 1, 'price' => '5.00']]],
            ], 200),
            // A real 429 with Shopify's own Retry-After. Our ladder must not
            // second-guess a number the API actually gave us.
            '*/graphql.json' => Http::response('', 429, ['Retry-After' => '17']),
        ]);

        $migration = $this->migration();

        MigrationEntity::create([
            'migration_id' => $migration->id,
            'entity_type' => 'order',
            'status' => 'processing',
            'total_items' => 1,
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

        Queue::assertPushed(ProcessBatchJob::class, fn (ProcessBatchJob $job) => $job->delay === 17);
    }

    public function test_the_per_entity_concurrency_ceiling_is_configurable(): void
    {
        // Every worker beyond this one has nothing it is allowed to pick up for
        // orders, which is the entire explanation for "8 workers running, 2
        // busy" — and until now it was welded into the class, so finding the
        // right number for a store cost a deploy.
        $this->shop->forceFill(['api_restore_rate' => 1000, 'api_bucket_size' => 2000])->save();

        $migration = $this->migration();

        config(['migration.entity_concurrency' => ['order' => 2]]);
        $this->assertSame(2, MigrationRunner::concurrencyFor($migration, 'order'));

        config(['migration.entity_concurrency' => ['order' => 5]]);
        $this->assertSame(5, MigrationRunner::concurrencyFor($migration, 'order'));

        // An entity with no ceiling of its own is still bounded by the shop's
        // real Shopify budget — lifting the order cap must not lift everything.
        config(['migration.entity_concurrency' => []]);
        $this->assertLessThanOrEqual(
            (int) config('migration.max_concurrency'),
            MigrationRunner::concurrencyFor($migration, 'product'),
        );
    }

    // -----------------------------------------------------------------
    // The developer log
    // -----------------------------------------------------------------

    public function test_every_job_leaves_a_line_naming_its_worker_and_its_time(): void
    {
        Queue::fake();

        [$migration, $batch] = $this->throttledOrderRun();

        (new ProcessBatchJob($batch->id))->handle(app(MigratorRegistry::class));

        $debug = MigrationLog::where('migration_id', $migration->id)->where('level', 'debug')->first();

        $this->assertNotNull($debug, 'a job that owned a chunk must account for itself');
        $this->assertSame(MigrationDiagnostics::worker(), $debug->context['worker']);
        $this->assertSame('paused on throttle', $debug->context['outcome']);
        $this->assertArrayHasKey('elapsed_seconds', $debug->context);
        // The calls are what turn "slow" into "slow BECAUSE" — one WooCommerce
        // read for the chunk, one Shopify write that was refused.
        $this->assertGreaterThan(0, $debug->context['woo_calls']);
        $this->assertGreaterThan(0, $debug->context['shopify_calls']);
        $this->assertSame(1, $debug->context['throttle_waits']);
    }

    public function test_debug_lines_are_never_shown_to_the_merchant(): void
    {
        [$migration] = $this->throttledOrderRun();

        MigrationRunner::log($migration->id, 'debug', 'order', 'batch 1 completed in 4s', ['worker' => 'x:1']);
        MigrationRunner::log($migration->id, 'info', 'order', 'Migrated 100 orders.');

        $response = $this->withHeaders(['X-Shop-Domain' => $this->shop->shopify_domain])
            ->getJson("/api/migrations/{$migration->id}/logs");

        $response->assertOk();

        $levels = collect($response->json('data'))->pluck('level')->all();

        // Same table, opposite audiences. A merchant reading "batch 1 completed
        // in 4s · woo 1×11.4s" learns nothing and loses the lines written for
        // them.
        $this->assertNotContains('debug', $levels);
        $this->assertContains('info', $levels);
    }

    public function test_the_developer_endpoint_rolls_the_job_lines_up(): void
    {
        [$migration] = $this->throttledOrderRun();

        foreach ([['shopify_calls' => 3, 'shopify_seconds' => 6.0, 'woo_calls' => 1, 'woo_seconds' => 30.0],
            ['shopify_calls' => 2, 'shopify_seconds' => 4.0, 'woo_calls' => 1, 'woo_seconds' => 20.0]] as $i => $context) {
            MigrationRunner::log($migration->id, 'debug', 'order', "batch {$i}", $context + [
                'worker' => 'host:'.(100 + $i),
                'outcome' => 'chunk completed',
                'elapsed_seconds' => 40.0,
                'records' => 5,
            ]);
        }

        $response = $this->withAdmin()->getJson("/api/admin/migrations/{$migration->id}/debug");

        $response->assertOk();

        $this->assertSame(5, $response->json('totals.shopify_calls'));
        // assertEquals, not assertSame: json_encode drops the fractional part of
        // a whole float (50.0 goes out as `50`), so the seconds totals arrive as
        // int or float depending only on whether they happened to be round.
        $this->assertEquals(50, $response->json('totals.woo_seconds'));
        // Two distinct processes touched the run. This is the number an operator
        // is trying to reconcile against the workers they believe are running.
        $this->assertCount(2, $response->json('workers'));
        $this->assertSame(2, $response->json('outcomes.chunk completed'));
    }

    public function test_the_developer_log_download_is_admin_only_and_says_what_matters(): void
    {
        [$migration] = $this->throttledOrderRun();

        MigrationRunner::log($migration->id, 'debug', 'order', 'batch 1', [
            'worker' => 'host:1',
            'outcome' => 'paused on throttle',
            'elapsed_seconds' => 60.0,
            'throttle_waits' => 1,
            'throttle_seconds' => 55.0,
        ]);

        $this->getJson("/api/admin/migrations/{$migration->id}/debug-log")->assertStatus(401);

        $body = $this->withAdmin()
            ->get("/api/admin/migrations/{$migration->id}/debug-log")
            ->assertOk()
            ->streamedContent();

        foreach ([
            'SHOP SHIFT — DEVELOPER LOG',
            'SHOPIFY LIMITS & CONCURRENCY',
            'WHERE THE TIME WENT',
            'WORKERS THAT TOUCHED THIS RUN',
            'host:1',
        ] as $expected) {
            $this->assertStringContainsString($expected, $body);
        }

        // The report is only worth opening if it draws the conclusion. 55 of 60
        // seconds asleep is the finding, not the raw pair of numbers.
        $this->assertStringContainsString('ASLEEP waiting out', $body);
    }

    private function withAdmin(): self
    {
        $token = 'test-admin-token';

        AdminUser::create([
            'name' => 'Ops',
            'email' => 'ops@example.com',
            'password' => bcrypt('secret'),
            'api_token_hash' => hash('sha256', $token),
        ]);

        return $this->withHeaders(['Authorization' => "Bearer {$token}"]);
    }
}
