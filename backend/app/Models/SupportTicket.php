<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupportTicket extends Model
{
    protected $fillable = [
        'shop_id', 'migration_id', 'subject', 'message',
        'contact_email', 'status', 'priority', 'last_reply_at',
    ];

    protected $casts = ['last_reply_at' => 'datetime'];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function migration(): BelongsTo
    {
        return $this->belongsTo(StoreMigration::class, 'migration_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(TicketReply::class)->oldest();
    }
}
