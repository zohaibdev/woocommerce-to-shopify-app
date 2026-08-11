<?php

namespace App\Migration;

use App\Services\ShopifyClient;

/**
 * Maps WooCommerce meta_data entries to Shopify metafields, and lazily creates
 * a metafield DEFINITION for each new key so it appears in the Shopify admin.
 *
 * Type strategy (kept consistent per key so definitions never conflict):
 *   - array/object value  -> json
 *   - everything else     -> multi_line_text_field (accepts any scalar, any length)
 */
class MetafieldMapper
{
    private const DEFINITION_MUTATION = <<<'GQL'
    mutation metafieldDefinitionCreate($definition: MetafieldDefinitionInput!) {
      metafieldDefinitionCreate(definition: $definition) {
        createdDefinition { id }
        userErrors { field message code }
      }
    }
    GQL;

    /** @var array<int, array<string,string>> shopId => [key => type] */
    private static array $typeByKey = [];

    /** @var array<int, array<string,bool>> shopId => [key => defined] */
    private static array $defined = [];

    /**
     * Build the metafields array for a productSet input from Woo meta_data.
     *
     * @return array<int, array{namespace:string,key:string,type:string,value:string}>
     */
    public function fromMetaData(int $shopId, array $metaData): array
    {
        $namespace = config('migration.meta_namespace', 'woocommerce');
        $skip = collect(explode(',', (string) config('migration.skip_meta_keys', '')))
            ->map(fn ($k) => trim($k))->filter()->all();

        $metafields = [];
        foreach ($metaData as $meta) {
            $rawKey = $meta['key'] ?? '';
            $value = $meta['value'] ?? null;

            if ($rawKey === '' || in_array($rawKey, $skip, true)) {
                continue;
            }
            if ($value === null || $value === '' || (is_array($value) && $value === [])) {
                continue;
            }

            $key = $this->sanitizeKey($rawKey);
            if ($key === null) {
                continue;
            }

            [$type, $encoded] = $this->resolveType($shopId, $key, $value);
            $metafields[] = compact('namespace', 'key', 'type') + ['value' => $encoded];
        }

        return $metafields;
    }

    /** Build a single json metafield holding all brand names (for fidelity). */
    public function brandMetafield(int $shopId, array $brandNames): ?array
    {
        if (empty($brandNames)) {
            return null;
        }

        $namespace = config('migration.meta_namespace', 'woocommerce');
        $key = 'brands';
        $this->rememberType($shopId, $key, 'json');

        return [
            'namespace' => $namespace,
            'key' => $key,
            'type' => 'json',
            'value' => json_encode(array_values($brandNames)),
        ];
    }

    /**
     * Ensure a definition exists for each metafield key (create once per key/shop).
     * Errors (e.g. the definition already exists) are ignored.
     */
    public function ensureDefinitions(ShopifyClient $client, int $shopId, array $metafields): void
    {
        if (! config('migration.create_metafield_definitions', true)) {
            return;
        }

        foreach ($metafields as $mf) {
            $key = $mf['key'];
            if (! empty(self::$defined[$shopId][$key])) {
                continue;
            }
            self::$defined[$shopId][$key] = true;

            try {
                $client->graphql(self::DEFINITION_MUTATION, [
                    'definition' => [
                        'name' => $this->humanize($key),
                        'namespace' => $mf['namespace'],
                        'key' => $key,
                        'type' => $mf['type'],
                        'ownerType' => 'PRODUCT',
                    ],
                ]);
            } catch (\Throwable $e) {
                report($e); // never let definition creation break a migration
            }
        }
    }

    /** Decide (and cache) a consistent type per key, and encode the value to it. */
    private function resolveType(int $shopId, string $key, mixed $value): array
    {
        $existing = self::$typeByKey[$shopId][$key] ?? null;

        if ($existing === 'json' || (is_null($existing) && is_array($value))) {
            $this->rememberType($shopId, $key, 'json');
            return ['json', json_encode($value)];
        }

        $this->rememberType($shopId, $key, 'multi_line_text_field');
        return ['multi_line_text_field', is_array($value) ? json_encode($value) : (string) $value];
    }

    private function rememberType(int $shopId, string $key, string $type): void
    {
        self::$typeByKey[$shopId][$key] = $type;
    }

    /** Sanitize a Woo meta key into a valid Shopify metafield key (3-64, starts with a letter). */
    private function sanitizeKey(string $key): ?string
    {
        $key = strtolower($key);
        $key = preg_replace('/[^a-z0-9_]/', '_', $key);
        $key = ltrim($key, "_0123456789");   // must start with a letter
        $key = trim($key, '_');

        if ($key === '') {
            return null;
        }
        if (strlen($key) < 3) {
            $key = 'mf_' . $key;
        }

        return substr($key, 0, 64);
    }

    private function humanize(string $key): string
    {
        return ucwords(str_replace('_', ' ', $key));
    }
}
