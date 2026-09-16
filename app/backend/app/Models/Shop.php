<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Shop extends Model
{
    protected $fillable = [
        'shopify_domain', 'access_token', 'refresh_token', 'token_expires_at',
        'refresh_token_expires_at', 'scopes', 'name', 'email',
        'plan_id', 'installed_at', 'uninstalled_at',
        'shopify_plan', 'api_bucket_size', 'api_restore_rate', 'limits_checked_at',
    ];

    protected $casts = [
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'token_expires_at' => 'datetime',
        'refresh_token_expires_at' => 'datetime',
        'installed_at' => 'datetime',
        'uninstalled_at' => 'datetime',
        'limits_checked_at' => 'datetime',
        'billing_synced_at' => 'datetime',
    ];

    protected $hidden = ['access_token', 'refresh_token'];

    /** A legacy (deprecated) permanent offline token has no expiry recorded. */
    public function hasLegacyToken(): bool
    {
        return $this->access_token && ! $this->token_expires_at;
    }

    /** Expiring token is past (or within 60s of) its expiry. */
    public function tokenIsExpired(): bool
    {
        return $this->token_expires_at !== null
            && $this->token_expires_at->subSeconds(60)->isPast();
    }

    public function refreshTokenIsUsable(): bool
    {
        return $this->refresh_token
            && ($this->refresh_token_expires_at === null || $this->refresh_token_expires_at->isFuture());
    }

    public function woocommerceConnection(): HasOne
    {
        return $this->hasOne(WoocommerceConnection::class)->latestOfMany();
    }

    public function migrations(): HasMany
    {
        return $this->hasMany(StoreMigration::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * The shop's current (most recent active) subscription.
     *
     * The status filter MUST sit inside ofMany(). Chained before latestOfMany()
     * it lands outside the MAX(id) subquery, which made this "the newest row, if
     * that row happens to be active" — so a declined upgrade, an abandoned charge
     * or a cancellation record created after the live subscription hid it: the
     * admin panel said "No subscription" and the app applied Starter limits to a
     * merchant who was paying.
     */
    public function activeSubscription(): HasOne
    {
        return $this->hasOne(Subscription::class)
            ->ofMany(['id' => 'max'], fn ($query) => $query->where('status', 'active'));
    }

    public function billingEvents(): HasMany
    {
        return $this->hasMany(BillingEvent::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(SupportTicket::class);
    }

    /**
     * Days an ACTIVE paid subscription keeps its plan after its billing period
     * should have renewed without this app being able to confirm the renewal.
     *
     * Shopify sends no webhook on an ordinary renewal (APP_SUBSCRIPTIONS_UPDATE
     * fires on status changes), so current_period_end only advances when a sync
     * succeeds — the hourly billing:sync does that. A period end this far in the
     * past means every sync has failed for a week; the plan is then treated as
     * unverified rather than granted indefinitely on stale data.
     */
    public const UNVERIFIED_AFTER_DAYS = 7;

    /**
     * Memoised for the life of this model instance.
     *
     * This is two queries, and it is consulted PER RECORD — planAllows() runs on
     * every product to decide whether the plan includes metafields. Unmemoised
     * that is two extra queries per product, which on a 50,000-product catalogue
     * is 100,000 queries buying nothing: a shop's plan cannot change part-way
     * through a batch, and a fresh worker gets a fresh instance anyway.
     *
     * @var array{subscription: ?Subscription, plan: ?Plan, basis: string}|null
     */
    private ?array $entitlementCache = null;

    /**
     * What this shop is entitled to right now, and why. THE rule — the merchant
     * app, the admin panel, plan gating and migrations all read it, so they can
     * never disagree (they did: after an uninstall the app showed Basic while the
     * admin panel showed no subscription).
     *
     * basis:
     *   active        an ACTIVE paid subscription
     *   paid_through  a cancelled paid subscription inside the period already paid
     *                 for (not prorated). Shopify: on uninstall "a credit isn't
     *                 applied … merchants can reinstall and use the app for the
     *                 remainder of the billing period".
     *   free          no paid entitlement — the free Starter plan
     *
     * Not entitled: pending, declined, expired and FROZEN subscriptions (Shopify
     * holds a frozen one for non-payment), and an active one whose renewal has
     * gone unconfirmed for UNVERIFIED_AFTER_DAYS.
     *
     * @return array{subscription: ?Subscription, plan: ?Plan, basis: string}
     */
    public function entitlement(): array
    {
        if ($this->entitlementCache !== null) {
            return $this->entitlementCache;
        }

        $candidates = $this->subscriptions()
            ->with('plan')
            ->where(fn ($q) => $q->where('status', Subscription::ACTIVE)
                ->orWhere(fn ($q) => $q->where('status', Subscription::CANCELLED)->where('ends_at', '>', now())))
            ->orderByDesc('id')
            ->get();

        $active = $candidates->first(fn (Subscription $s) => $s->status === Subscription::ACTIVE);

        if ($active && $active->isPaid() && ! self::renewalUnverified($active)) {
            return $this->entitlementCache = ['subscription' => $active, 'plan' => $active->plan, 'basis' => 'active'];
        }

        $paidThrough = $candidates->first(fn (Subscription $s) => $s->grantsPaidAccess());

        if ($paidThrough) {
            return $this->entitlementCache = ['subscription' => $paidThrough, 'plan' => $paidThrough->plan, 'basis' => 'paid_through'];
        }

        // A free "subscription" row from before free plans stopped being recorded.
        $freeRow = $active && ! $active->isPaid() ? $active : null;

        return $this->entitlementCache = [
            'subscription' => $freeRow,
            'plan' => $freeRow?->plan ?? Plan::where('code', 'starter')->first(),
            'basis' => 'free',
        ];
    }

    /** An active paid subscription whose renewal nobody has confirmed for a week. */
    public static function renewalUnverified(Subscription $subscription): bool
    {
        return $subscription->status === Subscription::ACTIVE
            && $subscription->current_period_end !== null
            && $subscription->current_period_end->lt(now()->subDays(self::UNVERIFIED_AFTER_DAYS));
    }

    /** The shop's effective plan. Every shop always has one. */
    public function currentPlan(): ?Plan
    {
        return $this->entitlement()['plan'];
    }

    /** Drop the memoised entitlement — for tests and for code that changes a subscription. */
    public function forgetPlan(): void
    {
        $this->entitlementCache = null;
    }

    /**
     * Limit for one entity: an int cap, or null for unlimited.
     * Unknown entities are unlimited (they aren't metered).
     */
    public function limitFor(string $entityType): ?int
    {
        $key = match ($entityType) {
            'product', 'variation', 'media' => 'products',
            'customer' => 'customers',
            'order' => 'orders',
            'coupon' => 'coupons',
            // Blogs and their posts are ONE feature to a merchant and one key
            // here. Metering them apart would let a plan sell blogs that must
            // arrive empty, which is not a product anybody wants.
            'blog', 'article' => 'blogs',
            'review' => 'reviews',
            default => null,
        };

        if ($key === null) {
            return null;
        }

        $limits = $this->currentPlan()?->limits ?? [];

        // Missing key => unmetered; explicit null => unlimited.
        return array_key_exists($key, $limits) ? $limits[$key] : null;
    }

    /**
     * The plan's SHARED record allowance, spent across products, customers and
     * orders together — or null when this plan meters each entity separately.
     *
     * See App\Support\PlanPool. Lives in the same `limits` blob the admin panel
     * already edits, so switching a plan between pooled and per-entity metering
     * needs no deploy.
     */
    public function poolLimit(): ?int
    {
        return $this->currentPlan()?->poolLimit();
    }

    /**
     * Whether this plan is bought by talking to us rather than through Shopify
     * billing. Enterprise is negotiated and invoiced off-platform, so offering an
     * "Upgrade" button that opens a Shopify charge screen for it would promise
     * something the checkout cannot deliver.
     */
    public function planIsContactOnly(): bool
    {
        return (bool) $this->currentPlan()?->isContactOnly();
    }

    /** Whether the plan allows a feature flag such as "metafields". */
    public function planAllows(string $feature): bool
    {
        $limits = $this->currentPlan()?->limits ?? [];

        return (bool) ($limits[$feature] ?? true);
    }
}
