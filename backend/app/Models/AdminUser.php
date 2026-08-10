<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AdminUser extends Model
{
    protected $fillable = ['name', 'email', 'password', 'is_super', 'api_token_hash', 'last_login_at'];

    protected $hidden = ['password', 'api_token_hash'];

    protected $casts = [
        'is_super' => 'boolean',
        'last_login_at' => 'datetime',
        'password' => 'hashed',
    ];

    /** Issue a fresh API token, storing only its hash. Returns the plaintext token. */
    public function issueToken(): string
    {
        $plain = Str::random(64);
        $this->forceFill([
            'api_token_hash' => hash('sha256', $plain),
            'last_login_at' => now(),
        ])->save();

        return $plain;
    }

    public static function findByToken(?string $plain): ?self
    {
        if (! $plain) {
            return null;
        }

        return static::where('api_token_hash', hash('sha256', $plain))->first();
    }

    public function checkPassword(string $plain): bool
    {
        return Hash::check($plain, $this->password);
    }
}
