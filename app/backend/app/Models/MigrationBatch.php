<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MigrationBatch extends Model
{
    protected $fillable = [
        'migration_id', 'entity_type', 'batch_number',
        'page', 'per_page', 'source_ids', 'processed_offset', 'status', 'attempts', 'error',
    ];

    protected $casts = [
        // The exact WooCommerce ids this batch owns, resolved once and then
        // fetched by `include=` — see the 2026_08_16 migration for why.
        'source_ids' => 'array',
    ];

    public function migration(): BelongsTo
    {
        return $this->belongsTo(StoreMigration::class, 'migration_id');
    }
}
