<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Shop;
use App\Models\Subscription;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Shopify Billing (recurring app subscriptions).
 *
 * Flow:
 *   1. subscribe()  → returns Shopify's confirmationUrl; merchant approves there
 *   2. Shopify redirects back to /api/billing/callback?charge_id=…
 *   3. activate()   → verifies THAT charge is ACTIVE and records it locally
 *
 * Free plans never create a Shopify charge — but switching to one cancels the
 * paid charge, or the merchant would go on being billed for a plan the app no
 * longer showed.
 *
 * SHOPIFY IS THE SOURCE OF TRUTH for whether a charge is live. sync() reconciles
 * the local rows against it, and runs whenever billing state is shown, because
 * the local copy goes stale in ways no single webhook covers: Shopify cancels
 * every charge on uninstall, a reinstall arrives through token exchange rather
 * than the OAuth callback, and a webhook sent to an old domain never lands.
 * App Store review 1.2.2 failed on exactly that — after a reinstall the app still
 * presented the cancelled subscription as the merchant's plan.
 *
 * A cancelled PAID plan keeps granting access until the end of the period the
 * merchant already paid for (Subscription::inPaidGracePeriod). Shopify reports
 * currentPeriodEnd as null once a charge is inactive, so it is captured while
 * the charge is active.
 *
 * @see https://shopify.dev/docs/apps/launch/billing/subscription-billing
 */
class ShopifyBilling
{
    private const CREATE_MUTATION = <<<'GQL'
    mutation appSubscriptionCreate(
      $name: String!, $returnUrl: URL!, $test: Boolean, $trialDays: Int,
      $lineItems: [AppSubscriptionLineItemInput!]!
    ) {
      appSubscriptionCreate(
        name: $name, returnUrl: $returnUrl, test: $test, trialDays: $trialDays, lineItems: $lineItems
      ) {
        confirmationUrl
        appSubscription { id status }
        userErrors { field message }
      }
    }
    GQL;

    private const STATE_QUERY = <<<'GQL'
    {
      currentAppInstallation {
        activeSubscriptions { id name status test createdAt currentPeriodEnd }
        allSubscriptions(first: 25, reverse: true) {
          nodes { id name status test createdAt currentPeriodEnd }
        }
      }
    }
    GQL;

    // No `prorate`: the merchant is not refunded the rest of the period, which is
    // precisely why they keep the plan until it ends.
    private const CANCEL_MUTATION = <<<'GQL'
    mutation appSubscriptionCancel($id: ID!) {
      appSubscriptionCancel(id: $id) {
        appSubscription { id status }
        userErrors { field message }
      }
    }
    GQL;

    /** Shopify status of an inactive charge => [local status, cancellation_reason]. */
    private const ENDED = [
        'CANCELLED' => ['cancelled', 'shopify'],
        'EXPIRED' => ['expired', 'expired'],
        'FROZEN' => ['frozen', 'frozen'],
        'DECLINED' => ['cancelled', 'declined'],
    ];

    /**
     * Start a subscription. Returns the Shopify confirmation URL the merchant
     * must visit, or null when the plan is free (applied immediately).
     */
    public function subscribe(Shop $shop, Plan $plan): ?string
    {
        if ((float) $plan->price <= 0) {
            // Cancel the paid charge FIRST, and let a failure stop the switch.
            // Recording Free while Shopify kept billing is the one outcome worse
            // than an error message.
            $this->cancel($shop, 'merchant');
            $this->recordFree($shop, $plan);

            return null;
        }

        $returnUrl = rtrim(ShopifyOAuth::appUrl(), '/')
            . '/api/billing/callback?shop=' . urlencode($shop->shopify_domain)
            . '&plan=' . urlencode($plan->code);

        $variables = [
            'name' => $plan->name,
            'returnUrl' => $returnUrl,
            'test' => (bool) config('services.shopify.billing_test', false),
            'lineItems' => [[
                'plan' => [
                    'appRecurringPricingDetails' => [
                        'price' => ['amount' => (float) $plan->price, 'currencyCode' => 'USD'],
                        'interval' => $plan->interval === 'yearly' ? 'ANNUAL' : 'EVERY_30_DAYS',
                    ],
                ],
            ]],
        ];

        // Re-approving the plan they are still inside a paid grace period for —
        // typically right after a reinstall. Deferring the first charge until the
        // paid time runs out stops Shopify billing the same days twice.
        $grace = $this->gracePeriodFor($shop, $plan);
        if ($grace) {
            $variables['trialDays'] = $grace->daysRemaining();
        }

        $client = new ShopifyClient($shop);
        $body = $client->graphql(self::CREATE_MUTATION, $variables);

        $errors = $client->userErrors($body, 'appSubscriptionCreate');
        if (empty($errors)) {
            $errors = $client->topLevelErrors($body);
        }
        if (! empty($errors)) {
            throw new RuntimeException('Billing failed: ' . json_encode($errors));
        }

        $url = data_get($body, 'data.appSubscriptionCreate.confirmationUrl');
        if (! $url) {
            throw new RuntimeException('Shopify returned no confirmation URL');
        }

        // Pending until the merchant approves on Shopify's screen.
        Subscription::updateOrCreate(
            ['shop_id' => $shop->id, 'plan_id' => $plan->id, 'status' => 'pending'],
            [
                'shopify_charge_id' => data_get($body, 'data.appSubscriptionCreate.appSubscription.id'),
                'started_at' => null,
                'current_period_end' => null,
                'cancelled_at' => null,
                'cancellation_reason' => null,
                'ends_at' => null,
            ],
        );

        return $url;
    }

