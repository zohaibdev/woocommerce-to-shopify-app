<?php

namespace App\Services;

use App\Exceptions\BillingException;
use App\Jobs\SyncShopBillingJob;
use App\Models\BillingEvent;
use App\Models\Plan;
use App\Models\Shop;
use App\Models\Subscription;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Shopify Billing API (Manual Pricing) — recurring app subscriptions.
 *
 * THE ONLY place that talks to Shopify about billing. Controllers, webhooks, the
 * scheduler and the admin panel all come through here.
 *
 * Source of truth
 *   Shopify is authoritative for whether a subscription is live. The subscriptions
 *   table is this app's copy plus history; reconcile() brings it in line with
 *   currentAppInstallation and is idempotent — running it twice changes nothing.
 *
 * Verified Shopify behaviour this is built on (Admin GraphQL 2026-07 docs and
 * shopify.dev/docs/apps/launch/billing/manual-pricing/subscription-billing):
 *   - "An app can have only one active subscription for each merchant."
 *   - A plan change creates a NEW subscription; "the existing subscription is
 *     canceled and replaced by the new subscription after the merchant approves
 *     it". Declined or abandoned: the old subscription keeps running.
 *   - replacementBehavior STANDARD replaces immediately, except annual → monthly
 *     and annual → cheaper annual, which defer to the next billing cycle.
 *     Upgrades are charged the prorated difference; downgrades get a prorated
 *     credit.
 *   - appSubscriptionCancel(id, prorate) takes no "end of period" option. The
 *     subscription becomes CANCELLED (terminal) at once. prorate: false (default)
 *     issues no credit.
 *   - On uninstall Shopify cancels the subscription, "a credit isn't applied",
 *     and "merchants can reinstall and use the app for the remainder of the
 *     billing period".
 *   - PENDING charges not approved within two days become EXPIRED. FROZEN means
 *     "on hold due to non-payment" and re-activates after payment resumes.
 *   - APP_SUBSCRIPTIONS_UPDATE fires on status changes, NOT on a routine renewal,
 *     so renewals are picked up by sync (billing:sync).
 *   - currentPeriodEnd is null for a subscription that isn't active, so it is
 *     captured while it is.
 *
 * Paid-through access: a cancelled, non-prorated subscription keeps its plan
 * until current_period_end (Subscription::ends_at). A prorated or replaced one
 * ends at once. See Shop::entitlement().
 */
class ShopifyBilling
{
    /** How long a same-plan checkout is reused for repeat clicks when Shopify can't be asked. */
    private const REUSE_UNVERIFIED_MINUTES = 60;

    /** Shopify expires an unapproved charge after two days. */
    public const PENDING_LIFETIME_HOURS = 48;

    private const SUBSCRIPTION_FIELDS = <<<'GQL'
    fragment BillingSubscription on AppSubscription {
      id name status test createdAt currentPeriodEnd trialDays returnUrl
      lineItems {
        plan {
          pricingDetails {
            __typename
            ... on AppRecurringPricing { interval price { amount currencyCode } }
          }
        }
      }
    }
    GQL;

    private const STATE_QUERY = <<<'GQL'
    query BillingState {
      currentAppInstallation {
        activeSubscriptions { ...BillingSubscription }
        allSubscriptions(first: 25, reverse: true) { nodes { ...BillingSubscription } }
      }
    }
    GQL;

    private const CREATE_MUTATION = <<<'GQL'
    mutation appSubscriptionCreate(
      $name: String!, $returnUrl: URL!, $test: Boolean,
      $replacementBehavior: AppSubscriptionReplacementBehavior,
      $lineItems: [AppSubscriptionLineItemInput!]!
    ) {
      appSubscriptionCreate(
        name: $name, returnUrl: $returnUrl, test: $test,
        replacementBehavior: $replacementBehavior, lineItems: $lineItems
      ) {
        confirmationUrl
        appSubscription { id status createdAt test }
        userErrors { field message }
      }
    }
    GQL;

    private const CANCEL_MUTATION = <<<'GQL'
    mutation appSubscriptionCancel($id: ID!, $prorate: Boolean) {
      appSubscriptionCancel(id: $id, prorate: $prorate) {
        appSubscription { id status }
        userErrors { field message }
      }
    }
    GQL;

    // =====================================================================
    // Merchant / admin operations
    // =====================================================================

    /**
     * Start (or reuse) a checkout for $plan.
     *
     * @return array{result: string, confirmation_url: ?string, subscription: ?Subscription, reused: bool}
     *   result: confirmation_required | cancelled | noop
     *
     * @throws BillingException
     */
    public function subscribe(Shop $shop, Plan $plan, string $source = 'merchant'): array
    {
        return $this->locked($shop, 0, function () use ($shop, $plan, $source) {
            if (! $shop->access_token) {
                throw BillingException::notConnected();
            }

            // Decide on current facts, not on a copy that may be hours old.
            $verified = $this->syncUnlocked($shop, $source);
            $current = $this->activePaidRow($shop);

            if ($plan->isFree()) {
                if (! $current) {
                    return ['result' => 'noop', 'confirmation_url' => null, 'subscription' => null, 'reused' => false];
                }

                return [
                    'result' => 'cancelled',
                    'confirmation_url' => null,
                    'subscription' => $this->cancelUnlocked($shop, $source, null, 'Switched to the free plan'),
                    'reused' => false,
                ];
            }

            if ($current && $current->plan_id === $plan->id) {
                throw new BillingException('already_subscribed', "You're already subscribed to the {$plan->name} plan.");
            }

            $scheduled = Subscription::where('shop_id', $shop->id)->where('plan_id', $plan->id)
                ->where('status', Subscription::PENDING)->whereNotNull('approved_at')->where('shopify_status', 'ACTIVE')->exists();

            if ($scheduled) {
                throw new BillingException('already_scheduled', "Your change to the {$plan->name} plan is already approved and starts at your next billing cycle.");
            }

            if ($reusable = $this->reusableCheckout($shop, $plan, $verified)) {
                return ['result' => 'confirmation_required', 'confirmation_url' => $reusable->confirmation_url, 'subscription' => $reusable, 'reused' => true];
            }

            return $this->createCheckout($shop, $plan, $current, $source);
        });
    }

