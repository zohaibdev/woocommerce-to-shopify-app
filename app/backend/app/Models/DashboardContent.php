<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DashboardContent extends Model
{
    protected $fillable = ['type', 'title', 'body', 'url', 'position', 'enabled'];

    protected $casts = ['enabled' => 'boolean', 'position' => 'integer'];

    public function scopeEnabled($q)
    {
        return $q->where('enabled', true);
    }

    public function scopeOrdered($q)
    {
        return $q->orderBy('position')->orderBy('id');
    }
}
