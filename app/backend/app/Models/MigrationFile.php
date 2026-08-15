<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One WooCommerce image that has been re-hosted on Shopify for this shop.
 *
 * See App\Migration\DescriptionImages — this is the lookup that stops a banner
 * pasted into every product description being uploaded once per product.
 */
class MigrationFile extends Model
{
    protected $fillable = [
        'shop_id', 'source_hash', 'source_url', 'shopify_url', 'shopify_file_id',
    ];

    public static function hashFor(string $sourceUrl): string
    {
        return hash('sha256', $sourceUrl);
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }
}