    /**
     * The merchant came back through the signed return URL — Shopify only sends
     * them there after APPROVING (a decline returns them to the Shopify admin).
     * Approval alone does not grant anything: the plan is granted when Shopify
     * reports the subscription ACTIVE.
     *
     * @return string active | scheduled | processing | declined
     */
    public function activate(Shop $shop, Plan $plan, ?string $chargeId = null, string $source = 'callback'): string
    {
        try {
            return $this->locked($shop, 15, function () use ($shop, $plan, $chargeId, $source) {
                $gid = $chargeId && ctype_digit($chargeId) ? "gid://shopify/AppSubscription/{$chargeId}" : null;

                // Only a charge that belongs to THIS shop — a charge id is never trusted on its own.
                $row = $gid
                    ? Subscription::where('shop_id', $shop->id)->where('shopify_charge_id', $gid)->first()
                    : Subscription::where('shop_id', $shop->id)->where('plan_id', $plan->id)
                        ->where('status', Subscription::PENDING)->latest('id')->first();

                if ($row && $row->status === Subscription::PENDING && ! $row->approved_at) {
                    $row->update(['approved_at' => now()]);
                }

                if (! $this->syncUnlocked($shop, $source)) {
                    // Approved, but Shopify can't confirm yet. The webhook or the
                    // next sync finishes it; nothing is granted meanwhile.
                    return 'processing';
                }

                $row?->refresh();

                return match (true) {
                    $row === null => $shop->entitlement()['basis'] === 'active' ? 'active' : 'processing',
                    $row->status === Subscription::ACTIVE => 'active',
                    in_array($row->status, [Subscription::DECLINED, Subscription::EXPIRED, Subscription::CANCELLED], true) => 'declined',
                    $row->shopify_status === 'ACTIVE' => 'scheduled',
                    default => 'processing',
                };
            });
        } catch (BillingException) {
            return 'processing';
        }
    }

    /**
     * Cancel the shop's paid subscription ON SHOPIFY.
     *
     * Nothing is recorded as cancelled unless Shopify confirms it (or, after a
     * failed call, a fresh lookup shows it is no longer active).
     *
     * @throws BillingException
     */
    public function cancel(Shop $shop, string $source = 'merchant', ?bool $prorate = null, ?string $note = null): Subscription
    {
        return $this->locked($shop, $source === 'admin' ? 10 : 0, function () use ($shop, $source, $prorate, $note) {
            if (! $shop->access_token) {
                throw BillingException::notConnected();
            }

            $this->syncUnlocked($shop, $source);

            return $this->cancelUnlocked($shop, $source, $prorate, $note);
        });
    }

    /** What cancelling would do right now — shown in the confirmation dialog before anything happens. */
    public function cancellationPreview(Shop $shop): array
    {
        $current = $this->activePaidRow($shop);
        $prorate = (bool) config('services.shopify.billing_prorate_cancellations', false);
        $periodEnd = $current?->current_period_end;

        return [
            'prorate' => $prorate,
            // null => the plan ends the moment the cancellation is confirmed.
            'access_until' => ! $prorate && $periodEnd?->isFuture() ? $periodEnd->toIso8601String() : null,
        ];
    }

    /**
     * What choosing $target would do, per Shopify's documented STANDARD
     * replacement rules — so the merchant is told before being sent to Shopify.
     */
    public static function changePreview(?Subscription $current, Plan $target): array
    {
        if ($target->isFree()) {
            return ['type' => $current ? 'cancel' : 'current_free', 'timing' => $current ? 'on_cancel' : null, 'proration' => 'none', 'effective_at' => null];
        }

        if (! $current) {
            return ['type' => 'new', 'timing' => 'on_approval', 'proration' => 'none', 'effective_at' => null];
        }

        if ($current->plan_id === $target->id) {
            return ['type' => 'current', 'timing' => null, 'proration' => 'none', 'effective_at' => null];
        }

        $currentPrice = (float) ($current->price ?? $current->plan?->price ?? 0);
        $currentInterval = $current->interval ?? $current->plan?->shopifyInterval() ?? 'EVERY_30_DAYS';
        $targetPrice = (float) $target->price;
        $targetInterval = $target->shopifyInterval();

        $deferred = $currentInterval === 'ANNUAL'
            && ($targetInterval === 'EVERY_30_DAYS' || $targetPrice < $currentPrice);

        $type = match (true) {
            $targetPrice > $currentPrice => 'upgrade',
            $targetPrice < $currentPrice => 'downgrade',
            default => 'switch',
        };

        return [
            'type' => $type,
            'timing' => $deferred ? 'next_billing_cycle' : 'on_approval',
            'proration' => $deferred ? 'none' : match ($type) {
                'upgrade' => 'charge_difference',
                'downgrade' => 'credit',
                default => 'none',
            },
            'effective_at' => $deferred ? $current->current_period_end?->toIso8601String() : null,
        ];
    }

    // =====================================================================
    // Lifecycle signals
    // =====================================================================

