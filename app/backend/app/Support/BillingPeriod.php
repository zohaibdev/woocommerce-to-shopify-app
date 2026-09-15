<?php

namespace App\Support;

use App\Models\Shop;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * The window a plan's allowance is measured over.
 *
 * The allowance used to be measured over ALL TIME, which quietly turned a
 * recurring subscription into a one-off purchase: a merchant on a 2,000-product
 * monthly plan spent it once and then paid $19 every month for nothing, with the
 * app telling them they were "exhausted" on an invoice they had just settled.
 *
 * The period is derived ARITHMETICALLY from the subscription's start date rather
 * than stored and advanced by a webhook. Shopify does not reliably send an app
 * subscription event on every renewal, and a missed webhook would freeze a
 * merchant's allowance for a month with nothing in the app to show why — a bug
 * that only appears 30 days after the deploy. Counting months from a fixed
 * anchor cannot drift, needs no column and no delivery guarantee.
 */
class BillingPeriod
{
    /**
     * When the shop's current allowance period began.
     *
     * Anchored to the day the merchant subscribed, so their month runs from
     * their own billing date rather than from the 1st — which is what Shopify
     * actually charges them on.
     */
    public static function currentStart(Shop $shop): CarbonInterface
    {
        $anchor = self::anchor($shop);
        $now = Carbon::now();

        if ($anchor->greaterThanOrEqualTo($now)) {
            return $anchor;
        }

        // addMonthsNoOverflow, so an anchor on the 31st lands on the 28th/30th
        // rather than skipping into the following month and losing a period.
        $elapsed = $anchor->diffInMonths($now);
        $start = $anchor->copy()->addMonthsNoOverflow($elapsed);

        return $start->greaterThan($now)
            ? $start->subMonthNoOverflow()
            : $start;
    }

    /** When the allowance next comes back. */
    public static function nextReset(Shop $shop): CarbonInterface
    {
        return self::currentStart($shop)->copy()->addMonthNoOverflow();
    }

    /**
     * A free shop has no subscription to anchor to, so its month runs from the
     * install date. Without this the Starter plan's 10 products would be a
     * lifetime cap while every paid plan reset, and the difference would only
     * ever be discovered by a merchant who had already given up.
     */
    private static function anchor(Shop $shop): CarbonInterface
    {
        // The entitling subscription, not merely the active one: a plan kept
        // through a paid grace period is still metered from its own billing date.
        $subscription = $shop->entitlingSubscription();

        return ($subscription?->started_at
            ?? $shop->installed_at
            ?? $shop->created_at
            ?? Carbon::now())->copy();
    }
}