    /**
     * Called when Shopify redirects back after the approval screen.
     *
     * Checks the charge the merchant was actually shown. This used to accept ANY
     * active subscription, so declining an upgrade while already on a paid plan
     * was recorded as an approved upgrade.
     */
    public function activate(Shop $shop, Plan $plan, ?string $chargeId = null): bool
    {
        $pending = Subscription::where('shop_id', $shop->id)
            ->where('plan_id', $plan->id)
            ->where('status', 'pending')
            ->latest('id')
            ->first();

        $target = $chargeId && ctype_digit($chargeId)
            ? "gid://shopify/AppSubscription/{$chargeId}"
            : $pending?->shopify_charge_id;

        $state = $this->fetchState($shop);
        if ($state === null) {
            return false;
        }

        $node = $target
            ? $state['active'][$target] ?? null
            : collect($state['active'])->firstWhere('name', $plan->name);

        if ($node) {
            $this->markActive($shop, $plan, $node, $pending);

            return true;
        }

        // Approved but not ACTIVE yet: leave it pending for sync() and the
        // app_subscriptions/update webhook to finish, rather than call it declined.
        if ($target && in_array($state['all'][$target]['status'] ?? null, ['ACCEPTED', 'PENDING'], true)) {
            return false;
        }

        // Declined, or abandoned on Shopify's screen. Nothing was granted — and
        // the pending row must not linger looking like a charge in progress.
        $pending?->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'cancellation_reason' => 'declined',
            'ends_at' => now(),
        ]);

        return false;
    }

    /**
     * Cancel the shop's paid Shopify charge. The plan stays in force until the
     * end of the period already paid for.
     *
     * Throws when Shopify cannot confirm the charge is gone, rather than
     * pretending locally that billing has stopped.
     */
    public function cancel(Shop $shop, string $reason = 'merchant'): void
    {
        $sub = $this->activePaid($shop);

        if (! $sub) {
            return;
        }

        if ($sub->shopify_charge_id) {
            $failure = null;

            try {
                $client = new ShopifyClient($shop);
                $body = $client->graphql(self::CANCEL_MUTATION, ['id' => $sub->shopify_charge_id]);
                $errors = $client->userErrors($body, 'appSubscriptionCancel') ?: $client->topLevelErrors($body);
                $failure = $errors ? json_encode($errors) : null;
            } catch (\Throwable $e) {
                $failure = $e->getMessage();
            }

            if ($failure !== null) {
                // "Already cancelled" arrives as an error too. Ask Shopify what is
                // actually true before refusing the merchant.
                $this->sync($shop);

                if ($sub->refresh()->status === 'active') {
                    report(new RuntimeException("Subscription cancel failed: {$failure}"));

                    throw new RuntimeException('Shopify could not cancel your subscription. Please try again.');
                }

                return;
            }
        }

        $this->end($sub, 'cancelled', $reason);
        $shop->forgetPlan();
    }

    /**
     * app/uninstalled. Shopify cancels every app charge on uninstall and the token
     * is already revoked, so nothing can be asked — the facts captured while the
     * charge was active have to be enough.
     */
    public function handleUninstall(Shop $shop): void
    {
        Subscription::where('shop_id', $shop->id)
            ->whereIn('status', ['active', 'pending'])
            ->with('plan')
            ->get()
            ->each(function (Subscription $sub) {
                if ($sub->status === 'active' && $sub->isPaid()) {
                    $this->end($sub, 'cancelled', 'uninstalled');

                    return;
                }

                $sub->update([
                    'status' => 'cancelled',
                    'cancelled_at' => now(),
                    'cancellation_reason' => 'uninstalled',
                    'ends_at' => now(),
                ]);
            });

        $shop->forgetPlan();
    }

    /**
     * Reconcile local subscriptions with what Shopify says is live.
     *
     * Returns false when Shopify could not be asked (no token, network, API
     * error). In that case NOTHING is changed: a failed lookup must never
     * downgrade a paying merchant.
     */
    public function sync(Shop $shop): bool
    {
        $state = $this->fetchState($shop);
        if ($state === null) {
            return false;
        }

        $local = Subscription::where('shop_id', $shop->id)->with('plan')->orderBy('id')->get();

        foreach ($local as $sub) {
            if (! $sub->shopify_charge_id) {
                continue;
            }

            $live = $state['active'][$sub->shopify_charge_id] ?? null;

            if ($sub->status === 'active' && $live) {
                $sub->update(['current_period_end' => $this->periodEnd($live) ?? $sub->current_period_end]);

                continue;
            }

            if ($sub->status === 'active' && $sub->isPaid()) {
                // Active here, not on Shopify: cancelled while our webhook was
                // missed. Keep whatever paid time is left.
                $known = $state['all'][$sub->shopify_charge_id] ?? null;
                [$status, $reason] = self::ENDED[$known['status'] ?? 'CANCELLED'] ?? ['cancelled', 'shopify'];
                $this->end($sub, $status, $reason);

                continue;
            }

            if ($sub->status === 'pending' && ! $live) {
                $known = $state['all'][$sub->shopify_charge_id] ?? null;

                if (in_array($known['status'] ?? null, ['DECLINED', 'EXPIRED', 'CANCELLED'], true)) {
                    $sub->update([
                        'status' => 'cancelled',
                        'cancelled_at' => now(),
                        'cancellation_reason' => 'declined',
                        'ends_at' => now(),
                    ]);
                }
            }
        }

        // Live on Shopify but not active here: approved while the callback never
        // arrived, or re-activated on Shopify's side. Shopify wins.
        foreach ($state['active'] as $id => $node) {
            $row = $local->firstWhere('shopify_charge_id', $id);

            if ($row?->status === 'active') {
                continue;
            }

            $plan = $row?->plan ?? Plan::where('name', $node['name'] ?? '')->first();

            if (! $plan) {
                Log::warning('Active Shopify subscription matches no plan', [
                    'shop' => $shop->shopify_domain,
                    'subscription' => $id,
                    'name' => $node['name'] ?? null,
                ]);

                continue;
            }

            $this->markActive($shop, $plan, $node, $row);
        }

        $shop->forgetPlan();

        return true;
    }

    /**
     * app_subscriptions/update. Reconcile against Shopify when possible; with no
     * usable token, apply the status the webhook itself carries.
     */
    public function handleSubscriptionUpdate(Shop $shop, ?string $chargeId, ?string $status): void
    {
        if ($shop->access_token && $this->sync($shop)) {
            return;
        }

        $sub = $chargeId
            ? Subscription::where('shop_id', $shop->id)->where('shopify_charge_id', $chargeId)->with('plan')->first()
            : null;

        if (! $sub || ! isset(self::ENDED[strtoupper((string) $status)])) {
            return;
        }

        [$local, $reason] = self::ENDED[strtoupper((string) $status)];

        if (in_array($sub->status, ['active', 'pending'], true)) {
            $this->end($sub, $local, $reason);
            $shop->forgetPlan();
        }
    }

    /** A cancelled, still-paid-for subscription to this plan, if there is one. */
    public function gracePeriodFor(Shop $shop, Plan $plan): ?Subscription
    {
        return Subscription::where('shop_id', $shop->id)
            ->where('plan_id', $plan->id)
            ->where('status', 'cancelled')
            ->where('ends_at', '>', now())
            ->latest('id')
            ->first();
    }

    /**
     * @return array{active: array<string,array>, all: array<string,array>}|null
     */
    private function fetchState(Shop $shop): ?array
    {
        if (! $shop->access_token) {
            return null;
        }

        try {
            $body = (new ShopifyClient($shop))->graphql(self::STATE_QUERY);
        } catch (\Throwable $e) {
            Log::warning('Billing state lookup failed', ['shop' => $shop->shopify_domain, 'error' => $e->getMessage()]);

            return null;
        }

        $installation = data_get($body, 'data.currentAppInstallation');
        if (! is_array($installation)) {
            Log::warning('Billing state lookup returned no installation', [
                'shop' => $shop->shopify_domain,
                'errors' => data_get($body, 'errors'),
            ]);

            return null;
        }

        return [
            'active' => collect($installation['activeSubscriptions'] ?? [])
                ->filter(fn ($s) => ($s['status'] ?? null) === 'ACTIVE' && isset($s['id']))
                ->keyBy('id')
                ->all(),
            'all' => collect(data_get($installation, 'allSubscriptions.nodes', []))
                ->filter(fn ($s) => isset($s['id']))
                ->keyBy('id')
                ->all(),
        ];
    }

    /** Make $plan the shop's active paid plan, backed by the live Shopify charge $node. */
    private function markActive(Shop $shop, Plan $plan, array $node, ?Subscription $row): Subscription
    {
        $row ??= Subscription::where('shop_id', $shop->id)->where('shopify_charge_id', $node['id'])->first();

        // Everything this charge now supersedes — including a grace period, whose
        // days a same-plan re-approval carries over as trial days instead.
        Subscription::where('shop_id', $shop->id)
            ->when($row, fn ($q) => $q->whereKeyNot($row->id))
            ->where(fn ($q) => $q->whereIn('status', ['active', 'pending'])
                ->orWhere(fn ($q) => $q->where('status', 'cancelled')->where('ends_at', '>', now())))
            ->get()
            ->each(fn (Subscription $old) => $old->update([
                'status' => 'cancelled',
                'cancelled_at' => $old->cancelled_at ?? now(),
                'cancellation_reason' => $old->cancellation_reason ?? 'replaced',
                'ends_at' => now(),
            ]));

        $attributes = [
            'plan_id' => $plan->id,
            'status' => 'active',
            'shopify_charge_id' => $node['id'],
            'started_at' => $row?->started_at
                ?? (isset($node['createdAt']) ? Carbon::parse($node['createdAt']) : now()),
            'current_period_end' => $this->periodEnd($node),
            'cancelled_at' => null,
            'cancellation_reason' => null,
            'ends_at' => null,
        ];

        $row = $row
            ? tap($row)->update($attributes)
            : Subscription::create(['shop_id' => $shop->id] + $attributes);

        $shop->forgetPlan();

        return $row;
    }

    /** Record the free plan as active. It never supersedes a paid grace period. */
    private function recordFree(Shop $shop, Plan $plan): void
    {
        Subscription::where('shop_id', $shop->id)
            ->where('status', 'active')
            ->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancellation_reason' => 'replaced',
                'ends_at' => now(),
            ]);

        Subscription::create([
            'shop_id' => $shop->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'started_at' => now(),
        ]);

        $shop->forgetPlan();
    }

    /**
     * Take a subscription out of service. A cancelled PAID one keeps access to
     * the end of its paid period; expired, frozen and declined ones end now.
     */
    private function end(Subscription $sub, string $status, string $reason): void
    {
        $sub->loadMissing('plan');

        $paidUntil = $sub->current_period_end ?? $this->estimatePeriodEnd($sub);
        $keepsAccess = $status === 'cancelled'
            && $reason !== 'declined'
            && $sub->isPaid()
            && $paidUntil?->isFuture();

        $sub->update([
            'status' => $status,
            'cancelled_at' => $sub->cancelled_at ?? now(),
            'cancellation_reason' => $reason,
            'current_period_end' => $paidUntil,
            'ends_at' => $keepsAccess ? $paidUntil : now(),
        ]);
    }

    private function periodEnd(array $node): ?CarbonInterface
    {
        return empty($node['currentPeriodEnd']) ? null : Carbon::parse($node['currentPeriodEnd']);
    }

    /**
     * Where the paid period ends, for a subscription that never had
     * currentPeriodEnd captured (anything activated before it was recorded).
     * Shopify bills EVERY_30_DAYS, or ANNUAL — days, not calendar months.
     */
    private function estimatePeriodEnd(Subscription $sub): ?CarbonInterface
    {
        if (! $sub->started_at) {
            return null;
        }

        $days = $sub->plan?->interval === 'yearly' ? 365 : 30;
        $elapsed = (int) floor($sub->started_at->diffInDays(now()) / $days);

        return $sub->started_at->copy()->addDays(($elapsed + 1) * $days);
    }

    private function activePaid(Shop $shop): ?Subscription
    {
        return Subscription::where('shop_id', $shop->id)
            ->where('status', 'active')
            ->with('plan')
            ->orderByDesc('id')
            ->get()
            ->first(fn (Subscription $s) => $s->isPaid());
    }
}
