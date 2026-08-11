<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WoocommerceConnection extends Model
{
    protected $fillable = [
        'shop_id', 'store_url', 'consumer_key', 'consumer_secret',
        'status', 'wc_version', 'wp_version', 'last_tested_at',
    ];

    protected $casts = [
        'consumer_key' => 'encrypted',
        'consumer_secret' => 'encrypted',
        'last_tested_at' => 'datetime',
    ];

    protected $hidden = ['consumer_key', 'consumer_secret'];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /** Masked key for safe display in the UI. */
    public function getConsumerKeyMaskedAttribute(): string
    {
        $key = $this->consumer_key ?? '';
        if (strlen($key) <= 4) {
            return str_repeat('*', strlen($key));
        }
        return str_repeat('*', max(0, strlen($key) - 4)) . substr($key, -4);
    }
}