    /**
     * app/uninstalled. Shopify has cancelled every charge and revoked the token,
     * so nothing can be asked. Per Shopify, no credit is applied and the merchant
     * may use the rest of the paid period if they reinstall.
     */
    public function handleUninstall(Shop $shop, ?CarbonInterface $occurredAt = null, string $source = 'webhook'): void
    {
        DB::transaction(function () use ($shop, $occurredAt, $source) {
            Subscription::where('shop_id', $shop->id)
                ->whereIn('status', [Subscription::ACTIVE, Subscription::FROZEN, Subscription::PENDING])
                ->with('plan')
                ->lockForUpdate()
                ->get()
                ->each(function (Subscription $sub) use ($shop, $occurredAt, $source) {
                    if ($sub->status === Subscription::PENDING) {
                        $sub->update(['status' => Subscription::CANCELLED, 'cancelled_at' => now(), 'cancellation_reason' => 'uninstalled', 'ends_at' => now()]);

                        return;
                    }

                    $this->end($shop, $sub, Subscription::CANCELLED, 'uninstalled', $source, prorated: false, occurredAt: $occurredAt);
                });
        });

        $shop->forgetPlan();
    }

    /**
     * A previously uninstalled shop has a token again. Anything the previous
     * installation left active is ended (Shopify cancelled it), then Shopify is
     * asked what is really live now.
     */
    public function handleReinstall(Shop $shop): void
    {
        $this->handleUninstall($shop, source: 'system');

        BillingEvent::record($shop, 'app_reinstalled', 'system', message: 'App reinstalled; billing reconciled with Shopify.');

        $this->sync($shop, 'system');
    }

    /**
     * app_subscriptions/update — HMAC already verified by the controller.
     *
     * The payload is applied at once, so the change shows even if no queue worker
     * is running, then a full reconcile is queued. Deliveries are ordered by
     * Shopify's updated_at: an older (or duplicate) delivery never overwrites a
     * newer state.
     */
    public function handleSubscriptionUpdate(Shop $shop, array $payload): void
    {
        $data = $payload['app_subscription'] ?? [];
        $id = $data['admin_graphql_api_id'] ?? null;
        $status = strtoupper((string) ($data['status'] ?? ''));
        $updatedAt = ! empty($data['updated_at']) ? Carbon::parse($data['updated_at']) : null;

        if ($id && $status !== '') {
            try {
                $this->locked($shop, 10, function () use ($shop, $id, $status, $updatedAt, $data) {
                    $row = Subscription::where('shop_id', $shop->id)->where('shopify_charge_id', $id)->with('plan')->first();

                    if (! $row) {
                        return; // Not ours yet — the queued reconcile adopts it.
                    }

                    if ($updatedAt && $row->shopify_updated_at && $updatedAt->lte($row->shopify_updated_at)) {
                        Log::info('billing.webhook_out_of_order', ['shop' => $shop->shopify_domain, 'subscription' => $id, 'status' => $status]);

                        return;
                    }

                    $node = [
                        'id' => $id,
                        'status' => $status,
                        'price' => $data['price'] ?? null,
                        'currency' => $data['currency'] ?? null,
                        'interval' => isset($data['interval']) ? strtoupper((string) $data['interval']) : null,
                    ];

                    DB::transaction(function () use ($shop, $row, $node, $updatedAt) {
                        $row->forceFill(array_filter([
                            'shopify_status' => $node['status'],
                            'shopify_updated_at' => $updatedAt,
                            'price' => is_numeric($node['price']) ? $node['price'] : null,
                            'currency' => $node['currency'],
                            'interval' => in_array($node['interval'], ['EVERY_30_DAYS', 'ANNUAL'], true) ? $node['interval'] : null,
                        ], fn ($v) => $v !== null))->save();

                        // A webhook can't tell a deferred replacement from an immediate
                        // one, so ACTIVE is applied as current; the queued reconcile
                        // corrects it against the full picture.
                        $this->transition($shop, $row, $node, isCurrent: $node['status'] === 'ACTIVE', source: 'webhook', occurredAt: $updatedAt);
                    });
                });
            } catch (BillingException) {
                // Another billing operation holds the lock; the queued reconcile applies it.
            }
        }

        $shop->forgetPlan();

        if ($shop->access_token) {
            SyncShopBillingJob::dispatch($shop->id, 'webhook');
        }
    }

    // =====================================================================
    // Synchronisation
    // =====================================================================

    /**
     * Reconcile with Shopify. Returns false when Shopify could not be asked — in
     * which case NOTHING local changes: a failed lookup never downgrades anyone.
     */
    public function sync(Shop $shop, string $source = 'sync'): bool
    {
        try {
            return $this->locked($shop, 10, fn () => $this->syncUnlocked($shop, $source));
        } catch (BillingException) {
            return false;
        }
    }

    /** Sync only when the last successful one is older than $maxAgeSeconds. */
    public function syncIfStale(Shop $shop, int $maxAgeSeconds, string $source = 'sync'): bool
    {
        if ($shop->billing_synced_at && $shop->billing_synced_at->gt(now()->subSeconds($maxAgeSeconds))) {
            return true;
        }

        return $this->sync($shop, $source);
    }

    /** HMAC over the return URL's shop and plan, so a forged callback can't drive billing. */
    public static function returnSignature(string $shopDomain, string $planCode): string
    {
        return hash_hmac('sha256', "{$shopDomain}|{$planCode}", ShopifyOAuth::apiSecret());
    }

    // =====================================================================
    // Internals
    // =====================================================================

    private function syncUnlocked(Shop $shop, string $source): bool
    {
        $state = $this->fetchState($shop, $source);

        if ($state === null) {
            return false;
        }

        $this->reconcile($shop, $state, $source);

        $shop->forceFill(['billing_synced_at' => now()])->save();
        $shop->forgetPlan();

        return true;
    }

