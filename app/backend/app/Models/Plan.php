<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    protected $fillable = ['code', 'name', 'price', 'interval', 'limits', 'active'];

    protected $casts = ['limits' => 'array', 'active' => 'boolean', 'price' => 'decimal:2'];

    /**
     * A shared record budget across products, customers and orders, instead of a
     * separate cap per entity. See App\Support\PlanPool.
     */
    public function poolLimit(): ?int
    {
        $total = $this->limits['total'] ?? null;

        return is_numeric($total) ? (int) $total : null;
    }

    /**
     * Bought by talking to us rather than through Shopify billing — Enterprise is
     * negotiated and invoiced off-platform. Offering a Shopify charge screen for
     * it would promise something the checkout cannot deliver.
     */
    public function isContactOnly(): bool
    {
        return (bool) ($this->limits['contact_only'] ?? false);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }
}
