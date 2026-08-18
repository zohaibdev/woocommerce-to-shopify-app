<?php

namespace App\Services;

use App\Models\AppSetting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The logo and name shown at the top of every email the app sends, editable from
 * the admin panel rather than baked into the templates.
 *
 * The file lives in storage/app/branding (outside the web root) and is served by
 * a public route. That avoids needing `storage:link` or a writable public assets
 * folder — neither of which is reliable on shared hosting.
 */
class Branding
{
    public const KEY = 'branding';

    private const CACHE_KEY = 'app_settings.branding';

    private const DIRECTORY = 'branding';

    public static function stored(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, fn () => AppSetting::read(self::KEY));
    }

    public static function current(): array
    {
        $stored = self::stored();

        return [
            'name' => $stored['name'] ?? 'Shift Shop',
            'has_logo' => ! empty($stored['logo_path']),
            // Cache-busted so a replaced logo isn't served from an email client cache.
            'logo_url' => empty($stored['logo_path']) ? null : self::logoUrl($stored['updated'] ?? null),
        ];
    }

    /** Absolute URL for the logo — emails are read outside the app, so never relative. */
    public static function logoUrl(?string $version = null): string
    {
        $base = rtrim((string) config('services.shopify.app_url'), '/');

        return $base.'/api/branding/logo'.($version ? '?v='.$version : '');
    }

    public static function storeLogo(UploadedFile $file): void
    {
        $stored = AppSetting::read(self::KEY);

        // Only ever one logo on disk.
        self::deleteFile($stored['logo_path'] ?? null);

        $path = $file->store(self::DIRECTORY);

        AppSetting::write(self::KEY, array_merge($stored, [
            'logo_path' => $path,
            'logo_mime' => $file->getClientMimeType(),
            'updated' => self::version($path),
        ]));

        Cache::forget(self::CACHE_KEY);
    }

    public static function removeLogo(): void
    {
        $stored = AppSetting::read(self::KEY);

        self::deleteFile($stored['logo_path'] ?? null);

        unset($stored['logo_path'], $stored['logo_mime']);
        $stored['updated'] = self::version('removed');

        AppSetting::write(self::KEY, $stored);
        Cache::forget(self::CACHE_KEY);
    }

    public static function setName(string $name): void
    {
        $stored = AppSetting::read(self::KEY);
        $stored['name'] = trim($name) ?: 'Shift Shop';

        AppSetting::write(self::KEY, $stored);
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * The cache-busting token in the logo URL.
     *
     * Every logo is served from the SAME path (/api/branding/logo), and the route
     * sends a day of Cache-Control — so to Gmail and Outlook, which proxy and
     * cache images by URL, a replaced logo is the same image they already have.
     * The token is what makes the new one a new URL.
     *
     * It is built from the stored FILENAME (which Laravel randomises per upload)
     * as well as the clock, because a timestamp alone cannot distinguish two
     * uploads inside the same second — and "I re-uploaded it straight away and it
     * did not change" is precisely how this gets reported.
     */
    private static function version(string $path): string
    {
        return substr(sha1($path.'|'.now()->getTimestamp()), 0, 12);
    }

    /** @return array{0: string, 1: string}|null [absolute path, mime] */
    public static function logoFile(): ?array
    {
        $stored = self::stored();
        $path = $stored['logo_path'] ?? null;

        if (! $path || ! Storage::exists($path)) {
            return null;
        }

        return [Storage::path($path), $stored['logo_mime'] ?? 'image/png'];
    }

    private static function deleteFile(?string $path): void
    {
        if (! $path) {
            return;
        }

        try {
            Storage::delete($path);
        } catch (Throwable) {
            // A logo we cannot delete is not worth failing the request over.
        }
    }
}
