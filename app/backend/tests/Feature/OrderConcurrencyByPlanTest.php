<?php

namespace Tests\Feature;

use App\Jobs\ProcessBatchJob;
use App\Migration\MigrationRunner;
use App\Migration\MigratorRegistry;
use App\Models\MigrationBatch;
use App\Models\MigrationEntity;
use App\Models\MigrationItem;
use App\Models\Shop;
use App\Models\StoreMigration;
use App\Models\WoocommerceConnection;
use App\Services\ShopifyPlan;
use App\Support\MigrationEstimate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Order concurrency has to follow the merchant's Shopify plan.
 *
 * It used to be ONE number for the whole installation
 * (MIGRATION_ORDER_CONCURRENCY, default 2), so a Plus store paying for ten times
 * the API budget migrated orders at exactly Basic speed while its other six
 * workers sat idle.
 *
 * The numbers below are NOT a claim that Shopify's order limit officially scales
 * with plan — Shopify does not document that limit at all. They are permission
 * to TRY, and the safety net is the throttle handling underneath: a refusal
 * pauses the batch, backs off, and resumes, so a tier allowed more than Shopify
 * will actually give it simply settles back down. The last two tests are the
 * ones that keep that net in place.
 */
class OrderConcurrencyByPlanTest extends TestCase
{
    use RefreshDatabase;

    /** Domains must differ per call — several tests build more than one shop. */
    private int $seq = 0;

    /**
     * Shopify's documented GraphQL restore rates, which is what tier() reads.
     * Basic and Grow are both 100/s — Shopify gives them the same budget, so
     * they resolve to the same tier by construction rather than by a second
     * lookup table.
     */
    private const RESTORE_RATES = [
        'basic' => 100,
        'grow' => 100,
        'advanced' => 200,
        'plus' => 1000,
        'enterprise' => 2000,
    ];

    private function shopOn(string $plan): Shop
    {
        $shop = Shop::create([
            'shopify_domain' => 'orders-'.$plan.'-'.(++$this->seq).'.myshopify.com',
            'access_token' => 'shpat_x',
            'token_expires_at' => now()->addHour(),
        ]);

        $shop->forceFill([
            'api_restore_rate' => self::RESTORE_RATES[$plan],
            'api_bucket_size' => self::RESTORE_RATES[$plan] * 20,
            'limits_checked_at' => now(),
        ])->save();

        return $shop;
    }

    private function migrationOn(string $plan): StoreMigration
    {
        $shop = $this->shopOn($plan);

        $connection = WoocommerceConnection::create([
            'shop_id' => $shop->id,
            'store_url' => 'https://woo.example.com',
            'consumer_key' => 'ck',
            'consumer_secret' => 'cs',
            'status' => 'connected',
        ]);

        $migration = StoreMigration::create([
            'shop_id' => $shop->id,
            'woocommerce_connection_id' => $connection->id,
            'status' => 'processing',
            'selected_entities' => ['order'],
        ]);

        $migration->setRelation('shop', $shop);

        return $migration;
    }

    // ------------------------------------------------------- the ceiling table

    public static function planExpectations(): array
    {
        return [
            'Basic' => ['basic', 2],
            'Grow' => ['grow', 2],
            'Advanced' => ['advanced', 3],
            'Plus' => ['plus', 6],
            'Enterprise' => ['enterprise', 8],
        ];
    }

    /**
     * @dataProvider planExpectations
     */
    public function test_each_plan_gets_its_own_order_ceiling(string $plan, int $expected): void
    {
        // MIGRATION_MAX_CONCURRENCY has to be out of the way, or it would cap the
        // higher tiers before their own ceiling ever applied and the test would
        // be measuring the wrong limit.
        config(['migration.max_concurrency' => 16]);

        $shop = $this->shopOn($plan);

        $this->assertSame(
            $expected,
            app(ShopifyPlan::class)->entityCeiling($shop, 'order'),
            "{$plan} should allow {$expected} concurrent order batches",
        );
    }

