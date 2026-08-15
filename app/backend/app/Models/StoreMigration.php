<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StoreMigration extends Model
{
    protected $table = 'store_migrations';

    protected $fillable = [
        'shop_id', 'name', 'woocommerce_connection_id', 'status',
        'selected_entities', 'options', 'started_at', 'planned_at', 'completed_at',
    ];

    protected $casts = [
        'selected_entities' => 'array',
        'options' => 'array',
        'started_at' => 'datetime',
        'planned_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /** Custom tags the merchant chose to add to every migrated record. */
    public function customTags(): array
    {
        return array_values(array_filter((array) ($this->options['custom_tags'] ?? [])));
    }

    public const ACTIVE_STATES = ['pending', 'processing', 'paused'];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(WoocommerceConnection::class, 'woocommerce_connection_id');
    }

    public function entities(): HasMany
    {
        return $this->hasMany(MigrationEntity::class, 'migration_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(MigrationItem::class, 'migration_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(MigrationLog::class, 'migration_id');
    }

    public function isCancelledOrPaused(): bool
    {
        return in_array($this->currentStatus(), ['cancelled', 'paused'], true);
    }

    /**
     * The run's status straight from the database, without rehydrating the model.
     *
     * `fresh()` was being called once per migrated item — a full row SELECT, plus
     * a new model, fifty thousand times over a large catalogue — purely to read
     * one enum column.
     */
    public function currentStatus(): string
    {
        return (string) static::query()->whereKey($this->getKey())->value('status');
    }
}
