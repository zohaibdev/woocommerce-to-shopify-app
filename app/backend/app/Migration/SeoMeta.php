<?php

namespace App\Migration;

/**
 * Pulls SEO title/description out of a WooCommerce record.
 *
 * WordPress core stores neither — they belong to whichever SEO plugin the
 * merchant installed, and each one uses its own meta keys. Migrating a store
 * without them means every product and collection arrives with Shopify's
 * auto-generated SEO instead of the copy the merchant wrote and ranks on, which
 * is the single most damaging thing a migration can quietly lose.
 *
 * Yoast, Rank Math and All in One SEO cover the overwhelming majority of stores.
 * Their placeholder syntax (`%%title%%`, `%title%`, `#post_title`) is deliberately
 * NOT expanded: a template is not a value, and shipping "%%title%% %%sep%%
 * %%sitename%%" into Shopify's SEO field is worse than leaving it empty, which
 * lets Shopify generate something sensible instead.
 */
class SeoMeta
{
    /** meta_data keys that hold an SEO title, best-known plugin first. */
    private const TITLE_KEYS = [
        '_yoast_wpseo_title',
        'rank_math_title',
        '_aioseo_title',
        '_seopress_titles_title',
    ];

    /** meta_data keys that hold an SEO description. */
    private const DESCRIPTION_KEYS = [
        '_yoast_wpseo_metadesc',
        'rank_math_description',
        '_aioseo_description',
        '_seopress_titles_desc',
    ];

    /** Shopify's limits. Longer values are rejected outright. */
    private const MAX_TITLE = 70;

    private const MAX_DESCRIPTION = 320;

    /**
     * Shopify's `seo` input for one record, or [] when the store has no SEO
     * plugin (or the plugin left these blank, which is the common case — most
     * merchants only fill them in on pages that matter).
     *
     * @param  array  $woo  a WooCommerce product/record with `meta_data`
     * @return array{title?: string, description?: string}
     */
    public static function forRecord(array $woo): array
    {
        $meta = self::index($woo['meta_data'] ?? []);

        $seo = array_filter([
            'title' => self::pick($meta, self::TITLE_KEYS, self::MAX_TITLE),
            'description' => self::pick($meta, self::DESCRIPTION_KEYS, self::MAX_DESCRIPTION),
        ], fn ($v) => $v !== null);

        // A term (product category) has no meta_data in the WooCommerce REST
        // response at all. Yoast optionally exposes `yoast_head_json` there
        // instead, so take it when it is the only thing on offer.
        if (empty($seo)) {
            $seo = array_filter([
                'title' => self::clean(data_get($woo, 'yoast_head_json.title'), self::MAX_TITLE),
                'description' => self::clean(data_get($woo, 'yoast_head_json.description'), self::MAX_DESCRIPTION),
            ], fn ($v) => $v !== null);
        }

        return $seo;
    }

    /**
     * @param  array<int, array>  $metaData
     * @return array<string, mixed>
     */
    private static function index(array $metaData): array
    {
        $byKey = [];

        foreach ($metaData as $entry) {
            $key = is_array($entry) ? ($entry['key'] ?? null) : null;

            if (is_string($key) && $key !== '') {
                $byKey[$key] = $entry['value'] ?? null;
            }
        }

        return $byKey;
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<int, string>  $keys
     */
    private static function pick(array $meta, array $keys, int $limit): ?string
    {
        foreach ($keys as $key) {
            $value = self::clean($meta[$key] ?? null, $limit);

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private static function clean(mixed $value, int $limit): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($value === '') {
            return null;
        }

        // An unexpanded plugin template is not usable copy — see the class note.
        if (preg_match('/%%\w+%%|%\w+%|#\w+/', $value)) {
            return null;
        }

        return mb_substr($value, 0, $limit);
    }
}
