<?php

namespace App\Support;

use App\Models\BillingEvent;
use App\Models\Plan;
use App\Models\Shop;
use App\Models\Subscription;
use App\Services\ShopifyBilling;
use Carbon\CarbonInterface;

/**
 * The one normalised description of a shop's billing, rendered by BOTH the
 * merchant app (GET /api/billing) and the admin panel. Built only from stored
 * data — every date is a value Shopify reported or an event that was recorded;
 * nothing is estimated. A date Shopify doesn't provide is null.
 *
 * state:
 *   active     paying; renews on renews_at
 *   frozen     Shopify put it on hold for non-payment; paid features paused
 *   unknown    marked active, but its renewal hasn't been confirmed with Shopify
 *              for Shop::UNVERIFIED_AFTER_DAYS — needs a sync
 *   cancelled  cancelled and won't renew, but already paid for: plan kept until access_until
 *   pending    first subscription created, waiting for approval in Shopify
 *   ended      a paid plan existed and has ended; on the free plan
 *   free       never had a paid plan
 */
class BillingState
{
    public static function for(Shop $shop, bool $forAdmin = false): array
    {
        $shop->forgetPlan();
        $entitlement = $shop->entitlement();

        $rows = $shop->subscriptions()->with(['plan', 'previous.plan'])->orderByDesc('id')->get();

        $unverified = $rows->first(fn (Subscription $s) => $s->isPaid() && Shop::renewalUnverified($s));
        $frozen = $rows->first(fn (Subscription $s) => $s->status === Subscription::FROZEN && $s->isPaid());
        $openCheckouts = $rows->filter(fn (Subscription $s) => $s->status === Subscription::PENDING
            && $s->created_at->gt(now()->subHours(ShopifyBilling::PENDING_LIFETIME_HOURS)));
        $lastPaid = $rows->first(fn (Subscription $s) => $s->isPaid()
            && $s->started_at !== null
            && in_array($s->status, [Subscription::ACTIVE, Subscription::FROZEN, Subscription::CANCELLED, Subscription::EXPIRED], true));

        [$state, $subject] = match (true) {
            $entitlement['basis'] === 'active' => ['active', $entitlement['subscription']],
            $unverified !== null => ['unknown', $unverified],
            $frozen !== null => ['frozen', $frozen],
            $entitlement['basis'] === 'paid_through' => ['cancelled', $entitlement['subscription']],
            $openCheckouts->isNotEmpty() && $lastPaid === null => ['pending', $openCheckouts->first()],
            $lastPaid !== null => ['ended', $lastPaid],
            default => ['free', null],
        };

        $pendingChange = $state === 'pending' ? null : $openCheckouts->first();
        $previous = self::previousOf($subject, $rows);
        $cancellation = app(ShopifyBilling::class)->cancellationPreview($shop);
        $syncFailedAt = BillingEvent::where('shop_id', $shop->id)->where('event', 'billing_sync_failed')->latest('occurred_at')->value('occurred_at');

        $out = [
            'state' => $state,
            'status' => $subject?->status,
            'shopify_status' => $subject?->shopify_status,
            'plan' => self::plan($subject?->plan),
            'price' => $subject ? (float) ($subject->price ?? $subject->plan?->price) : null,
            'currency' => $subject?->currency ?? ($subject ? 'USD' : null),
            'interval' => $subject?->interval ?? $subject?->plan?->shopifyInterval(),
            'test' => (bool) $subject?->test,
            'trial_days' => $subject?->trial_days,

            'created_at' => self::date($subject?->shopify_created_at ?? $subject?->created_at),
            'started_at' => self::date($subject?->started_at),
            'current_period_end' => self::date($subject?->current_period_end),
            'renews_at' => $state === 'active' ? self::date($subject->current_period_end) : null,
            'cancelled_at' => in_array($state, ['cancelled', 'ended'], true) ? self::date($subject->cancelled_at) : null,
            'cancellation_reason' => in_array($state, ['cancelled', 'ended'], true) ? $subject->cancellation_reason : null,
            'access_until' => $state === 'cancelled' ? self::date($subject->ends_at) : null,
            'ended_at' => $state === 'ended' ? self::date($subject->ends_at) : null,

            // The plan in force right now — may differ from `plan` (e.g. frozen: the
            // subscription is Basic but the store gets Starter until payment resumes).
            'entitled_plan' => self::plan($entitlement['plan']),

            'previous' => $previous ? [
                'plan' => self::plan($previous->plan),
                'status' => $previous->status,
                'ended_at' => self::date($previous->ends_at),
                'reason' => $previous->cancellation_reason,
            ] : null,

            'pending_change' => $pendingChange ? [
                'plan' => self::plan($pendingChange->plan),
                'price' => (float) ($pendingChange->price ?? $pendingChange->plan?->price),
                'interval' => $pendingChange->interval ?? $pendingChange->plan?->shopifyInterval(),
                'created_at' => self::date($pendingChange->created_at),
                // approved + ACTIVE on Shopify but not current => deferred to the next cycle
                'status' => match (true) {
                    $pendingChange->approved_at && $pendingChange->shopify_status === 'ACTIVE' => 'scheduled',
                    $pendingChange->approved_at !== null => 'processing',
                    default => 'awaiting_approval',
                },
                'effective_at' => $pendingChange->approved_at && $pendingChange->shopify_status === 'ACTIVE'
                    ? self::date($subject?->current_period_end)
                    : null,
                'expires_at' => $pendingChange->approved_at ? null : self::date($pendingChange->created_at->copy()->addHours(ShopifyBilling::PENDING_LIFETIME_HOURS)),
            ] : null,

            'sync' => [
                'last_synced_at' => self::date($shop->billing_synced_at),
                'failing' => $syncFailedAt !== null && ($shop->billing_synced_at === null || $shop->billing_synced_at->lt($syncFailedAt)),
            ],

            'actions' => [
                'can_cancel' => in_array($state, ['active', 'frozen', 'unknown'], true) && $subject?->shopify_charge_id !== null,
                'can_change_plan' => $state !== 'frozen',
                'can_resubscribe' => in_array($state, ['cancelled', 'ended'], true),
                'can_continue_checkout' => $state === 'pending'
                    || ($pendingChange !== null && $pendingChange->approved_at === null),
            ],

            // What "Cancel" would do right now, so the dialog states it before anything happens.
            'cancellation' => $cancellation,
        ];

        if ($forAdmin) {
            $out['admin'] = [
                'shopify_subscription_id' => $subject?->shopify_charge_id,
                'shopify_updated_at' => self::date($subject?->shopify_updated_at),
                'approved_at' => self::date($subject?->approved_at),
                'local_subscription_id' => $subject?->id,
                'entitlement_basis' => $entitlement['basis'],
                'open_checkouts' => $openCheckouts->count(),
                'last_sync_failure_at' => self::date($syncFailedAt),
            ];
        }

        return $out;
    }

