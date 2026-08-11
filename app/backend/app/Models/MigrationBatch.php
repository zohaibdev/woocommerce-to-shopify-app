<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MigrationBatch extends Model
{
    protected $fillable = [
        'migration_id', 'entity_type', 'batch_number',
        'page', 'per_page', 'status', 'attempts', 'error',
    ];

    public function migration(): BelongsTo
    {
        return $this->belongsTo(StoreMigration::class, 'migration_id');
    }
}
