<?php

namespace Tests\Feature;

use App\Jobs\FinalizeMigrationJob;
use App\Jobs\RetryFailedItemsJob;
use App\Migration\MigratorRegistry;
use App\Models\MigrationEntity;
use App\Models\MigrationItem;
use App\Models\Shop;
use App\Models\StoreMigration;
use App\Models\WoocommerceConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * "Retry failed items" as a FINITE sweep.
 *
 * The job used to re-read every still-failed record from the top on each pass,
 * on the assumption that anything it had retried was no longer failed. That is
 * true only when the retry WORKED — and the runs that most need this button are
 * exactly the ones where it does not: Protected Customer Data not approved, an
 * expired token, a WooCommerce store that has gone away. There, every record
 * fails again, so the job re-dispatched itself for ever, re-attempting the same
 * first hundred records and never reaching the rest.
 *
 * It also loaded every failed row as a model in one go, which on the twenty
 * thousand failures that produce this situation is where the job died instead.
 *
 * So the sweep now carries a cursor. These tests are about that cursor, because
 * a job that re-dispatches itself looks identical from the outside whether it is
 * making progress or not.
 */
class RetrySweepTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): StoreMigration
    {
        $shop = Shop::create([
            'shopify_domain' => 'retry.myshopify.com',
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
            'selected_entities' => ['customer'],
        ]);

        MigrationEntity::create([
            'migration_id' => $migration->id,
            'entity_type' => 'customer',
            'status' => 'completed_with_errors',
            'total_items' => 3,
        ]);

        return $migration;
    }

    /** @return array<int, int> migration_items ids, in the order they were created */
    private function failedCustomers(StoreMigration $migration, array $wooIds): array
    {
        $ids = [];

        foreach ($wooIds as $wooId) {
            $ids[] = MigrationItem::create([
                'migration_id' => $migration->id,
                'shop_id' => $migration->shop_id,
                'entity_type' => 'customer',
                'woocommerce_id' => $wooId,
                'status' => 'failed',
                'error' => 'Shopify denied access',
            ])->id;
        }

        return $ids;
    }

    /** Every `include=` list WooCommerce was asked for, in order. */
    private function requestedIncludes(): array
    {
        $seen = [];

        foreach (Http::recorded() as [$request]) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            if (isset($query['include'])) {
                $seen[] = $query['include'];
            }
        }

        return $seen;
    }

    /** Shopify refuses everything, so every record fails again. */
    private function fakeDeniedStore(array $customers): void
    {
        Http::fake(function ($request) use ($customers) {
            if (str_contains($request->url(), 'graphql.json')) {
                return Http::response([
                    'errors' => [[
                        'message' => 'This app is not approved to access the Customer object.',
                        'extensions' => ['code' => 'ACCESS_DENIED'],
                    ]],
                ], 200);
            }

            return Http::response($customers, 200);
        });
    }

    /** @test */
    public function a_pass_resumes_past_its_cursor_instead_of_starting_over(): void
    {
        Queue::fake();

        $migration = $this->migration();
        $rowIds = $this->failedCustomers($migration, [101, 102, 103]);

        $this->fakeDeniedStore([
            ['id' => 102, 'email' => 'b@example.com'],
            ['id' => 103, 'email' => 'c@example.com'],
        ]);

        // Continuing a sweep that already dealt with the first record.
        (new RetryFailedItemsJob($migration->id, 'customer', $rowIds[0]))
            ->handle(app(MigratorRegistry::class));

        $includes = $this->requestedIncludes();

        $this->assertNotEmpty($includes);
        $this->assertStringNotContainsString('101', implode(',', $includes),
            'the record before the cursor was already dealt with and must not be attempted again');
        $this->assertStringContainsString('102', implode(',', $includes));
        $this->assertStringContainsString('103', implode(',', $includes));
    }

    /** @test */
    public function a_record_that_fails_again_is_not_attempted_twice_in_one_sweep(): void
    {
        Queue::fake();

        $migration = $this->migration();
        $this->failedCustomers($migration, [101, 102, 103]);

        $this->fakeDeniedStore([
            ['id' => 101, 'email' => 'a@example.com'],
            ['id' => 102, 'email' => 'b@example.com'],
            ['id' => 103, 'email' => 'c@example.com'],
        ]);

        (new RetryFailedItemsJob($migration->id))->handle(app(MigratorRegistry::class));

        // All three are still failed — Shopify refused them again.
        $this->assertSame(3, MigrationItem::where('migration_id', $migration->id)
            ->where('status', 'failed')->count());

        // …and the sweep still ENDED, rather than queueing itself to try the very
        // same three records for a second time. This is the whole bug: the old
        // job's exit condition was "nothing is failed any more", which a
        // systemic refusal never satisfies.
        Queue::assertPushed(FinalizeMigrationJob::class);
        Queue::assertNotPushed(RetryFailedItemsJob::class);
    }

    /** @test */
    public function a_sweep_with_nothing_left_to_do_hands_back_to_finalize(): void
    {
        Queue::fake();

        $migration = $this->migration();

        (new RetryFailedItemsJob($migration->id))->handle(app(MigratorRegistry::class));

        Queue::assertPushed(FinalizeMigrationJob::class);
        Queue::assertNotPushed(RetryFailedItemsJob::class);
    }

    /** @test */
    public function a_throttled_chunk_is_resumed_rather_than_stepped_over(): void
    {
        Queue::fake();

        $migration = $this->migration();
        $this->failedCustomers($migration, [101, 102, 103]);

        Http::fake(function ($request) {
            if (str_contains($request->url(), 'graphql.json')) {
                // Shopify's soft rate limit: an ordinary userError, not a 429.
                return Http::response([
                    'data' => ['customerCreate' => [
                        'customer' => null,
                        'userErrors' => [['field' => null, 'message' => 'Too many attempts. Please try again later.']],
                    ]],
                ], 200);
            }

            return Http::response([
                ['id' => 101, 'email' => 'a@example.com'],
                ['id' => 102, 'email' => 'b@example.com'],
                ['id' => 103, 'email' => 'c@example.com'],
            ], 200);
        });

        (new RetryFailedItemsJob($migration->id))->handle(app(MigratorRegistry::class));

        // Paused, not abandoned — and pointed back at the START of the chunk it
        // was in the middle of. Advancing the cursor here would have silently
        // dropped the rest of that chunk from the sweep.
        Queue::assertPushed(RetryFailedItemsJob::class, function (RetryFailedItemsJob $job) use ($migration) {
            return $job->migrationId === $migration->id
                && $job->resumeEntity === 'customer'
                && $job->afterId === 0
                && $job->throttleWaits === 1;
        });
    }

    /** @test */
    public function a_chunk_that_never_stops_being_throttled_is_eventually_swept_past(): void
    {
        Queue::fake();

        $migration = $this->migration();
        $rowIds = $this->failedCustomers($migration, [101, 102, 103]);

        Http::fake(function ($request) {
            if (str_contains($request->url(), 'graphql.json')) {
                return Http::response([
                    'data' => ['customerCreate' => [
                        'customer' => null,
                        'userErrors' => [['field' => null, 'message' => 'Too many attempts. Please try again later.']],
                    ]],
                ], 200);
            }

            return Http::response([['id' => 101, 'email' => 'a@example.com']], 200);
        });

        // Ten pauses already spent on this chunk. Throttling that never clears is
        // not throttling, and pausing forever is just a slower infinite loop.
        (new RetryFailedItemsJob($migration->id, 'customer', 0, 10))
            ->handle(app(MigratorRegistry::class));

        Queue::assertPushed(RetryFailedItemsJob::class, function (RetryFailedItemsJob $job) use ($rowIds) {
            return $job->afterId === $rowIds[2] && $job->throttleWaits === 0;
        });
    }
}
