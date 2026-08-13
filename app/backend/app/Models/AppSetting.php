<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One encrypted JSON blob per settings key. Encrypted rather than plain because
 * these hold credentials (SMTP password), and CLAUDE.md rule 6 requires every
 * credential to be encrypted at rest.
 */
class AppSetting extends Model
{
    protected $fillable = ['key', 'value'];

    protected $casts = [
        'value' => 'encrypted:array',
    ];

    public static function read(string $key, array $default = []): array
    {
        $row = static::where('key', $key)->first();

        return is_array($row?->value) ? $row->value : $default;
    }

    public static function write(string $key, array $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
