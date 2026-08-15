<?php

namespace Tests\Feature;

use App\Jobs\FinalizeMigrationJob;
use App\Jobs\PlanBatchesJob;
use App\Jobs\ProcessBatchJob;
use App\Migration\MigrationRunner;
use App\Migration\MigratorRegistry;
use App\Models\MigrationBatch;
use App\Models\MigrationEntity;
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
 * Pinning every chunk's WooCommerce ids in ONE pass at the start of the run.
 *
 * A chunk used to be addressed by page number until its first job ran, and only
 * then pinned. Page/offset paging does not survive a DELETE: remove one record
 * and every later page shifts one place left, so the records on the boundary
 * between "already pinned" and "not pinned yet" are never fetched at all. No row
 * in migration_items, so invisible to the counters, to the failed list and to
 * "Retry failed items" — a run could deliver 19,950 of 20,000 products and
 * report success.
 *
 * The window for that was the whole run. Planning up front shrinks it to one
 * listing pass. What these tests protect is everything that had to change around
 * it: the run may not close while planning is outstanding, the planner has to be
 * resumable and revivable, and — the part that is easiest to get catastrophically
 * wrong — a store that will not answer an id listing must fall back rather than
 * be treated as empty.
 */
class PlanningPhaseTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        SourceHealth::within(null);

        parent::tearDown();
    }

    private function migration(int $total = 250, string $entity = 'product'): StoreMigration
    {
        $shop = Shop::create([
            'shopify_domain' => 'planning.myshopify.com',
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
            'started_at' => now(),
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

    /**
     * A store with `$total` products whose ids run 1..$total.
     *
     * @param  callable|null  $listIds  override what the id listing answers
     */
    private function fakeStore(int $total, ?callable $listIds = null): void
    {
        Http::fake(function ($request) use ($total, $listIds) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            // The id listing — this is what planning walks.
            if (($query['_fields'] ?? null) === 'id') {
                $page = (int) ($query['page'] ?? 1);
                $per = (int) ($query['per_page'] ?? 100);

                if ($listIds) {
                    return Http::response($listIds($page, $per), 200);
                }

                $from = ($page - 1) * $per + 1;
                $ids = [];

                for ($id = $from; $id < $from + $per && $id <= $total; $id++) {
                    $ids[] = ['id' => $id];
                }

                return Http::response($ids, 200);
            }

            return Http::response([], 200, ['X-WP-Total' => (string) $total]);
        });
    }

    private function plan(StoreMigration $migration): void
    {
        (new PlanBatchesJob($migration->id))->handle(app(MigratorRegistry::class));
    }

    // ------------------------------------------------------------- the pinning

    /** @test */
    public function every_chunk_is_pinned_to_concrete_ids_before_any_of_them_runs(): void
    {
        Queue::fake();

        $migration = $this->migration(total: 250);
        $this->fakeStore(250);

        $this->plan($migration);

        $batches = MigrationBatch::where('migration_id', $migration->id)->orderBy('page')->get();

        $this->assertCount(3, $batches, '250 records at 100 a chunk');

        // The point of the whole exercise: no chunk is left addressed by page
        // number, waiting to find out what it owns.
        foreach ($batches as $batch) {
            $this->assertIsArray($batch->source_ids);
            $this->assertNotEmpty($batch->source_ids);
        }

        $pinned = $batches->flatMap(fn ($b) => $b->source_ids)->all();

        $this->assertSame(range(1, 250), $pinned, 'every record exactly once, in order');
        $this->assertNotNull($migration->fresh()->planned_at);
    }

    /** @test */
    public function a_record_inserted_while_the_listing_runs_is_not_pinned_twice(): void
    {
        Queue::fake();

        $migration = $this->migration(total: 200);

        // Page 1 lists 1..100. Then a record is inserted low down, so page 2 —
        // which is an OFFSET, not a cursor — shifts right and repeats id 100.
        $this->fakeStore(200, function (int $page, int $per) {
            if ($page === 1) {
                return array_map(fn ($id) => ['id' => $id], range(1, 100));
            }

            return array_map(fn ($id) => ['id' => $id], range(100, 199));
        });

        $this->plan($migration);

        $pinned = MigrationBatch::where('migration_id', $migration->id)
            ->orderBy('page')->get()->flatMap(fn ($b) => $b->source_ids)->all();

        $this->assertSame(count($pinned), count(array_unique($pinned)), 'no record may be pinned to two chunks');
    }

    /** @test */
    public function planning_never_pins_more_than_the_plan_allows(): void
    {
        Queue::fake();

        // total_items is already capped by StartMigrationJob; planning must not
        // undo that by pinning a whole page.
        $migration = $this->migration(total: 10);
        $this->fakeStore(250);

        $this->plan($migration);

        $pinned = MigrationBatch::where('migration_id', $migration->id)
            ->get()->flatMap(fn ($b) => $b->source_ids)->all();

        $this->assertCount(10, $pinned);
    }

    // ---------------------------------------------------------- being resumable

    /** @test */
    public function a_planner_that_was_killed_resumes_instead_of_starting_the_catalogue_again(): void
    {
        Queue::fake();

        $migration = $this->migration(total: 250);
        $this->fakeStore(250);

        // Pretend the first pass got through one chunk and died.
        MigrationBatch::create([
            'migration_id' => $migration->id,
            'entity_type' => 'product',
            'batch_number' => 1,
            'page' => 1,
            'per_page' => 100,
            'source_ids' => range(1, 100),
            'processed_offset' => 0,
            'status' => 'pending',
        ]);

        MigrationEntity::where('migration_id', $migration->id)
            ->update(['planned_items' => 100, 'last_source_id' => 100]);

        $this->plan($migration);

        $batches = MigrationBatch::where('migration_id', $migration->id)->orderBy('page')->get();

        $this->assertCount(3, $batches, 'it carried on from chunk 2 rather than re-listing chunk 1');
        $this->assertSame(range(1, 100), $batches[0]->source_ids);
        $this->assertSame(250, (int) MigrationEntity::where('migration_id', $migration->id)->value('planned_items'));
    }

    // ------------------------------------------------ nothing finalizes too early

    /** @test */
    public function a_run_cannot_finish_while_it_is_still_being_planned(): void
    {
        Queue::fake();

        // The exact shape of the bug this guard exists for: the first entity's
        // chunks are all done, and the second entity has not been listed yet.
        $migration = $this->migration(total: 250);
        $migration->update(['planned_at' => null]);

        MigrationRunner::advance($migration->id);

        Queue::assertNotPushed(FinalizeMigrationJob::class);
    }

    /** @test */
    public function the_finalizer_refuses_a_half_planned_run_even_if_it_is_asked_directly(): void
    {
        $migration = $this->migration(total: 250);
        $migration->update(['planned_at' => null]);

        (new FinalizeMigrationJob($migration->id))->handle();

        $this->assertSame('processing', $migration->fresh()->status);
    }

    /** @test */
    public function a_fully_planned_run_with_no_chunks_left_does_finish(): void
    {
        Queue::fake();

        $migration = $this->migration(total: 250);
        $migration->update(['planned_at' => now()]);

        MigrationRunner::advance($migration->id);

        Queue::assertPushed(FinalizeMigrationJob::class);
    }

    /** @test */
    public function work_starts_before_the_whole_catalogue_has_been_listed(): void
    {
        Queue::fake();

        // Planning a 20,000-record store is 200 requests. Making the merchant
        // watch 0% for the whole of that would read as a broken migration, so
        // chunks are handed to the runner as they are created.
        $migration = $this->migration(total: 1000);
        $this->fakeStore(1000);

        $this->plan($migration);

        Queue::assertPushed(ProcessBatchJob::class);
    }

    // ------------------------------------------------------------ the fallbacks

    /** @test */
    public function a_store_that_will_not_list_ids_is_read_page_by_page_not_treated_as_empty(): void
    {
        Queue::fake();

        $migration = $this->migration(total: 250);

        // Answers the count but lists nothing — a security plugin, a cache, an
        // endpoint that ignores `_fields`. Believing the listing here would set
        // the total to zero and finish the entity in seconds with a green tick:
        // a migration that moved nothing and called it success.
        $this->fakeStore(250, fn () => []);

        $this->plan($migration);

        $entity = MigrationEntity::where('migration_id', $migration->id)->first();

        $this->assertSame(250, (int) $entity->total_items, 'the entity must not be zeroed');

        $batches = MigrationBatch::where('migration_id', $migration->id)->get();

        $this->assertCount(3, $batches, 'it falls back to page-addressed chunks');
        $this->assertNull($batches->first()->source_ids);

        $this->assertTrue(
            MigrationLog::where('migration_id', $migration->id)
                ->where('message', 'like', '%page by page%')
                ->exists(),
        );
    }

    /** @test */
    public function a_count_that_was_too_high_is_corrected_rather_than_left_unreachable(): void
    {
        Queue::fake();

        // The store said 250 and listed 120. Left alone the progress bar would
        // stop at 120/250 for ever with nothing to explain it.
        $migration = $this->migration(total: 250);
        $this->fakeStore(120);

        $this->plan($migration);

        $this->assertSame(120, (int) MigrationEntity::where('migration_id', $migration->id)->value('total_items'));
    }

    // -------------------------------------------------------------- the watchdog

    /** @test */
    public function the_watchdog_resumes_a_planner_that_died(): void
    {
        Queue::fake();

        // Nothing may finalize a run whose planning is outstanding, so a dead
        // planner freezes it — and unlike a stalled chunk there is no batch row
        // whose timestamp would give it away.
        $migration = $this->migration(total: 250);
        $migration->forceFill([
            'planned_at' => null,
            'updated_at' => now()->subHour(),
        ])->save();

        $this->artisan('migration:watchdog')->assertSuccessful();

        Queue::assertPushed(PlanBatchesJob::class, fn ($job) => $job->migrationId === $migration->id);
    }

    /** @test */
    public function the_watchdog_leaves_a_planner_that_is_still_working_alone(): void
    {
        Queue::fake();

        $migration = $this->migration(total: 250);
        $migration->forceFill(['planned_at' => null, 'updated_at' => now()])->save();

        $this->artisan('migration:watchdog')->assertSuccessful();

        Queue::assertNotPushed(PlanBatchesJob::class);
    }

    /** @test */
    public function a_planner_that_gives_up_lets_the_run_reach_an_end_state(): void
    {
        Queue::fake();

        // Better a run that migrates most of the catalogue and says what went
        // wrong than one that sits at "processing" for ever.
        $migration = $this->migration(total: 250);
        $migration->update(['planned_at' => null]);

        (new PlanBatchesJob($migration->id))->failed(new \RuntimeException('WooCommerce went away'));

        $this->assertNotNull($migration->fresh()->planned_at);
        $this->assertTrue(
            MigrationLog::where('migration_id', $migration->id)
                ->where('level', 'error')
                ->where('message', 'like', '%went away%')
                ->exists(),
        );
    }
}
