<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Shop extends Model
{
    protected $fillable = [
        'shopify_domain', 'access_token', 'scopes', 'name', 'email',
        'plan_id', 'installed_at', 'uninstalled_at',
    ];

    protected $casts = [
        'access_token' => 'encrypted',
        'installed_at' => 'datetime',
        'uninstalled_at' => 'datetime',
    ];

    protected $hidden = ['access_token'];

    public function woocommerceConnection(): HasOne
    {
        return $this->hasOne(WoocommerceConnection::class)->latestOfMany();
    }

    public function migrations(): HasMany
    {
        return $this->hasMany(StoreMigration::class);
    }
}
