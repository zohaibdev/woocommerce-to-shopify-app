<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Services\ShopifyPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "The VPS runs eight queue workers and only three are ever busy."
 *
 * Not a queue misconfiguration, and not the UI lying — the app was deriving
 * three. A shop sustains restoreRate/pointsPerWrite writes a second, and each
 * write was assumed to occupy a worker for 0.6 SECONDS. On a Standard Shopify
 * store (100 points/second restore) that arithmetic lands on exactly
 * ceil(100/20 * 0.6) = 3, so five of the eight processes had nothing they were
 * permitted to pick up.
 *
 * 0.6s is not how long a Shopify write takes. productSet runs synchronously
 * while Shopify downloads every image on the product — the app's own estimate
 * baseline says 2.5s — so the derived figure was consuming barely a quarter of
 * the budget it existed to respect.
 */
class WorkerUtilisationTest extends TestCase
{
    use RefreshDatabase;

    /** Domains must differ per call, so one test can build two shops. */
    private int $seq = 0;

    private function shopWithRestoreRate(int $restore): Shop
    {
        $shop = Shop::create([
            'shopify_domain' => 'workers-'.$restore.'-'.(++$this->seq).'.myshopify.com',
            'access_token' => 'shpat_x',
            'token_expires_at' => now()->addHour(),
        ]);

        $shop->forceFill([
            'api_restore_rate' => $restore,
            'api_bucket_size' => $restore * 20,
            'limits_checked_at' => now(),
        ])->save();

        return $shop;
    }

    public function test_a_standard_store_gets_more_than_the_three_workers_it_used_to(): void
    {
        config(['migration.max_concurrency' => 8]);

        $workers = app(ShopifyPlan::class)->concurrencyFor($this->shopWithRestoreRate(100));

        $this->assertGreaterThan(3, $workers, 'the old arithmetic derived exactly 3 here');
        $this->assertLessThanOrEqual(8, $workers);
    }

    public function test_the_derived_count_never_overruns_the_shops_api_budget(): void
    {
        // The guard that matters. Parallelism is only free while the writes it
        // produces fit inside the points the shop restores per second — past that
        // the extra workers buy throttle errors rather than throughput.
        //
        // Costed at the SLOWEST plausible write (2.5s per record, the estimate's
        // baseline), because a slower write consumes points more slowly and is
        // therefore the generous case; a faster one would spend more.
        config(['migration.max_concurrency' => 8]);

        foreach ([100, 200, 1000, 2000] as $restore) {
            $workers = app(ShopifyPlan::class)->concurrencyFor($this->shopWithRestoreRate($restore));
            $pointsPerSecond = $workers * (float) config('migration.shopify_write_points') / 2.5;

            $this->assertLessThan(
                $restore,
                $pointsPerSecond,
                "restore={$restore}: {$workers} workers would spend points faster than the shop earns them",
            );
        }
    }

    public function test_the_hard_ceiling_still_wins(): void
    {
        // Past a handful of workers the merchant's WooCommerce host, not Shopify,
        // becomes the limit — so however much Shopify headroom a Plus store has,
        // MIGRATION_MAX_CONCURRENCY is the last word.
        config(['migration.max_concurrency' => 4]);

        $this->assertSame(4, app(ShopifyPlan::class)->concurrencyFor($this->shopWithRestoreRate(2000)));
    }

    public function test_an_unmeasured_shop_still_starts_conservatively(): void
    {
        // First run: nothing has been learned from a response yet. Guessing high
        // here would throttle a Standard store on its very first batch.
        $shop = Shop::create([
            'shopify_domain' => 'unmeasured.myshopify.com',
            'access_token' => 'shpat_x',
            'token_expires_at' => now()->addHour(),
        ]);

        $this->assertLessThanOrEqual(2, app(ShopifyPlan::class)->concurrencyFor($shop));
    }

    public function test_the_write_latency_is_tunable_without_a_deploy(): void
    {
        config(['migration.max_concurrency' => 16]);

        config(['migration.shopify_write_seconds' => 0.6]);
        $slow = app(ShopifyPlan::class)->concurrencyFor($this->shopWithRestoreRate(100));

        config(['migration.shopify_write_seconds' => 2.4]);
        $fast = app(ShopifyPlan::class)->concurrencyFor($this->shopWithRestoreRate(100));

        $this->assertGreaterThan($slow, $fast);
    }
}