    /**
     * @param  array{active: array<string,array>, all: array<string,array>}  $state
     */
    private function reconcile(Shop $shop, array $state, string $source): void
    {
        // The currently billed subscription is the OLDEST active one. A second
        // ACTIVE one only exists while a replacement is deferred to the next
        // billing cycle (STANDARD annual rules).
        $activeIds = collect($state['active'])
            ->sortBy(fn ($n) => $n['createdAt'] ?? '')
            ->keys()
            ->all();
        $currentId = $activeIds[0] ?? null;
        $nodes = $state['all'] + $state['active'];

        DB::transaction(function () use ($shop, $state, $nodes, $currentId, $activeIds, $source) {
            $local = Subscription::where('shop_id', $shop->id)->with('plan')->lockForUpdate()->get();

            // A replacement Shopify has deferred to the next billing cycle is live
            // but not current. Demote it BEFORE anything is made current, or two
            // rows would be active at once (the database refuses that).
            foreach ($local as $row) {
                if ($row->status === Subscription::ACTIVE && $row->shopify_charge_id
                    && in_array($row->shopify_charge_id, $activeIds, true) && $row->shopify_charge_id !== $currentId) {
                    $row->update(['status' => Subscription::PENDING, 'approved_at' => $row->approved_at ?? now()]);
                }
            }

            // Adopt live subscriptions this app has no row for (approved while the
            // callback and webhook were lost, or created before rows existed).
            foreach ($state['active'] as $id => $node) {
                if (! $local->contains('shopify_charge_id', $id) && ($row = $this->adopt($shop, $node, $local, $source))) {
                    $local->push($row);
                }
            }

            // Oldest first, so a replacement is applied after what it replaced.
            foreach ($local->sortBy('id') as $row) {
                if (! $row->shopify_charge_id) {
                    continue;
                }

                // An earlier iteration may have ended this row (makeCurrent supersedes
                // by query); acting on the stale copy would end it twice.
                $row->refresh();

                $node = $nodes[$row->shopify_charge_id] ?? null;

                if ($node) {
                    $this->applySnapshot($row, $node);
                    $this->transition($shop, $row, $node, isCurrent: $row->shopify_charge_id === $currentId, source: $source,
                        replacedByCurrent: $currentId !== null && $row->shopify_charge_id !== $currentId, liveIds: $activeIds);

                    continue;
                }

                // Active/frozen here but not live on Shopify, and outside the
                // recent-subscriptions window: it ended while we weren't told.
                if (in_array($row->status, [Subscription::ACTIVE, Subscription::FROZEN], true)) {
                    $this->end($shop, $row, Subscription::CANCELLED, $row->cancellation_reason ?? 'shopify', $source, prorated: false);
                }
            }

            // Legacy rows never linked to a Shopify charge: when Shopify reports no
            // live subscription at all, they are not being billed.
            if ($currentId === null) {
                $local->filter(fn (Subscription $s) => ! $s->shopify_charge_id && $s->status === Subscription::ACTIVE && $s->isPaid())
                    ->each(fn (Subscription $s) => $this->end($shop, $s, Subscription::CANCELLED, 'shopify', $source, prorated: true,
                        message: 'No matching subscription exists on Shopify.'));
            }
        });
    }

    /**
     * Move one local row to the state Shopify reports for it.
     *
     * @param  array  $node  AppSubscription as returned by Shopify (or built from a webhook)
     */
    private function transition(Shop $shop, Subscription $row, array $node, bool $isCurrent, string $source, ?CarbonInterface $occurredAt = null, bool $replacedByCurrent = false, array $liveIds = []): void
    {
        $status = $node['status'] ?? null;

        switch ($status) {
            case 'ACTIVE':
                if ($isCurrent) {
                    if ($row->status === Subscription::ACTIVE) {
                        $this->refreshPeriod($shop, $row, $node, $source);
                    } else {
                        $this->makeCurrent($shop, $row, $node, $source, $occurredAt, $liveIds);
                    }

                    return;
                }

                // Approved but deferred behind the current subscription.
                if ($row->status === Subscription::PENDING) {
                    $firstTime = $row->approved_at === null || ! BillingEvent::where('subscription_id', $row->id)->where('event', 'plan_change_scheduled')->exists();
                    $row->update(['approved_at' => $row->approved_at ?? now()]);

                    if ($firstTime) {
                        BillingEvent::record($shop, 'plan_change_scheduled', $source, $row,
                            "Change to {$row->plan?->name} approved; Shopify applies it at the start of the next billing cycle.",
                            occurredAt: $occurredAt);
                    }
                }

                return;

            case 'FROZEN':
                if ($row->status !== Subscription::FROZEN && in_array($row->status, [Subscription::ACTIVE, Subscription::PENDING], true)) {
                    $row->update(['status' => Subscription::FROZEN]);
                    BillingEvent::record($shop, 'subscription_frozen', $source, $row,
                        'Shopify put the subscription on hold because a Shopify bill is unpaid. Paid features are paused until it is paid.',
                        occurredAt: $occurredAt);
                }

                return;

            case 'CANCELLED':
                if ($row->status === Subscription::PENDING) {
                    $row->update(['status' => Subscription::CANCELLED, 'cancelled_at' => now(), 'cancellation_reason' => $row->cancellation_reason ?? 'shopify', 'ends_at' => now()]);

                    return;
                }

                if (in_array($row->status, [Subscription::ACTIVE, Subscription::FROZEN], true)) {
                    // Cancelled because a newer subscription replaced it — either
                    // Shopify already reports that one as current, or an open checkout
                    // names this one as its predecessor (its ACTIVE webhook can arrive
                    // after this CANCELLED one, and before the merchant's callback).
                    $replaced = $replacedByCurrent || Subscription::where('shop_id', $shop->id)
                        ->where('previous_subscription_id', $row->id)
                        ->whereIn('status', [Subscription::PENDING, Subscription::ACTIVE])
                        ->where('created_at', '>', now()->subHours(self::PENDING_LIFETIME_HOURS))
                        ->exists();

                    $this->end($shop, $row, Subscription::CANCELLED, $replaced ? 'replaced' : ($row->cancellation_reason ?? 'shopify'), $source, prorated: false, occurredAt: $occurredAt);
                }

                return;

            case 'DECLINED':
            case 'EXPIRED':
                if ($row->status === Subscription::PENDING) {
                    $declined = $status === 'DECLINED';
                    $row->update([
                        'status' => $declined ? Subscription::DECLINED : Subscription::EXPIRED,
                        'cancelled_at' => now(),
                        'cancellation_reason' => $declined ? 'declined' : 'not_approved',
                        'ends_at' => now(),
                    ]);
                    BillingEvent::record($shop, $declined ? 'checkout_declined' : 'checkout_expired', $source, $row,
                        $declined
                            ? "The {$row->plan?->name} subscription was declined in Shopify. Nothing was charged."
                            : "The {$row->plan?->name} subscription wasn't approved within two days, so Shopify expired it. Nothing was charged.",
                        occurredAt: $occurredAt);
                } elseif ($status === 'EXPIRED' && in_array($row->status, [Subscription::ACTIVE, Subscription::FROZEN], true)) {
                    $this->end($shop, $row, Subscription::EXPIRED, 'expired', $source, prorated: true, occurredAt: $occurredAt);
                }

                return;

            default:
                // PENDING, and the deprecated ACCEPTED: nothing to do until Shopify decides.
                return;
        }
    }

