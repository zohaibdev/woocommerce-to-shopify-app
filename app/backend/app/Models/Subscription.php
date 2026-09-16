<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One Shopify AppSubscription, as this app last knew it.
 *
 * status (local):
 *   pending    created, awaiting the merchant's approval on Shopify
 *   active     billed by Shopify right now
 *   frozen     Shopify put it on hold for non-payment; resumes on its own
 *   cancelled  ended — by the merchant, an admin, uninstall, replacement, or Shopify
 *   declined   the merchant declined the charge
 *   expired    never approved (Shopify expires a pending charge after two days)
 *
 * ends_at is when this subscription STOPS GRANTING ACCESS. For a cancelled paid
 * subscription that was not prorated it is the end of the period already paid
 * for — Shopify's own rule on uninstall is that "merchants can reinstall and use
 * the app for the remainder of the billing period" — otherwise it is the moment
 * it ended. A subscription never grants access past current_period_end.
 */
class Subscription extends Model
{
    public const ACTIVE = 'active';

    public const PENDING = 'pending';

    public const FROZEN = 'frozen';

    public const CANCELLED = 'cancelled';

    public const DECLINED = 'declined';

    public const EXPIRED = 'expired';

    protected $fillable = [
        'shop_id', 'plan_id', 'previous_subscription_id', 'status',
        'shopify_charge_id', 'shopify_status', 'shopify_updated_at', 'shopify_created_at',
        'price', 'currency', 'interval', 'test', 'trial_days',
        'started_at', 'approved_at', 'current_period_end',
        'cancelled_at', 'cancellation_reason', 'ends_at', 'confirmation_url',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'test' => 'boolean',
        'trial_days' => 'integer',
        'shopify_updated_at' => 'datetime',
        'shopify_created_at' => 'datetime',
        'started_at' => 'datetime',
        'approved_at' => 'datetime',
        'current_period_end' => 'datetime',
        'cancelled_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    // A merchant-specific approval link; only ever handed back to that merchant.
    protected $hidden = ['confirmation_url'];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function previous(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_subscription_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(BillingEvent::class);
    }

    public function isPaid(): bool
    {
        if ($this->price !== null) {
            return (float) $this->price > 0;
        }

        return $this->plan !== null && (float) $this->plan->price > 0;
    }

    /**
     * Cancelled, but still inside the period the merchant already paid for, so
     * it keeps granting its plan until ends_at. Never true for a prorated cancel
     * (credited — ends_at is the moment of cancelling) or a replaced plan.
     */
    public function grantsPaidAccess(): bool
    {
        return $this->status === self::CANCELLED
            && $this->ends_at !== null
            && $this->ends_at->isFuture()
            && $this->isPaid();
    }
}
