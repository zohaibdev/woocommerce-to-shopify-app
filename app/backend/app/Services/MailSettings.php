<?php

namespace App\Services;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * SMTP configuration the super admin owns from /app/admin/settings, instead of a
 * .env file they cannot reach on shared hosting.
 *
 * Stored settings OVERRIDE the .env values at boot. With nothing stored the app
 * behaves exactly as before, so this is safe to deploy before anything is
 * configured.
 */
class MailSettings
{
    public const KEY = 'mail';

    private const CACHE_KEY = 'app_settings.mail';

    /** Fields we manage. Anything else in config/mail.php is left alone. */
    public const FIELDS = ['host', 'port', 'username', 'password', 'scheme', 'from_address', 'from_name'];

    /** Stored settings, or an empty array when the admin has configured nothing. */
    public static function stored(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, fn () => AppSetting::read(self::KEY));
    }

    /** Stored settings merged over the current config, for display. */
    public static function current(): array
    {
        $stored = self::stored();

        return [
            'host' => $stored['host'] ?? config('mail.mailers.smtp.host'),
            'port' => (int) ($stored['port'] ?? config('mail.mailers.smtp.port')),
            'username' => $stored['username'] ?? config('mail.mailers.smtp.username'),
            'scheme' => $stored['scheme'] ?? config('mail.mailers.smtp.scheme') ?? 'smtp',
            'from_address' => $stored['from_address'] ?? config('mail.from.address'),
            'from_name' => $stored['from_name'] ?? config('mail.from.name'),
            // Never returned in full — the client only needs to know one exists.
            'has_password' => filled($stored['password'] ?? config('mail.mailers.smtp.password')),
            'configured' => filled($stored['host'] ?? null),
        ];
    }

    /**
     * Persist settings. A blank password keeps the stored one, so the admin can
     * edit the host without re-typing the credential.
     */
    public static function save(array $data): array
    {
        $stored = AppSetting::read(self::KEY);

        $next = [
            'host' => trim((string) ($data['host'] ?? '')),
            'port' => (int) ($data['port'] ?? 587),
            'username' => trim((string) ($data['username'] ?? '')),
            'password' => filled($data['password'] ?? null)
                ? (string) $data['password']
                : (string) ($stored['password'] ?? ''),
            'scheme' => in_array($data['scheme'] ?? null, ['smtp', 'smtps'], true) ? $data['scheme'] : 'smtp',
            'from_address' => trim((string) ($data['from_address'] ?? '')),
            'from_name' => trim((string) ($data['from_name'] ?? '')),
        ];

        AppSetting::write(self::KEY, $next);
        Cache::forget(self::CACHE_KEY);

        self::apply();

        return self::current();
    }

    /**
     * Push stored settings into the live config. Called from AppServiceProvider,
     * so it must never throw — a missing table (fresh install, mid-migration)
     * simply means "keep whatever .env says".
     */
    public static function apply(): void
    {
        try {
            $stored = self::stored();
        } catch (Throwable) {
            return;
        }

        if (blank($stored['host'] ?? null)) {
            return;
        }

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => $stored['host'],
            'mail.mailers.smtp.port' => (int) ($stored['port'] ?? 587),
            'mail.mailers.smtp.username' => $stored['username'] ?: null,
            'mail.mailers.smtp.password' => $stored['password'] ?: null,
            'mail.mailers.smtp.scheme' => $stored['scheme'] ?? 'smtp',
        ]);

        if (filled($stored['from_address'] ?? null)) {
            config(['mail.from.address' => $stored['from_address']]);
        }

        if (filled($stored['from_name'] ?? null)) {
            config(['mail.from.name' => $stored['from_name']]);
        }
    }
}
