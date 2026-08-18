<?php

namespace App\Services;

use App\Models\Shop;
use Illuminate\Support\Facades\Cache;

/**
 * Works out how fast we are allowed to push a particular merchant.
 *
 * Shopify's GraphQL budget is per SHOP, not per app, and it differs by an order
 * of magnitude across plans (100 points/s on Standard, 2000 on Enterprise).
 * Running every merchant at the pace that is safe for the smallest one means a
 * Plus store migrates ten times slower than it could; running everyone at Plus
 * pace means Standard stores spend the whole run throttled.
 *
 * So we do not guess. Every GraphQL response carries the shop's real bucket size
 * and restore rate in `extensions.cost.throttleStatus`; ShopifyClient hands them
 * here, and the migration's speed is derived from the actual numbers. The plan
 * NAME is fetched separately, purely so the merchant can be told which tier they
 * are being run at.
 */
class ShopifyPlan
{
    private const PLAN_QUERY = <<<'GQL'
    {
      shop {
        plan { displayName partnerDevelopment shopifyPlus }
      }
    }
    GQL;

    /** Restore rate (points/sec) at or above which a shop is in each tier. */
    private const TIERS = [
        'enterprise' => 2000,
        'plus' => 1000,
        'advanced' => 200,
        'standard' => 0,
    ];

    /**
     * Roughly how many points a productSet costs, and how long one round trip
     * takes. Together they turn "points per second" into "how many jobs may run
     * at once" — see concurrencyFor().
     *
     * Both come from config now (see config/migration.php for the full reasoning).
     * The seconds figure used to be a hard-coded 0.6, which is why a host running
     * eight queue workers only ever had three of them busy on a Standard store.
     */
    private static function pointsPerWrite(): float
    {
        return max(1.0, (float) config('migration.shopify_write_points', 20));
    }

    private static function secondsPerWrite(): float
    {
        return max(0.1, (float) config('migration.shopify_write_seconds', 1.2));
    }

    /**
     * Record the limits Shopify just told us about. Called on every response, so
     * it must be nearly free — the write is rate limited to once an hour per
     * shop, and only happens when the numbers actually changed.
     */
    public function remember(Shop $shop, float $bucket, float $restoreRate): void
    {
        if ($bucket <= 0 || $restoreRate <= 0) {
            return;
        }

        $unchanged = (int) $shop->api_bucket_size === (int) $bucket
            && (int) $shop->api_restore_rate === (int) $restoreRate;

        if ($unchanged && $shop->limits_checked_at?->gt(now()->subHour())) {
            return;
        }

        $shop->forceFill([
            'api_bucket_size' => (int) $bucket,
            'api_restore_rate' => (int) $restoreRate,
            'limits_checked_at' => now(),
        ])->save();
    }

    /**
     * Fetch and store the merchant's plan name. Cheap, cached for a day — a shop
     * does not change plan mid-migration, and if it does the throttle numbers
     * (which we read continuously) pick the change up on their own.
     */
    public function detect(Shop $shop): ?string
    {
        $cacheKey = "shopify_plan_name:{$shop->id}";

        $name = Cache::remember($cacheKey, now()->addDay(), function () use ($shop) {
            try {
                $body = (new ShopifyClient($shop))->graphql(self::PLAN_QUERY);
            } catch (\Throwable) {
                return '';
            }

            return (string) (data_get($body, 'data.shop.plan.displayName') ?: '');
        });

        if ($name === '') {
            return null;
        }

        if ($shop->shopify_plan !== $name) {
            $shop->forceFill(['shopify_plan' => $name])->save();
        }

        return $name;
    }

    /** standard | advanced | plus | enterprise — derived from the real restore rate. */
    public function tier(Shop $shop): string
    {
        $restore = (int) ($shop->api_restore_rate ?: 0);

        foreach (self::TIERS as $tier => $floor) {
            if ($restore >= $floor && $floor > 0) {
                return $tier;
            }
        }

        return 'standard';
    }

    /**
     * How many batch jobs may run at once for this shop.
     *
     * A shop can sustain restoreRate/POINTS_PER_WRITE writes per second, and each
     * write occupies a worker for SECONDS_PER_WRITE. Multiply the two and you get
     * the number of workers needed to keep the budget saturated without
     * overshooting it.
     *
     * The ceiling matters as much as the maths: past a handful of workers the
     * merchant's WooCommerce host — not Shopify — becomes the limit, and piling
     * on more only makes their store fall over.
     */
    public function concurrencyFor(Shop $shop): int
    {
        $restore = (int) ($shop->api_restore_rate ?: 0);
        $ceiling = max(1, (int) config('migration.max_concurrency', 8));

        if ($restore <= 0) {
            // Nothing measured yet (first run). Start conservative; the next
            // response teaches us the real number.
            return min(2, $ceiling);
        }

        $workers = (int) ceil(($restore / self::pointsPerWrite()) * self::secondsPerWrite());

        return max(1, min($workers, $ceiling));
    }

    /** One line for the migration log, so the merchant can see why it runs at this speed. */
    public function describe(Shop $shop): string
    {
        $name = $shop->shopify_plan;
        $restore = (int) ($shop->api_restore_rate ?: 0);
        $workers = $this->concurrencyFor($shop);

        if ($restore <= 0) {
            return 'Measuring your store\'s Shopify API limits — the migration will speed up once they are known.';
        }

        $label = $name ? "{$name} plan" : ucfirst($this->tier($shop)).' tier';

        return "Detected {$label} ({$restore} API points/second) — running this migration with {$workers} parallel worker"
            .($workers === 1 ? '' : 's').'.';
    }
}
