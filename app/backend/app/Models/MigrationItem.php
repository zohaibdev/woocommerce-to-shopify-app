<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MigrationItem extends Model
{
    protected $fillable = [
        'migration_id', 'shop_id', 'entity_type', 'woocommerce_id',
        'shopify_id', 'status', 'error', 'payload_hash',
    ];

    public function migration(): BelongsTo
    {
        return $this->belongsTo(StoreMigration::class, 'migration_id');
    }
}