    public function test_a_plus_store_really_does_run_orders_wider_than_a_basic_one(): void
    {
        // The whole point of the change, asserted end to end through the runner
        // rather than against the lookup table.
        config(['migration.max_concurrency' => 16]);

        $basic = MigrationRunner::concurrencyFor($this->migrationOn('basic'), 'order');
        $plus = MigrationRunner::concurrencyFor($this->migrationOn('plus'), 'order');

        $this->assertSame(2, $basic);
        $this->assertSame(6, $plus);
        $this->assertGreaterThan($basic, $plus);
    }

    // ------------------------------------------------------- the calculation

    public function test_the_ceiling_is_still_the_minimum_with_the_plan_derived_workers(): void
    {
        // A Plus store is entitled to 6 order batches, but if the installation
        // only permits 4 workers in total then 4 is the honest answer. The
        // per-entity ceiling raises the order limit; it must never raise the
        // shop-wide one.
        config(['migration.max_concurrency' => 4]);

        $migration = $this->migrationOn('plus');

        $derived = app(ShopifyPlan::class)->concurrencyFor($migration->shop);

        $this->assertSame(4, $derived);
        $this->assertSame(4, MigrationRunner::concurrencyFor($migration, 'order'));
    }

    public function test_source_heavy_entities_keep_their_own_bound(): void
    {
        // Products, categories and customers are bounded by the SOURCE-side
        // default, not by the order ceiling and not by the absolute maximum.
        //
        // This is the guard on the fix for the order knob. Making that knob work
        // meant raising migration.max_concurrency, and on its own that would have
        // lifted products from 8 to 12 on every Advanced/Plus/Enterprise store —
        // 50% more concurrent reads against a merchant's WordPress host, which is
        // the exact load that bound exists to prevent. The `default` entity
        // ceiling is what holds them still.
        $source = (int) config('migration.entity_concurrency.default');

        foreach (['plus', 'enterprise', 'advanced'] as $plan) {
            $migration = $this->migrationOn($plan);

            foreach (['product', 'customer', 'category'] as $entity) {
                $ceiling = MigrationRunner::concurrencyFor($migration, $entity);

                $this->assertSame(
                    $source,
                    $ceiling,
                    "{$entity} on {$plan} must sit at the source bound",
                );
            }
        }
    }

    public function test_products_do_not_follow_the_order_ceiling(): void
    {
        // Asserted on Plus specifically, where the two numbers genuinely differ
        // (orders 6, source bound 8). Enterprise happens to have both at 8, so
        // it cannot tell the two apart and proves nothing here.
        $migration = $this->migrationOn('plus');

        $this->assertSame(6, MigrationRunner::concurrencyFor($migration, 'order'));
        $this->assertSame(8, MigrationRunner::concurrencyFor($migration, 'product'));
    }

    public function test_a_small_shop_is_still_bounded_by_its_api_budget_not_the_source_default(): void
    {
        // Basic derives 6 from its own Shopify budget, which is below the source
        // default of 8 — so the budget is what limits it, and the default must
        // not be mistaken for a floor.
        $migration = $this->migrationOn('basic');

        $this->assertSame(6, app(ShopifyPlan::class)->concurrencyFor($migration->shop));
        $this->assertSame(6, MigrationRunner::concurrencyFor($migration, 'product'));
    }

    public function test_raising_a_tier_ceiling_above_the_source_bound_actually_takes_effect(): void
    {
        // The defect this fix exists for. The order ceiling and the absolute
        // maximum used to be the same number (8), so an operator who set the
        // enterprise ceiling to 12 got 8 — the knob was inert and said nothing
        // about it.
        config(['migration.entity_concurrency.order.enterprise' => 12]);

        $migration = $this->migrationOn('enterprise');

        $this->assertSame(12, MigrationRunner::concurrencyFor($migration, 'order'));

        // And products are unmoved by it, because they read a different key.
        $this->assertSame(
            (int) config('migration.entity_concurrency.default'),
            MigrationRunner::concurrencyFor($migration, 'product'),
        );
    }

