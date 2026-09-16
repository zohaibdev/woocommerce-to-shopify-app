<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

/**
 * Append-only billing history and audit log.
 *
 * Every row records something that actually happened, at the time it happened:
 * Shopify's updated_at for a webhook, otherwise the moment this app performed or
 * observed it. Merchants see the lifecycle events; admins also see sync and
 * failure events.
 */
class BillingEvent extends Model
{
    public const UPDATED_AT = null;

    /** Events a merchant sees in their own billing history. */
    public const MERCHANT_VISIBLE = [
        'checkout_started', 'checkout_declined', 'checkout_expired',
        'subscription_activated', 'plan_changed', 'previous_plan_ended', 'plan_change_scheduled',
        'subscription_renewed', 'cancellation_requested', 'subscription_cancelled',
        'subscription_frozen', 'subscription_unfrozen', 'subscription_expired',
    ];

    /** Context keys that must never be persisted. */
    private const SECRET_KEYS = ['access_token', 'refresh_token', 'token', 'client_secret', 'api_secret', 'confirmation_url'];

    protected $fillable = [
        'shop_id', 'subscription_id', 'plan_id', 'event', 'source', 'status',
        'shopify_subscription_id', 'message', 'context', 'occurred_at',
    ];

    protected $casts = [
        'context' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public static function record(
        Shop $shop,
        string $event,
        string $source,
        ?Subscription $subscription = null,
        ?string $message = null,
        array $context = [],
        ?\DateTimeInterface $occurredAt = null,
    ): self {
        return self::create([
            'shop_id' => $shop->id,
            'subscription_id' => $subscription?->id,
            'plan_id' => $subscription?->plan_id,
            'event' => $event,
            'source' => $source,
            'status' => $subscription?->status,
            'shopify_subscription_id' => $subscription?->shopify_charge_id,
            'message' => $message !== null ? mb_substr($message, 0, 500) : null,
            'context' => $context ? Arr::except($context, self::SECRET_KEYS) : null,
            'occurred_at' => $occurredAt ? Carbon::instance($occurredAt) : now(),
        ]);
    }
}
