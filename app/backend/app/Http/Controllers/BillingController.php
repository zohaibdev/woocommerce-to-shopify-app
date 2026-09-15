<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\Shop;
use App\Models\Subscription;
use App\Services\ShopifyBilling;
use App\Services\ShopifyOAuth;
use App\Support\CurrentShop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BillingController extends Controller
{
    /**
     * Plans + the shop's current plan, usage limits and subscription lifecycle.
     *
     * Reconciles with Shopify first. The local rows are stale after an uninstall
     * and reinstall — Shopify cancels the charge, and the reinstall arrives by
     * token exchange, not the OAuth callback — and this is the screen a merchant
     * opens to find out what they are paying for. A failed lookup changes nothing.
     */
    public function index(Request $request, ShopifyBilling $billing): JsonResponse
    {
        $shop = CurrentShop::resolve($request);
        $billing->sync($shop);

        return $this->state($shop);
    }

    private function state(Shop $shop): JsonResponse
    {
        $shop->forgetPlan();
        $current = $shop->currentPlan();

        return response()->json([
            'current_plan' => $current ? [
                'code' => $current->code,
                'name' => $current->name,
                'price' => (float) $current->price,
                'limits' => $current->limits,
            ] : null,
            'subscription_status' => $shop->activeSubscription()->first()?->status,
            'subscription' => self::lifecycle($shop),
            'plans' => self::formatPlans($current),
        ]);
    }

    /**
     * Where the shop's paid subscription stands, in the terms a merchant needs:
     *
     *   active          paying; renews on current_period_end
     *   cancelled_grace cancelled (or uninstalled) but paid up — keeps the plan until access_until
     *   ended           a paid plan existed and has run out; now on the free plan
     *   free            never had a paid plan
     */
    private static function lifecycle(Shop $shop): array
    {
        $entitling = $shop->entitlingSubscription();

        $subject = match (true) {
            $entitling !== null && $entitling->isPaid() => $entitling,
            // The most recent paid plan that actually ran. Declined charges never
            // started, so they are not "a subscription you had".
            default => $shop->subscriptions()
                ->with('plan')
                ->whereNotNull('started_at')
                ->whereIn('status', ['cancelled', 'expired', 'frozen'])
                ->orderByDesc('id')
                ->get()
                ->first(fn (Subscription $s) => $s->isPaid()),
        };

        $state = match (true) {
            $subject === null => 'free',
            $subject->status === 'active' => 'active',
            $subject->inPaidGracePeriod() => 'cancelled_grace',
            default => 'ended',
        };

        return [
            'state' => $state,
            'status' => $subject?->status,
            'plan' => $subject?->plan ? [
                'code' => $subject->plan->code,
                'name' => $subject->plan->name,
                'price' => (float) $subject->plan->price,
            ] : null,
            'started_at' => $subject?->started_at?->toIso8601String(),
            'current_period_end' => $subject?->current_period_end?->toIso8601String(),
            'cancelled_at' => $subject?->cancelled_at?->toIso8601String(),
            'cancellation_reason' => $subject?->cancellation_reason,
            'access_until' => $state === 'cancelled_grace' || $state === 'ended'
                ? $subject->ends_at?->toIso8601String()
                : null,
            'days_remaining' => $state === 'cancelled_grace' ? $subject->daysRemaining() : 0,
        ];
    }

    /**
     * Same plan list, with no shop context. Powers the pricing table on the
     * public marketing site (index.html), which has no Shopify session and
     * previously hardcoded its own copy of the plan data — so an admin
     * editing coupons/blogs/reviews limits in AdminPlans never reached it.
     */
    public function publicPlans(): JsonResponse
    {
        return response()->json(['plans' => self::formatPlans(null)]);
    }

    private static function formatPlans(?Plan $current)
    {
        return Plan::where('active', true)->orderBy('price')->get()
            ->map(fn (Plan $p) => [
                'code' => $p->code,
                'name' => $p->name,
                'price' => (float) $p->price,
                'interval' => $p->interval,
                'limits' => $p->limits,
                'is_current' => $current && $p->id === $current->id,
            ]);
    }

    /** Begin a plan change. Returns a Shopify confirmation URL for paid plans. */
    public function subscribe(Request $request, ShopifyBilling $billing): JsonResponse
    {
        $data = $request->validate(['plan' => ['required', 'string']]);

        $shop = CurrentShop::resolve($request);
        $plan = Plan::where('code', $data['plan'])->where('active', true)->firstOrFail();

        // Enterprise is negotiated and invoiced off-platform. Sending the merchant
        // to a Shopify charge screen for it would bill them for a subscription
        // that is not what they think they are buying.
        if ($plan->isContactOnly()) {
            return response()->json([
                'error' => "The {$plan->name} plan is arranged with our team rather than through Shopify billing. "
                    .'Contact support and we will set it up for you.',
                'contact_only' => true,
            ], 422);
        }

        try {
            $confirmationUrl = $billing->subscribe($shop, $plan);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json([
            'confirmation_url' => $confirmationUrl,   // null => free plan, applied immediately
            'plan' => $plan->code,
        ]);
    }

    /** Shopify redirects here after the merchant approves (or declines). */
    public function callback(Request $request, ShopifyBilling $billing): RedirectResponse
    {
        $shopDomain = (string) $request->query('shop');
        $planCode = (string) $request->query('plan');

        abort_unless(ShopifyOAuth::isValidShopDomain($shopDomain), 400, 'Invalid shop domain');

        $shop = Shop::where('shopify_domain', $shopDomain)->firstOrFail();
        $plan = Plan::where('code', $planCode)->firstOrFail();

        // Shopify appends charge_id — the charge the merchant was actually shown.
        $ok = $billing->activate($shop, $plan, (string) $request->query('charge_id') ?: null);

        $handle = preg_replace('/\.myshopify\.com$/', '', $shopDomain);
        $apiKey = ShopifyOAuth::apiKey();

        // Back into the embedded app, flagging the outcome for the UI.
        return redirect()->away(
            "https://admin.shopify.com/store/{$handle}/apps/{$apiKey}"
            . '?billing=' . ($ok ? 'success' : 'declined')
        );
    }

    /** Cancel the paid subscription and fall back to the free plan. */
    public function cancel(Request $request, ShopifyBilling $billing): JsonResponse
    {
        $shop = CurrentShop::resolve($request);

        try {
            $billing->cancel($shop);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return $this->state($shop);
    }
}