    public function test_the_absolute_maximum_is_still_the_last_word(): void
    {
        // Headroom is not the absence of a limit. Whatever a tier ceiling says,
        // migration.max_concurrency still caps it — that is what an operator
        // reaches for when a host genuinely cannot take the load.
        config([
            'migration.max_concurrency' => 4,
            'migration.entity_concurrency.order.enterprise' => 12,
        ]);

        $this->assertSame(4, MigrationRunner::concurrencyFor($this->migrationOn('enterprise'), 'order'));
    }

    public function test_an_unmeasured_shop_falls_to_the_most_conservative_tier(): void
    {
        // First run: nothing learned from a response yet, so tier() reads
        // "standard". Guessing high for a shop we cannot identify is the one
        // direction that costs throttle errors.
        $shop = Shop::create([
            'shopify_domain' => 'orders-unmeasured.myshopify.com',
            'access_token' => 'shpat_x',
            'token_expires_at' => now()->addHour(),
        ]);

        $this->assertSame(2, app(ShopifyPlan::class)->entityCeiling($shop, 'order'));
    }

    public function test_an_unknown_tier_takes_the_lowest_configured_value(): void
    {
        config(['migration.entity_concurrency' => ['order' => [
            'standard' => 2,
            'plus' => 6,
        ]]]);

        // Not the first entry, the SMALLEST one — a tier we cannot place must not
        // inherit whichever number happens to be declared first.
        $this->assertSame(2, ShopifyPlan::entityCeilingForTier('something-new', 'order'));
    }

    public function test_a_flat_number_is_still_honoured(): void
    {
        // Backward compatibility: an installation (or an older test) that pins one
        // number keeps working exactly as it did.
        config(['migration.entity_concurrency' => ['order' => 5]]);

        $this->assertSame(5, ShopifyPlan::entityCeilingForTier('plus', 'order'));
        $this->assertSame(5, ShopifyPlan::entityCeilingForTier('standard', 'order'));
    }

    // ------------------------------------------------------------ consistency

    public function test_the_time_estimate_uses_the_same_ceiling_as_the_runner(): void
    {
        // These are two different code paths reading the same limit, and they
        // disagreeing is how the app promises a speed the run cannot reach. The
        // estimate reads a per-tier MAP now, so a raw (int) cast of it would
        // silently evaluate to 1 and price the order phase as fully serial.
        config(['migration.max_concurrency' => 16]);

        foreach (['basic' => 2, 'advanced' => 3, 'plus' => 6, 'enterprise' => 8] as $plan => $expected) {
            $migration = $this->migrationOn($plan);
            $tier = app(ShopifyPlan::class)->tier($migration->shop);

            $this->assertSame($expected, MigrationEstimate::workersFor('order', 16, $tier));
            $this->assertSame(
                MigrationRunner::concurrencyFor($migration, 'order'),
                MigrationEstimate::workersFor('order', 16, $tier),
                "the estimate and the runner disagree about {$plan}",
            );
        }
    }

    // ---------------------------------------------------------- the safety net

    public function test_the_throttle_backoff_is_untouched(): void
    {
        // Raising the ceiling is only safe because this still exists: a tier
        // allowed more than Shopify will give it gets refused, pauses, backs off
        // along this ladder and resumes. Removing or flattening it would turn a
        // higher ceiling from an experiment into a way to burn records.
        $this->assertSame([5, 10, 20, 40, 60], config('migration.soft_throttle_backoff'));
    }

