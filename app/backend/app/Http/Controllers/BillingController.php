<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\Shop;
use App\Services\ShopifyBilling;
use App\Services\ShopifyOAuth;
use App\Support\CurrentShop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BillingController extends Controller
{
    /** Plans + the shop's current plan and usage limits. */
    public function index(Request $request): JsonResponse
    {
        $shop = CurrentShop::resolve($request);
        $current = $shop->currentPlan();

        return response()->json([
            'current_plan' => $current ? [
                'code' => $current->code,
                'name' => $current->name,
                'price' => (float) $current->price,
                'limits' => $current->limits,
            ] : null,
            'subscription_status' => $shop->activeSubscription?->status,
            'plans' => self::formatPlans($current),
        ]);
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

        $ok = $billing->activate($shop, $plan);

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
        $billing->cancel($shop);

        return $this->index($request);
    }
}