    /** Every subscription row, newest first — the admin panel's full record. */
    public static function subscriptionRows(Shop $shop): array
    {
        return $shop->subscriptions()->with(['plan', 'previous.plan'])->orderByDesc('id')->get()
            ->map(fn (Subscription $s) => [
                'id' => $s->id,
                'plan' => $s->plan?->name,
                'plan_code' => $s->plan?->code,
                'status' => $s->status,
                'shopify_status' => $s->shopify_status,
                'shopify_subscription_id' => $s->shopify_charge_id,
                'price' => $s->price !== null ? (float) $s->price : null,
                'currency' => $s->currency,
                'interval' => $s->interval,
                'test' => $s->test,
                'created_at' => self::date($s->shopify_created_at ?? $s->created_at),
                'approved_at' => self::date($s->approved_at),
                'started_at' => self::date($s->started_at),
                'current_period_end' => self::date($s->current_period_end),
                'cancelled_at' => self::date($s->cancelled_at),
                'cancellation_reason' => $s->cancellation_reason,
                'ends_at' => self::date($s->ends_at),
                'previous_plan' => $s->previous?->plan?->name,
            ])
            ->all();
    }

    /** History, newest first. Merchants see lifecycle events; admins see everything. */
    public static function history(Shop $shop, bool $forAdmin = false, int $limit = 50): array
    {
        return BillingEvent::where('shop_id', $shop->id)
            ->when(! $forAdmin, fn ($q) => $q->whereIn('event', BillingEvent::MERCHANT_VISIBLE))
            ->with('plan')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (BillingEvent $e) => array_filter([
                'id' => $e->id,
                'event' => $e->event,
                'plan' => $e->plan?->name,
                'status' => $e->status,
                'message' => $e->message,
                'occurred_at' => self::date($e->occurred_at),
                // The date the event refers to, formatted by the client in the
                // merchant's timezone: when access ends, or the next billing date.
                'access_until' => $e->context['access_until'] ?? null,
                'next_billing' => $e->context['current_period_end'] ?? null,
                'source' => $forAdmin ? $e->source : null,
                'shopify_subscription_id' => $forAdmin ? $e->shopify_subscription_id : null,
                'context' => $forAdmin ? $e->context : null,
            ], fn ($v) => $v !== null))
            ->values()
            ->all();
    }

    /** The subscription the subject replaced, or the last one that ended before it. */
    private static function previousOf(?Subscription $subject, $rows): ?Subscription
    {
        if (! $subject) {
            return null;
        }

        if ($subject->previous) {
            return $subject->previous;
        }

        return $rows->first(fn (Subscription $s) => $s->id < $subject->id
            && $s->isPaid()
            && $s->started_at !== null
            && in_array($s->status, [Subscription::CANCELLED, Subscription::EXPIRED], true));
    }

    private static function plan(?Plan $plan): ?array
    {
        return $plan ? ['code' => $plan->code, 'name' => $plan->name, 'price' => (float) $plan->price] : null;
    }

    private static function date(?CarbonInterface $date): ?string
    {
        return $date?->toIso8601String();
    }
}
