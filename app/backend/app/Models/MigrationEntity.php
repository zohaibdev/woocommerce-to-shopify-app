<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MigrationEntity extends Model
{
    protected $fillable = [
        'migration_id', 'entity_type', 'status',
        'total_items', 'processed_items', 'successful_items', 'failed_items',
        // Planning progress: how many records have been pinned to a chunk so
        // far, and the highest WooCommerce id among them. See PlanBatchesJob.
        'planned_items', 'last_source_id',
    ];

    protected $appends = ['percentage'];

    public function migration(): BelongsTo
    {
        return $this->belongsTo(StoreMigration::class, 'migration_id');
    }

    public function getPercentageAttribute(): float
    {
        if ($this->total_items === 0) {
            return 0.0;
        }
        return round(($this->processed_items / $this->total_items) * 100, 1);
    }
}
