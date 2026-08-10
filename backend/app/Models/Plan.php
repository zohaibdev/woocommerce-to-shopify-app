<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    protected $fillable = ['code', 'name', 'price', 'interval', 'limits', 'active'];

    protected $casts = ['limits' => 'array', 'active' => 'boolean', 'price' => 'decimal:2'];

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }
}
