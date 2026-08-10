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
    ];

    protected $casts = [
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'token_expires_at' => 'datetime',
        'refresh_token_expires_at' => 'datetime',
        'installed_at' => 'datetime',
        'uninstalled_at' => 'datetime',
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
}
