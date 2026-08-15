<?php

namespace Tests\Feature;

use App\Models\MigrationEntity;
use App\Models\Shop;
use App\Models\StoreMigration;
use App\Models\WoocommerceConnection;
use App\Support\MigrationEstimate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "How long will this take?" — the question every merchant asks and the app had
 * no answer to.
 *
 * A run of any size takes hours, and the only signal on screen was a progress
 * bar that barely moved, which reads as broken rather than slow. But a WRONG
 * estimate is worse than none: a merchant told twenty minutes who is still
 * waiting six hours later does not trust anything the app says afterwards.
 *
 * So these tests are mostly about the two ways this could lie — treating a
 * coupon as costing the same as a product, and extrapolating a whole catalogue
 * from the handful of quick records at the front of the dependency order.
 */
class MigrationEstimateTest extends TestCase
{
    use RefreshDatabase;

    /** Distinct per call, so a test can compare two runs side by side. */
    private int $shopSeq = 0;

    private function migration(array $entities, ?string $startedAt = null): StoreMigration
    {
        $shop = Shop::create([
            'shopify_domain' => 'estimate-'.(++$this->shopSeq).'.myshopify.com',
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
            'selected_entities' => array_keys($entities),
            'started_at' => $startedAt,
        ]);

        foreach ($entities as $type => [$total, $processed]) {
            MigrationEntity::create([
                'migration_id' => $migration->id,
                'entity_type' => $type,
                'status' => 'processing',
                'total_items' => $total,
                'processed_items' => $processed,
                'successful_items' => $processed,
            ]);
        }

        return $migration->load('entities');
    }

    // ------------------------------------------------------------------- plan

    /** @test */
    public function a_product_is_not_costed_the_same_as_a_coupon(): void
    {
        // productSet runs SYNCHRONOUSLY while Shopify downloads every image on the
        // product, plus a separate WooCommerce read for its variations. A coupon
        // is one small mutation. Costing them alike is how an estimate built from
        // a store of coupons gets applied to a catalogue and is wrong by an order
        // of magnitude.
        $products = MigrationEstimate::forPlan(['product' => 1000], 1);
        $coupons = MigrationEstimate::forPlan(['coupon' => 1000], 1);

        $this->assertGreaterThan($coupons['seconds'] * 2, $products['seconds']);
    }

    /** @test */
    public function more_workers_means_less_time(): void
    {
        $one = MigrationEstimate::forPlan(['product' => 20_000], 1);
        $eight = MigrationEstimate::forPlan(['product' => 20_000], 8);

        $this->assertLessThan($one['seconds'], $eight['seconds']);

        // But NOT eight times less. Entities run in strict dependency order and a
        // straggling batch holds the tail, so promising a clean division is
        // promising a number the run cannot hit.
        $this->assertGreaterThan($one['seconds'] / 8, $eight['seconds']);
    }

    /** @test */
    public function the_per_entity_shares_add_up_to_the_whole(): void
    {
        // The wizard's summary sums only what the merchant ticked, so the parts
        // have to be usable on their own.
        $counts = ['product' => 500, 'customer' => 300, 'order' => 200];

        $parts = 0;
        foreach ($counts as $type => $count) {
            $parts += MigrationEstimate::secondsFor($type, $count, 4);
        }

        $whole = MigrationEstimate::forPlan($counts, 4)['seconds'];

        // Rounding per entity means they will not match to the second.
        $this->assertEqualsWithDelta($whole, $parts, 5);
    }

    /** @test */
    public function nothing_to_migrate_produces_no_estimate(): void
    {
        $this->assertNull(MigrationEstimate::forPlan([], 4));
        $this->assertNull(MigrationEstimate::forPlan(['product' => 0], 4));
    }

    // -------------------------------------------------------------------- run

    /** @test */
    public function a_young_run_is_not_extrapolated_from_its_first_few_records(): void
    {
        // Categories come first in the dependency order and are quick. Reading a
        // rate off them and applying it to twenty thousand products promised
        // merchants twenty minutes for an overnight job — so below the sample
        // threshold the static model is used and SAYS it is being used.
        $migration = $this->migration(
            ['category' => [10, 4], 'product' => [20_000, 0]],
            startedAt: now()->subSeconds(5)->toDateTimeString(),
        );

        $estimate = MigrationEstimate::forRun($migration, 4);

        $this->assertSame('plan', $estimate['basis']);
        $this->assertNull($estimate['records_per_minute']);
    }

    /** @test */
    public function a_run_with_a_real_sample_estimates_from_its_own_pace(): void
    {
        // 400 products in an hour is this shop's actual throughput — including
        // their WooCommerce host, their image sizes and their Shopify plan. None
        // of that is knowable up front, and all of it is already in this number.
        $migration = $this->migration(
            ['product' => [1000, 400]],
            startedAt: now()->subHour()->toDateTimeString(),
        );

        $estimate = MigrationEstimate::forRun($migration, 4);

        $this->assertSame('progress', $estimate['basis']);

        // 600 left at the pace that did 400 in an hour — an hour and a half.
        $this->assertEqualsWithDelta(5400, $estimate['seconds_remaining'], 120);
        $this->assertEqualsWithDelta(6.7, $estimate['records_per_minute'], 0.5);
    }

    /** @test */
    public function a_slow_store_gets_a_longer_estimate_than_a_fast_one(): void
    {
        // The whole point of measuring rather than modelling: two identical
        // catalogues, one host answering in seconds and one in minutes, must not
        // be quoted the same number.
        $fast = $this->migration(['product' => [1000, 400]], startedAt: now()->subMinutes(30)->toDateTimeString());
        $slow = $this->migration(['product' => [1000, 400]], startedAt: now()->subHours(3)->toDateTimeString());

        $this->assertLessThan(
            MigrationEstimate::forRun($slow, 4)['seconds_remaining'],
            MigrationEstimate::forRun($fast, 4)['seconds_remaining'],
        );
    }

    /** @test */
    public function a_finished_run_has_nothing_left_to_wait_for(): void
    {
        $migration = $this->migration(
            ['product' => [100, 100]],
            startedAt: now()->subHour()->toDateTimeString(),
        );

        $this->assertSame(0, MigrationEstimate::forRun($migration, 4)['seconds_remaining']);
    }

    /** @test */
    public function progress_running_ahead_of_the_plan_does_not_read_as_finished(): void
    {
        // processed can briefly exceed a total that planning later revised down.
        // A negative remainder would render as "done" on a run that plainly is not.
        $migration = $this->migration(
            ['product' => [100, 140], 'order' => [500, 0]],
            startedAt: now()->subHour()->toDateTimeString(),
        );

        $estimate = MigrationEstimate::forRun($migration, 4);

        $this->assertGreaterThan(0, $estimate['seconds_remaining']);
    }

    // ---------------------------------------------------------------- the API

    /** @test */
    public function the_details_endpoint_carries_a_live_estimate_only_while_running(): void
    {
        $migration = $this->migration(
            ['product' => [1000, 400]],
            startedAt: now()->subHour()->toDateTimeString(),
        );

        $shop = $migration->shop()->first();

        $this->withHeaders(['X-Shop-Domain' => $shop->shopify_domain])
            ->getJson("/api/migrations/{$migration->id}")
            ->assertOk()
            ->assertJsonPath('estimate.basis', 'progress');

        $migration->update(['status' => 'completed']);

        // After it ends the number describes history, not something to wait for.
        $this->withHeaders(['X-Shop-Domain' => $shop->shopify_domain])
            ->getJson("/api/migrations/{$migration->id}")
            ->assertOk()
            ->assertJsonPath('estimate', null);
    }
}
