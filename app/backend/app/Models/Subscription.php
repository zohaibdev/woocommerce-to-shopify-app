<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    protected $fillable = [
        'shop_id', 'plan_id', 'status', 'shopify_charge_id', 'started_at',
        'current_period_end', 'cancelled_at', 'cancellation_reason', 'ends_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'current_period_end' => 'datetime',
        'cancelled_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function isPaid(): bool
    {
        return $this->plan !== null && (float) $this->plan->price > 0;
    }

    /**
     * Cancelled, but the merchant has already paid for time that has not run out.
     * Shopify does not refund the rest of a period on cancel (the app never asks
     * it to prorate), so taking the plan away at the moment of cancelling would
     * charge a merchant for days they then could not use.
     */
    public function inPaidGracePeriod(): bool
    {
        return $this->status === 'cancelled'
            && $this->ends_at !== null
            && $this->ends_at->isFuture();
    }

    /** Whole days left on a grace period — rounded UP, so "ends today" reads as 1, never 0. */
    public function daysRemaining(): int
    {
        if (! $this->ends_at || $this->ends_at->isPast()) {
            return 0;
        }

        return (int) ceil(now()->diffInSeconds($this->ends_at) / 86400);
    }
}