    /** $row becomes the shop's one active subscription; whatever it replaces ends. */
    private function makeCurrent(Shop $shop, Subscription $row, array $node, string $source, ?CarbonInterface $occurredAt, array $liveIds = []): void
    {
        $wasFrozen = $row->status === Subscription::FROZEN;

        $replaced = Subscription::where('shop_id', $shop->id)
            ->whereKeyNot($row->id)
            ->where(fn ($q) => $q->whereIn('status', [Subscription::ACTIVE, Subscription::FROZEN])
                ->orWhere(fn ($q) => $q->where('status', Subscription::CANCELLED)->where('ends_at', '>', now())))
            // Still live on Shopify (a deferred replacement): not replaced by this.
            ->where(fn ($q) => $q->whereNull('shopify_charge_id')->orWhereNotIn('shopify_charge_id', $liveIds ?: ['']))
            ->with('plan')
            ->orderByDesc('id')
            ->get();

        foreach ($replaced as $old) {
            if (in_array($old->status, [Subscription::ACTIVE, Subscription::FROZEN], true)) {
                // Shopify cancels a replaced subscription itself and settles its
                // unused days through proration — no extra access here.
                $this->end($shop, $old, Subscription::CANCELLED, 'replaced', $source, prorated: true, occurredAt: $occurredAt);
            } else {
                // Cancelled earlier but still inside its paid period: the new
                // subscription takes over now.
                $old->update(['ends_at' => $occurredAt ?? now()]);

                if ($old->isPaid()) {
                    BillingEvent::record($shop, 'previous_plan_ended', $source, $old,
                        "{$old->plan?->name} access ended — {$row->plan?->name} started.", occurredAt: $occurredAt);
                }
            }
        }

        $previous = $replaced->first(fn (Subscription $s) => $s->isPaid())
            ?? ($row->previous_subscription_id ? Subscription::with('plan')->find($row->previous_subscription_id) : null);
        $previousPaid = $previous?->isPaid() && $previous->plan_id !== $row->plan_id ? $previous : null;

        $row->update([
            'status' => Subscription::ACTIVE,
            'previous_subscription_id' => $row->previous_subscription_id ?? $previousPaid?->id,
            'started_at' => $row->started_at ?? $occurredAt ?? now(),
            'approved_at' => $row->approved_at ?? now(),
            'current_period_end' => $this->periodEnd($node) ?? $row->current_period_end,
            'cancelled_at' => null,
            'cancellation_reason' => null,
            'ends_at' => null,
            'confirmation_url' => null,
        ]);

        // Dates live in context, not in the message: the UI formats them in the
        // merchant's own timezone, which a server-rendered date would contradict.
        $period = ['current_period_end' => $row->current_period_end?->toIso8601String()];

        match (true) {
            $wasFrozen => BillingEvent::record($shop, 'subscription_unfrozen', $source, $row, "Payment resumed; {$row->plan?->name} is active again.", $period, $occurredAt),
            $previousPaid !== null => BillingEvent::record($shop, 'plan_changed', $source, $row,
                "Plan changed from {$previousPaid->plan?->name} to {$row->plan?->name}.",
                $period + ['from_plan' => $previousPaid->plan?->code, 'to_plan' => $row->plan?->code, 'previous_subscription_id' => $previousPaid->id],
                $occurredAt),
            default => BillingEvent::record($shop, 'subscription_activated', $source, $row, "{$row->plan?->name} subscription started.", $period, $occurredAt),
        };

        $shop->forgetPlan();
    }

    /** An already-active subscription: keep its period current and record renewals. */
    private function refreshPeriod(Shop $shop, Subscription $row, array $node, string $source): void
    {
        $new = $this->periodEnd($node);
        $old = $row->current_period_end;

        if ($new && $old && $new->gt($old)) {
            // The renewal happened at the old period end — Shopify's own boundary.
            BillingEvent::record($shop, 'subscription_renewed', $source, $row,
                "{$row->plan?->name} renewed.",
                ['previous_period_end' => $old->toIso8601String(), 'current_period_end' => $new->toIso8601String()],
                $old->isPast() ? $old : null);
        }

        if ($new && (! $old || ! $new->equalTo($old))) {
            $row->update(['current_period_end' => $new]);
        }
    }

