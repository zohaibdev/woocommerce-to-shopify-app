<?php

namespace App\Http\Controllers;

use App\Exceptions\BillingException;
use App\Jobs\SyncShopBillingJob;
use App\Models\Plan;
use App\Models\Shop;
use App\Models\Subscription;
use App\Services\ShopifyBilling;
use App\Services\ShopifyOAuth;
use App\Services\ShopifyTokenService;
use App\Support\BillingState;
use App\Support\CurrentShop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Merchant billing endpoints. All Shopify work happens in ShopifyBilling; every
 * response carries the full, freshly read billing state, so the frontend never
 * patches its own copy after an operation.
 *
 * Nothing billing-related is taken from the request except the plan CODE, which
 * is looked up server-side. Prices, subscription ids and the shop all come from
 * the database or the verified session token.
 */
class BillingController extends Controller
{
    /** How old a successful sync may be before the billing page asks Shopify again. */
    private const PAGE_SYNC_MAX_AGE = 300;

    /**
     * Plans, the shop's current plan and its full billing state.
     *
     * Reconciles with Shopify when the last sync is older than five minutes, or
     * on ?refresh=1 (at most once every 10 seconds per shop).
     */
    public function index(Request $request, ShopifyBilling $billing): JsonResponse
    {
        $shop = CurrentShop::resolve($request);

        $force = $request->boolean('refresh') && Cache::add("billing:refresh:{$shop->id}", 1, 10);
        $synced = $force
            ? $billing->sync($shop, 'merchant')
            : $billing->syncIfStale($shop, self::PAGE_SYNC_MAX_AGE, 'merchant');

        if (! $synced) {
            $this->retryWithFreshToken($request, $shop, $billing);
        }

        return $this->state($shop);
    }

    /**
     * The stored token was revoked (sync drops it on a 401) — typically an
     * uninstall whose webhook never arrived. This request carries a valid session
     * token, so exchange it and ask Shopify again rather than show stale billing.
     */
    private function retryWithFreshToken(Request $request, Shop $shop, ShopifyBilling $billing): void
    {
        $sessionToken = $request->bearerToken();

        if (! $sessionToken || $shop->refresh()->access_token) {
            return;
        }

        try {
            app(ShopifyTokenService::class)->exchangeSessionToken($shop, $sessionToken);
            $billing->sync($shop, 'merchant');
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** The same shape for every billing response. */
    private function state(Shop $shop, array $extra = []): JsonResponse
    {
        $shop->refresh();
        $shop->forgetPlan();

        $current = $shop->currentPlan();
        $state = BillingState::for($shop);
        $activePaid = Subscription::where('shop_id', $shop->id)->where('status', Subscription::ACTIVE)
            ->with('plan')->orderByDesc('id')->get()->first(fn (Subscription $s) => $s->isPaid());

        return response()->json($extra + [
            'current_plan' => $current ? [
                'code' => $current->code,
                'name' => $current->name,
                'price' => (float) $current->price,
                'interval' => $current->interval,
                'limits' => $current->limits,
            ] : null,
            'subscription_status' => $shop->activeSubscription()->first()?->status,
            'subscription' => $state,
            'history' => BillingState::history($shop),
            'plans' => self::formatPlans($current, $activePaid),
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
        return response()->json(['plans' => self::formatPlans(null, null)]);
    }

    private static function formatPlans(?Plan $current, ?Subscription $activePaid)
    {
        return Plan::where('active', true)->orderBy('price')->get()
            ->map(fn (Plan $p) => [
                'code' => $p->code,
                'name' => $p->name,
                'price' => (float) $p->price,
                'interval' => $p->interval,
                'limits' => $p->limits,
                'is_current' => $current && $p->id === $current->id,
                // What choosing this plan would do, per Shopify's replacement rules.
                'change' => $current === null && $activePaid === null ? null : ShopifyBilling::changePreview($activePaid, $p),
            ]);
    }

    /** Begin a plan change. Returns a Shopify confirmation URL for paid plans. */
    public function subscribe(Request $request, ShopifyBilling $billing): JsonResponse
    {
        $data = $request->validate(['plan' => ['required', 'string', 'max:64']]);

        $shop = CurrentShop::resolve($request);
        $plan = Plan::where('code', $data['plan'])->where('active', true)->first();

        if (! $plan) {
            return response()->json(['error' => 'That plan is not available.', 'reason' => 'invalid_plan'], 422);
        }

        // Enterprise is negotiated and invoiced off-platform. Sending the merchant
        // to a Shopify charge screen for it would bill them for a subscription
        // that is not what they think they are buying.
        if ($plan->isContactOnly()) {
            return response()->json([
                'error' => "The {$plan->name} plan is arranged with our team rather than through Shopify billing. "
                    .'Contact support and we will set it up for you.',
                'reason' => 'contact_only',
                'contact_only' => true,
            ], 422);
        }

        try {
            $result = $billing->subscribe($shop, $plan, 'merchant');
        } catch (BillingException $e) {
            return response()->json($e->toResponseArray(), $e->httpStatus);
        }

        return $this->state($shop, [
            'result' => $result['result'],
            'confirmation_url' => $result['confirmation_url'],   // null => nothing to approve
            'reused' => $result['reused'],
            'plan' => $plan->code,
        ]);
    }

    /**
     * Shopify returns the merchant here after they APPROVE a charge (a decline
     * returns them to the Shopify admin instead). The URL is signed; an unsigned
     * or forged one only queues a reconcile, which reads Shopify's own state.
     */
    public function callback(Request $request, ShopifyBilling $billing): RedirectResponse
    {
        $shopDomain = (string) $request->query('shop');
        $planCode = (string) $request->query('plan');

        abort_unless(ShopifyOAuth::isValidShopDomain($shopDomain), 400, 'Invalid shop domain');

        $shop = Shop::where('shopify_domain', $shopDomain)->firstOrFail();
        $plan = Plan::where('code', $planCode)->first();

        $signed = $plan && hash_equals(
            ShopifyBilling::returnSignature($shopDomain, $planCode),
            (string) $request->query('sig'),
        );

        if ($signed) {
            // Shopify appends charge_id — the charge the merchant was actually shown.
            $outcome = $billing->activate($shop, $plan, (string) $request->query('charge_id') ?: null);
        } else {
            SyncShopBillingJob::dispatch($shop->id, 'callback');
            $outcome = 'processing';
        }

        $billingParam = match ($outcome) {
            'active' => 'success',
            'scheduled' => 'scheduled',
            'declined' => 'declined',
            default => 'processing',
        };

        $handle = preg_replace('/\.myshopify\.com$/', '', $shopDomain);
        $apiKey = ShopifyOAuth::apiKey();

        // Back into the embedded app, flagging the outcome for the UI.
        return redirect()->away("https://admin.shopify.com/store/{$handle}/apps/{$apiKey}?billing={$billingParam}");
    }

    /**
     * Cancel the paid subscription on Shopify. Depending on proration it ends now
     * or stays available until the end of the period already paid for — the
     * response describes which.
     */
    public function cancel(Request $request, ShopifyBilling $billing): JsonResponse
    {
        $shop = CurrentShop::resolve($request);

        try {
            $billing->cancel($shop, 'merchant');
        } catch (BillingException $e) {
            return response()->json($e->toResponseArray(), $e->httpStatus);
        }

        return $this->state($shop, ['result' => 'cancelled']);
    }
}