    public function test_a_plus_store_dispatches_more_order_batches_than_a_basic_one(): void
    {
        // The ceiling is only real if the RUNNER hands out that many chunks, so
        // this drives advance() rather than the lookup table.
        Queue::fake();
        config(['migration.max_concurrency' => 16]);

        $basic = $this->migrationOn('basic');
        $plus = $this->migrationOn('plus');

        $this->batches($basic, 10);
        $this->batches($plus, 10);

        MigrationRunner::advance($basic->id);
        $basicDispatched = Queue::pushed(ProcessBatchJob::class)->count();

        MigrationRunner::advance($plus->id);
        $plusDispatched = Queue::pushed(ProcessBatchJob::class)->count() - $basicDispatched;

        $this->assertSame(2, $basicDispatched);
        $this->assertSame(6, $plusDispatched);
    }

    public function test_a_throttled_plus_store_settles_instead_of_burning_orders(): void
    {
        // The safety net doing its job. Plus is now allowed 6 concurrent order
        // batches; if Shopify's real limit is lower it refuses, and the batch has
        // to PAUSE and resume rather than record the orders as failed. This is
        // what makes raising the ceiling an experiment rather than a risk.
        Queue::fake();

        Http::fake([
            '*/wp-json/wc/v3/orders*' => Http::response([
                ['id' => 1, 'currency' => 'USD', 'status' => 'completed', 'date_created' => '2024-01-01T10:00:00',
                    'billing' => ['email' => 'a@example.com'],
                    'line_items' => [['name' => 'X', 'quantity' => 1, 'price' => '5.00']]],
            ], 200),
            '*/graphql.json' => Http::response([
                'data' => ['orderCreate' => ['order' => null, 'userErrors' => [
                    ['field' => null, 'message' => 'Too many attempts. Please try again later.'],
                ]]],
            ], 200),
        ]);

        $migration = $this->migrationOn('plus');
        $migration->setRelation('connection', $migration->connection()->first());

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

        // Not failed — paused, still holding the chunk, queued to try again.
        $this->assertSame(0, MigrationItem::where('migration_id', $migration->id)
            ->where('status', 'failed')->count());
        $this->assertSame('processing', $batch->fresh()->status);
        Queue::assertPushed(ProcessBatchJob::class);
    }

    public function test_a_wider_ceiling_cannot_produce_duplicate_orders(): void
    {
        // Six workers on one entity makes the exclusivity guarantee load-bearing
        // in a way two never did. A chunk is claimed with a conditional UPDATE, so
        // however many jobs race for it exactly one may proceed.
        config(['migration.max_concurrency' => 16]);

        $migration = $this->migrationOn('plus');

        $batch = MigrationBatch::create([
            'migration_id' => $migration->id,
            'entity_type' => 'order',
            'batch_number' => 1,
            'page' => 1,
            'per_page' => 100,
            'status' => 'pending',
        ]);

        $claim = function () use ($batch) {
            return MigrationBatch::where('id', $batch->id)
                ->where('status', 'pending')
                ->update(['status' => 'processing']) === 1;
        };

        $this->assertTrue($claim(), 'the first worker takes the chunk');
        $this->assertFalse($claim(), 'every other worker must be refused it');
    }

    /** Pending order chunks for a migration, so advance() has something to hand out. */
    private function batches(StoreMigration $migration, int $count): void
    {
        foreach (range(1, $count) as $n) {
            MigrationBatch::create([
                'migration_id' => $migration->id,
                'entity_type' => 'order',
                'batch_number' => $n,
                'page' => $n,
                'per_page' => 100,
                'status' => 'pending',
            ]);
        }
    }

    public function test_order_concurrency_is_no_longer_one_global_number(): void
    {
        // The regression this whole change exists to prevent. Two shops on
        // different plans, one installation, one config — different answers.
        config(['migration.max_concurrency' => 16]);

        $answers = collect(['basic', 'grow', 'advanced', 'plus', 'enterprise'])
            ->map(fn ($plan) => MigrationRunner::concurrencyFor($this->migrationOn($plan), 'order'))
            ->unique();

        $this->assertGreaterThan(1, $answers->count(), 'every plan still gets the same number');
    }
}