    /**
     * Take a subscription out of service.
     *
     * $prorated false: a cancelled PAID subscription keeps its plan until the end
     * of the period already paid for (Shopify's rule on uninstall; also what a
     * non-prorated appSubscriptionCancel means). Otherwise it ends now.
     */
    private function end(Shop $shop, Subscription $sub, string $status, string $reason, string $source, bool $prorated, ?CarbonInterface $occurredAt = null, ?string $message = null): void
    {
        $sub->loadMissing('plan');
        $endedAt = $occurredAt ?? now();
        $periodEnd = $sub->current_period_end;

        $paidThrough = $status === Subscription::CANCELLED
            && ! $prorated
            && $reason !== 'replaced'
            && $sub->isPaid()
            && $periodEnd?->isAfter($endedAt);

        $sub->update([
            'status' => $status,
            'shopify_status' => $sub->shopify_charge_id ? strtoupper($status) : $sub->shopify_status,
            'cancelled_at' => $sub->cancelled_at ?? $endedAt,
            'cancellation_reason' => $reason,
            'ends_at' => $paidThrough ? $periodEnd : $endedAt,
            'confirmation_url' => null,
        ]);

        if ($sub->isPaid() && $reason === 'replaced') {
            BillingEvent::record($shop, 'previous_plan_ended', $source, $sub,
                "{$sub->plan?->name} ended — replaced by a new plan.", ['reason' => $reason], $occurredAt);
        } elseif ($sub->isPaid()) {
            $because = match ($reason) {
                'uninstalled' => ' when the app was uninstalled',
                'merchant' => '',
                'admin' => ' by Store Shift support',
                'expired' => '',
                default => ' on Shopify',
            };
            $verb = $status === Subscription::EXPIRED ? 'expired' : 'was cancelled';

            BillingEvent::record($shop, $status === Subscription::EXPIRED ? 'subscription_expired' : 'subscription_cancelled', $source, $sub,
                $message ?? ("{$sub->plan?->name} {$verb}{$because}. ".($paidThrough
                    ? "Already paid for, so it stays available until the end of the billing period and won't renew."
                    : 'It has ended and will not renew.')),
                ['reason' => $reason, 'prorated' => $prorated, 'access_until' => $paidThrough ? $periodEnd->toIso8601String() : null],
                $occurredAt);
        }

        $shop->forgetPlan();
    }

    /** @throws BillingException */
    private function cancelUnlocked(Shop $shop, string $source, ?bool $prorate, ?string $note): Subscription
    {
        $prorate ??= (bool) config('services.shopify.billing_prorate_cancellations', false);
        $sub = $this->activePaidRow($shop, includeFrozen: true);

        if (! $sub) {
            $through = $shop->entitlement();

            throw new BillingException('no_subscription', $through['basis'] === 'paid_through'
                ? "Your {$through['plan']?->name} subscription is already cancelled. It stays available until {$through['subscription']->ends_at->toFormattedDateString()}."
                : "You don't have an active paid subscription to cancel.");
        }

        if (! $sub->shopify_charge_id) {
            throw new BillingException('not_linked', "This subscription isn't linked to a Shopify charge yet, so it can't be cancelled from here. Please contact support.");
        }

        BillingEvent::record($shop, 'cancellation_requested', $source, $sub,
            ($source === 'admin' ? 'Cancellation requested by Store Shift support' : 'Cancellation requested').($note ? ": {$note}" : '.'),
            ['prorate' => $prorate]);

        $reference = (string) Str::uuid();
        $failure = null;

        try {
            $client = new ShopifyClient($shop);
            $body = $client->graphql(self::CANCEL_MUTATION, ['id' => $sub->shopify_charge_id, 'prorate' => $prorate]);
            $errors = $client->userErrors($body, 'appSubscriptionCancel') ?: $client->topLevelErrors($body);
            $status = data_get($body, 'data.appSubscriptionCancel.appSubscription.status');

            if ($errors || $status !== 'CANCELLED') {
                $failure = $errors ? json_encode($errors) : 'Unexpected status: '.json_encode($status);
            }
        } catch (\Throwable $e) {
            $failure = $e->getMessage();
        }

        if ($failure !== null) {
            // "Already cancelled" also arrives as an error: ask Shopify what is true.
            if ($this->syncUnlocked($shop, $source) && $sub->refresh()->status !== Subscription::ACTIVE) {
                return $sub;
            }

            $this->failure($shop, 'cancel', $reference, $failure, $sub, ['prorate' => $prorate, 'source' => $source]);

            throw BillingException::shopifyFailure(
                "We couldn't cancel your subscription right now. Your plan hasn't changed and you won't be charged differently. Please try again in a few minutes.",
                $reference,
            );
        }

        $this->end($shop, $sub, Subscription::CANCELLED, $source === 'admin' ? 'admin' : 'merchant', $source, $prorate);

        return $sub->refresh();
    }

