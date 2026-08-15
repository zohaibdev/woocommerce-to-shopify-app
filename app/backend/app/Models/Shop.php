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

    /** The shop's current (most recent active) subscription. */
    public function activeSubscription(): HasOne
    {
        return $this->hasOne(Subscription::class)->where('status', 'active')->latestOfMany();
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(SupportTicket::class);
    }

    /**
     * The shop's effective plan. Every shop always has one — with no paid
     * subscription they fall back to the free Starter plan.
     */
    public function currentPlan(): ?Plan
    {
        $subscribed = $this->activeSubscription()->with('plan')->first()?->plan;

        return $subscribed ?: Plan::where('code', 'starter')->first();
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