    /** @throws BillingException */
    private function createCheckout(Shop $shop, Plan $plan, ?Subscription $current, string $source): array
    {
        $returnUrl = rtrim(ShopifyOAuth::appUrl(), '/').'/api/billing/callback?'.http_build_query([
            'shop' => $shop->shopify_domain,
            'plan' => $plan->code,
            'sig' => self::returnSignature($shop->shopify_domain, $plan->code),
        ]);

        $test = (bool) config('services.shopify.billing_test', false);
        $reference = (string) Str::uuid();
        $preview = self::changePreview($current, $plan);

        try {
            $client = new ShopifyClient($shop);
            $body = $client->graphql(self::CREATE_MUTATION, [
                'name' => $plan->name,
                'returnUrl' => $returnUrl,
                'test' => $test,
                // Explicit rather than implied, so the preview above stays true.
                'replacementBehavior' => 'STANDARD',
                'lineItems' => [[
                    'plan' => [
                        'appRecurringPricingDetails' => [
                            'price' => ['amount' => (float) $plan->price, 'currencyCode' => 'USD'],
                            'interval' => $plan->shopifyInterval(),
                        ],
                    ],
                ]],
            ]);
            $errors = $client->userErrors($body, 'appSubscriptionCreate') ?: $client->topLevelErrors($body);
        } catch (\Throwable $e) {
            $body = [];
            $errors = [$e->getMessage()];
        }

        $url = data_get($body, 'data.appSubscriptionCreate.confirmationUrl');
        $chargeId = data_get($body, 'data.appSubscriptionCreate.appSubscription.id');

        if ($errors || ! $url || ! $chargeId) {
            $this->failure($shop, 'subscribe', $reference, $errors ?: 'No confirmation URL or subscription id returned', null, ['plan' => $plan->code, 'source' => $source]);

            throw BillingException::shopifyFailure(
                "We couldn't start the {$plan->name} subscription right now. Your current plan hasn't changed. Please try again.",
                $reference,
            );
        }

        $row = Subscription::create([
            'shop_id' => $shop->id,
            'plan_id' => $plan->id,
            'previous_subscription_id' => $current?->id,
            'status' => Subscription::PENDING,
            'shopify_charge_id' => $chargeId,
            'shopify_status' => data_get($body, 'data.appSubscriptionCreate.appSubscription.status', 'PENDING'),
            'shopify_created_at' => ($created = data_get($body, 'data.appSubscriptionCreate.appSubscription.createdAt')) ? Carbon::parse($created) : null,
            'price' => $plan->price,
            'currency' => 'USD',
            'interval' => $plan->shopifyInterval(),
            'test' => (bool) data_get($body, 'data.appSubscriptionCreate.appSubscription.test', $test),
            'confirmation_url' => $url,
        ]);

        BillingEvent::record($shop, 'checkout_started', $source, $row,
            "Started {$plan->name} subscription — waiting for approval in Shopify.",
            ['change' => $preview, 'test' => $row->test]);

        return ['result' => 'confirmation_required', 'confirmation_url' => $url, 'subscription' => $row, 'reused' => false];
    }

    /**
     * A still-open checkout for the same plan, so repeat clicks never create a
     * second charge. When Shopify was just consulted, anything still PENDING there
     * within its two-day life qualifies; otherwise only a very recent one.
     */
    private function reusableCheckout(Shop $shop, Plan $plan, bool $verified): ?Subscription
    {
        $row = Subscription::where('shop_id', $shop->id)
            ->where('plan_id', $plan->id)
            ->where('status', Subscription::PENDING)
            ->whereNull('approved_at')
            ->whereNotNull('confirmation_url')
            ->latest('id')
            ->first();

        if (! $row) {
            return null;
        }

        $limit = $verified ? now()->subHours(self::PENDING_LIFETIME_HOURS - 1) : now()->subMinutes(self::REUSE_UNVERIFIED_MINUTES);

        return $row->created_at->gt($limit) && (! $verified || $row->shopify_status === 'PENDING') ? $row : null;
    }

    /** Create a local row for a live Shopify subscription we have no record of. */
    private function adopt(Shop $shop, array $node, $local, string $source): ?Subscription
    {
        $plan = $this->planFor($node);

        if (! $plan) {
            $this->failure($shop, 'adopt', (string) Str::uuid(), 'Active Shopify subscription matches no plan', null,
                ['subscription' => $node['id'], 'name' => $node['name'] ?? null], event: 'billing_sync_unmatched');

            return null;
        }

        // A legacy row for the same plan with no charge id is this subscription.
        $legacy = $local->first(fn (Subscription $s) => ! $s->shopify_charge_id
            && $s->plan_id === $plan->id
            && in_array($s->status, [Subscription::ACTIVE, Subscription::PENDING], true));

        if ($legacy) {
            $legacy->update(['shopify_charge_id' => $node['id']]);

            return $legacy;
        }

        return Subscription::create([
            'shop_id' => $shop->id,
            'plan_id' => $plan->id,
            'status' => Subscription::PENDING, // transition() makes it current
            'shopify_charge_id' => $node['id'],
        ])->setRelation('plan', $plan);
    }

    /**
     * Which plan a Shopify subscription is for: the plan code in its return URL
     * (this app wrote it), else its name, else a unique price + interval match.
     */
    private function planFor(array $node): ?Plan
    {
        parse_str((string) parse_url((string) ($node['returnUrl'] ?? ''), PHP_URL_QUERY), $query);

        if (! empty($query['plan']) && ($plan = Plan::where('code', $query['plan'])->first())) {
            return $plan;
        }

        if (! empty($node['name']) && ($plan = Plan::where('name', $node['name'])->first())) {
            return $plan;
        }

        $pricing = $this->recurringPricing($node);

        if ($pricing['price'] !== null) {
            $matches = Plan::where('price', $pricing['price'])->get()
                ->filter(fn (Plan $p) => $pricing['interval'] === null || $p->shopifyInterval() === $pricing['interval']);

            if ($matches->count() === 1) {
                return $matches->first();
            }
        }

        return null;
    }

    /** Copy what Shopify says about the charge itself onto the row. */
    private function applySnapshot(Subscription $row, array $node): void
    {
        $pricing = $this->recurringPricing($node);

        $row->fill(array_filter([
            'shopify_status' => $node['status'] ?? null,
            'price' => $pricing['price'],
            'currency' => $pricing['currency'],
            'interval' => $pricing['interval'],
            'trial_days' => isset($node['trialDays']) ? (int) $node['trialDays'] : null,
            'shopify_created_at' => ! empty($node['createdAt']) ? Carbon::parse($node['createdAt']) : null,
        ], fn ($v) => $v !== null));

        if (array_key_exists('test', $node)) {
            $row->test = (bool) $node['test'];
        }

        if ($row->isDirty()) {
            $row->save();
        }
    }

    /** @return array{price: ?string, currency: ?string, interval: ?string} */
    private function recurringPricing(array $node): array
    {
        foreach ($node['lineItems'] ?? [] as $item) {
            $details = data_get($item, 'plan.pricingDetails');

            if (($details['__typename'] ?? null) === 'AppRecurringPricing') {
                return [
                    'price' => isset($details['price']['amount']) ? (string) $details['price']['amount'] : null,
                    'currency' => $details['price']['currencyCode'] ?? null,
                    'interval' => $details['interval'] ?? null,
                ];
            }
        }

        return ['price' => null, 'currency' => null, 'interval' => null];
    }

    /**
     * @return array{active: array<string,array>, all: array<string,array>}|null
     */
    private function fetchState(Shop $shop, string $source): ?array
    {
        if (! $shop->access_token) {
            return null;
        }

        $reference = (string) Str::uuid();

        try {
            $body = (new ShopifyClient($shop))->graphql(self::STATE_QUERY.self::SUBSCRIPTION_FIELDS);
        } catch (RequestException $e) {
            if ($e->response->status() === 401) {
                // Revoked — the app was uninstalled, possibly with the webhook
                // missed. Drop the dead token so the next embedded request
                // exchanges a fresh one instead of failing the same way forever.
                $shop->forceFill(['access_token' => null, 'refresh_token' => null])->save();
            }

            $this->syncFailed($shop, $reference, $e->getMessage(), $source);

            return null;
        } catch (\Throwable $e) {
            $this->syncFailed($shop, $reference, $e->getMessage(), $source);

            return null;
        }

        $installation = data_get($body, 'data.currentAppInstallation');

        if (! is_array($installation)) {
            $this->syncFailed($shop, $reference, 'No currentAppInstallation: '.json_encode(data_get($body, 'errors')), $source);

            return null;
        }

        $keyed = fn (array $list) => collect($list)->filter(fn ($s) => isset($s['id'], $s['status']))->keyBy('id')->all();

        return [
            'active' => collect($keyed($installation['activeSubscriptions'] ?? []))
                ->filter(fn ($s) => $s['status'] === 'ACTIVE')
                ->all(),
            'all' => $keyed(data_get($installation, 'allSubscriptions.nodes', [])),
        ];
    }

    private function syncFailed(Shop $shop, string $reference, string $error, string $source): void
    {
        Log::warning('billing.sync_failed', ['shop' => $shop->shopify_domain, 'source' => $source, 'reference' => $reference, 'error' => $error]);

        // One event per shop per 15 minutes: a Shopify outage must not bury the
        // history under identical failure rows every time a page loads.
        if (Cache::add("billing:sync-failed-event:{$shop->id}", 1, now()->addMinutes(15))) {
            BillingEvent::record($shop, 'billing_sync_failed', $source, null,
                'Could not read the subscription from Shopify.', ['reference' => $reference, 'error' => mb_substr($error, 0, 400)]);
        }
    }

    /** Log a failed operation and record it for admins, without secrets. */
    private function failure(Shop $shop, string $operation, string $reference, mixed $error, ?Subscription $sub, array $context = [], string $event = 'billing_operation_failed'): void
    {
        $error = is_string($error) ? $error : json_encode($error);

        Log::warning("billing.{$operation}_failed", $context + [
            'shop' => $shop->shopify_domain,
            'subscription' => $sub?->shopify_charge_id,
            'plan' => $sub?->plan?->code ?? ($context['plan'] ?? null),
            'reference' => $reference,
            'request_id' => request()?->header('X-Request-Id'),
            'error' => $error,
        ]);

        BillingEvent::record($shop, $event, $context['source'] ?? 'system', $sub,
            ucfirst($operation).' failed.', $context + ['reference' => $reference, 'error' => mb_substr($error, 0, 400)]);
    }

    /**
     * Run $callback holding the shop's billing lock, so two billing operations
     * for one shop never interleave (double clicks, callback racing a webhook).
     *
     * @throws BillingException when the lock is busy for longer than $waitSeconds
     */
    private function locked(Shop $shop, int $waitSeconds, callable $callback): mixed
    {
        $lock = Cache::lock("billing:shop:{$shop->id}", 60);

        try {
            $acquired = $waitSeconds > 0 ? $lock->block($waitSeconds) : $lock->get();
        } catch (LockTimeoutException) {
            $acquired = false;
        }

        if (! $acquired) {
            throw BillingException::inProgress();
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }

    private function periodEnd(array $node): ?CarbonInterface
    {
        return empty($node['currentPeriodEnd']) ? null : Carbon::parse($node['currentPeriodEnd']);
    }

    private function activePaidRow(Shop $shop, bool $includeFrozen = false): ?Subscription
    {
        return Subscription::where('shop_id', $shop->id)
            ->whereIn('status', $includeFrozen ? [Subscription::ACTIVE, Subscription::FROZEN] : [Subscription::ACTIVE])
            ->with('plan')
            ->orderByDesc('id')
            ->get()
            ->first(fn (Subscription $s) => $s->isPaid());
    }
}
