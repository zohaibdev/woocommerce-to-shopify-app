<?php

namespace App\Migration\Migrators;

use App\Migration\AbstractMigrator;
use App\Migration\Concerns\SurvivesMediaFailures;
use App\Migration\MetafieldMapper;
use App\Migration\MigrationItemResult;
use App\Models\StoreMigration;
use App\Services\WooCommerceClient;
use App\Models\WoocommerceConnection;

/**
 * Migrates a WooCommerce product (simple or variable) into Shopify using the
 * productSet mutation, which upserts the whole product graph in one call:
 * options, variants, prices, SKUs, inventory, weight, images and collection
 * membership. Variants are handled here inline (no separate variation job).
 */
class ProductMigrator extends AbstractMigrator
{
    use SurvivesMediaFailures;

    private const SET_MUTATION = <<<'GQL'
    mutation productSet($input: ProductSetInput!) {
      productSet(input: $input, synchronous: true) {
        product { id }
        userErrors { field message }
      }
    }
    GQL;

    /** Memoised primary location id per shop (for inventory quantities). */
    private static array $locationCache = [];

    /**
     * Weight unit for this run. WooCommerce doesn't report its unit through the
     * REST API, so the merchant picks it per migration (falling back to config).
     */
    private string $weightUnit = 'KILOGRAMS';

    public function entityType(): string
    {
        return 'product';
    }

    public function dependsOn(): array
    {
        // Categories must exist first so we can add products to their collections.
        return ['category'];
    }

    protected function upserts(): bool
    {
        // Re-running updates existing products (and recreates any the merchant
        // deleted) instead of skipping — so a re-run always reflects Woo + heals
        // stale mappings.
        return true;
    }

    public function count(WoocommerceConnection $connection): int
    {
        return (new WooCommerceClient($connection))->total('products');
    }

    public function fetchBatch(WoocommerceConnection $connection, int $page, int $perPage): array
    {
        return (new WooCommerceClient($connection))
            ->list('products', $page, $perPage, ['status' => 'any']);
    }

    protected function doMigrate(StoreMigration $migration, array $woo, ?string $existingShopifyId = null): MigrationItemResult
    {
        // Merchant-selected unit for this migration, else the configured default.
        $this->weightUnit = $migration->options['weight_unit']
            ?? config('migration.weight_unit', 'KILOGRAMS');

        $locationId = $this->primaryLocationId($migration);

        $metafields = $this->metafields($migration, $woo);

        // Fetch variations up front so their images can be added to the product's
        // media AND attached to each variant.
        $variations = ($woo['type'] ?? 'simple') === 'variable'
            ? (new WooCommerceClient($migration->connection))->list("products/{$woo['id']}/variations", 1, 100)
            : [];

        $input = [
            'title' => $woo['name'] ?? 'Untitled',
            'descriptionHtml' => $woo['description'] ?? '',
            'vendor' => $this->vendor($migration, $woo),
            'productType' => data_get($woo, 'categories.0.name') ?: null,
            'status' => $this->status($woo['status'] ?? 'publish'),
            'tags' => $this->tags($migration, $woo),
            'files' => $this->files($woo, $variations),
            'collections' => $this->collections($migration, $woo),
            'metafields' => $metafields,
        ];

        if (! empty($variations)) {
            [$options, $variants] = $this->variableOptionsAndVariants($woo, $variations, $locationId);
            if (empty($variants)) {
                // No usable variations — fall back to a single default variant.
                [$options, $variants] = $this->simpleOptionAndVariant($woo, $locationId);
            }
        } else {
            [$options, $variants] = $this->simpleOptionAndVariant($woo, $locationId);
        }

        $input['productOptions'] = $options;
        $input['variants'] = $variants;

        $input = array_filter($input, fn ($v) => $v !== null && $v !== []);

        // Upsert: target the existing product so a re-run UPDATES it in place.
        if ($existingShopifyId) {
            $input['id'] = $existingShopifyId;
        }

        $result = $this->runSet($migration, $input);

        // Self-heal: if we tried to update a product that no longer exists
        // (deleted in Shopify), recreate it fresh.
        if ($result === null && $existingShopifyId) {
            unset($input['id']);
            $result = $this->runSet($migration, $input);
        }

        // Images Shopify could not download must not cost us the product itself.
        // Retry once with every file stripped, then flag what went missing.
        if (is_array($result) && $this->isMediaOnlyError($result)) {
            $warning = $this->mediaWarning($result);
            $retried = $this->runSet($migration, $this->withoutMedia($input));

            if (is_string($retried) && $retried !== '') {
                return MigrationItemResult::successWithWarning($retried, $warning);
            }
        }

        if (is_array($result)) {
            return MigrationItemResult::failed(json_encode($result));
        }

        return $result
            ? MigrationItemResult::success($result)
            : MigrationItemResult::failed('No product id returned by Shopify');
    }

    /** The same productSet input with all product and variant media removed. */
    private function withoutMedia(array $input): array
    {
        unset($input['files']);

        foreach ($input['variants'] ?? [] as $i => $variant) {
            unset($input['variants'][$i]['file']);
        }

        return $input;
    }

    /**
     * Runs productSet. Returns the new product gid on success, an errors array on
     * a "real" failure, or null when the update targeted a non-existent product
     * (signal to the caller to retry as a create).
     */
    private function runSet(StoreMigration $migration, array $input): string|array|null
    {
        $body = $this->shopify($migration)->graphql(self::SET_MUTATION, ['input' => $input]);
        $errors = $this->shopify($migration)->userErrors($body, 'productSet');

        if (! empty($errors)) {
            $text = json_encode($errors);
            if (isset($input['id']) && stripos($text, 'does not exist') !== false) {
                return null; // deleted product -> caller recreates
            }
            return $errors;
        }

        return data_get($body, 'data.productSet.product.id');
    }

    // ---- Simple product: one default variant ----

    private function simpleOptionAndVariant(array $woo, ?string $locationId): array
    {
        $options = [['name' => 'Title', 'values' => [['name' => 'Default Title']]]];

        $variant = $this->buildVariant($woo, [['optionName' => 'Title', 'name' => 'Default Title']], $locationId);

        return [$options, [$variant]];
    }

    // ---- Variable product: options from attributes, variants from Woo variations ----

    private function variableOptionsAndVariants(array $woo, array $wooVariations, ?string $locationId): array
    {
        // Build Shopify options from the parent attributes flagged for variations.
        $options = collect($woo['attributes'] ?? [])
            ->filter(fn ($a) => ! empty($a['variation']))
            ->map(fn ($a) => [
                'name' => $a['name'],
                'values' => collect($a['options'] ?? [])->map(fn ($o) => ['name' => (string) $o])->values()->all(),
            ])
            ->filter(fn ($o) => ! empty($o['values']))
            ->values()
            ->all();

        if (empty($options)) {
            return [[], []];
        }

        $variants = [];
        foreach ($wooVariations as $v) {
            $optionValues = [];
            foreach ($v['attributes'] ?? [] as $attr) {
                if (($attr['option'] ?? '') === '') {
                    continue; // "Any" value — Shopify can't represent it as a variant
                }
                $optionValues[] = ['optionName' => $attr['name'], 'name' => (string) $attr['option']];
            }
            // Every declared option must have a value on the variant.
            if (count($optionValues) !== count($options)) {
                continue;
            }
            $variants[] = $this->buildVariant($v, $optionValues, $locationId);
        }

        return [$options, $variants];
    }

    // ---- Shared variant builder (works for both a simple product and a variation) ----

    private function buildVariant(array $data, array $optionValues, ?string $locationId): array
    {
        [$price, $compareAt] = $this->prices($data);

        $inventoryItem = array_filter([
            'sku' => $data['sku'] ?? null,
            'tracked' => ! empty($data['manage_stock']),
            'requiresShipping' => ! ($data['virtual'] ?? false),
            'measurement' => $this->weight($data),
        ], fn ($v) => $v !== null);

        $variant = [
            'optionValues' => $optionValues,
            'price' => $price,
            'compareAtPrice' => $compareAt,
            'inventoryItem' => $inventoryItem,
            'inventoryPolicy' => ($data['backorders'] ?? 'no') !== 'no' ? 'CONTINUE' : 'DENY',
        ];

        // Inventory quantity (only when Woo tracks stock and we know the location).
        if ($locationId && ! empty($data['manage_stock']) && isset($data['stock_quantity']) && $data['stock_quantity'] !== null) {
            $variant['inventoryQuantities'] = [[
                'locationId' => $locationId,
                'name' => 'available',
                'quantity' => (int) $data['stock_quantity'],
            ]];
        }

        // Variation image -> variant media (the same source is also in product files).
        if ($src = data_get($data, 'image.src')) {
            $variant['file'] = [
                'originalSource' => $src,
                'contentType' => 'IMAGE',
                'alt' => data_get($data, 'image.alt') ?: null,
            ];
        }

        return array_filter($variant, fn ($v) => $v !== null && $v !== []);
    }

    // ---- Field mappers ----

    /** Returns [price, compareAtPrice]. Sale price becomes price; regular becomes compareAt. */
    private function prices(array $data): array
    {
        $regular = $data['regular_price'] ?? '';
        $sale = $data['sale_price'] ?? '';
        $current = $data['price'] ?? '';

        if ($sale !== '' && $regular !== '' && (float) $sale < (float) $regular) {
            return [(string) $sale, (string) $regular];
        }

        $price = $regular !== '' ? $regular : ($current !== '' ? $current : '0');

        return [(string) $price, null];
    }

    private function weight(array $data): ?array
    {
        $weight = $data['weight'] ?? '';
        if ($weight === '' || (float) $weight <= 0) {
            return null;
        }

        return ['weight' => [
            'unit' => $this->weightUnit,
            'value' => (float) $weight,
        ]];
    }

    private function status(string $wooStatus): string
    {
        return $wooStatus === 'publish' ? 'ACTIVE' : 'DRAFT';
    }

    /** Woo tags + brand names (optional) + the merchant's custom tags. */
    private function tags(StoreMigration $migration, array $woo): array
    {
        $wooTags = collect($woo['tags'] ?? [])->pluck('name')->all();
        $brandTags = config('migration.brands_as_tags', true) ? $this->brandNames($woo) : [];

        return array_values(array_unique(array_merge($wooTags, $brandTags, $migration->customTags())));
    }

    private function brandNames(array $woo): array
    {
        return collect($woo['brands'] ?? [])->pluck('name')->filter()->values()->all();
    }

    /**
     * Vendor = the product's first Woo brand. If it has no brand, fall back to the
     * migration's "default_vendor" option, then the config default. When both are
     * empty we send null and Shopify defaults the vendor to the shop name.
     */
    private function vendor(StoreMigration $migration, array $woo): ?string
    {
        $brand = data_get($woo, 'brands.0.name');
        if ($brand) {
            return $brand;
        }

        $fallback = $migration->options['default_vendor'] ?? config('migration.default_vendor', '');

        return $fallback !== '' ? $fallback : null;
    }

    /**
     * Build product metafields from Woo meta_data (+ a brands metafield) and make
     * sure a definition exists for each key so it shows in the Shopify admin.
     */
    private function metafields(StoreMigration $migration, array $woo): array
    {
        $mapper = new MetafieldMapper();

        $metafields = $mapper->fromMetaData($migration->shop_id, $woo['meta_data'] ?? []);

        if ($brand = $mapper->brandMetafield($migration->shop_id, $this->brandNames($woo))) {
            $metafields[] = $brand;
        }

        if (! empty($metafields)) {
            $mapper->ensureDefinitions($this->shopify($migration), $migration->shop_id, $metafields);
        }

        return $metafields;
    }

    /** Product gallery images + every variation image, de-duplicated by source URL. */
    private function files(array $woo, array $variations = []): array
    {
        $sources = collect($woo['images'] ?? [])->map(fn ($img) => [
            'src' => $img['src'] ?? null,
            'alt' => $img['alt'] ?? ($woo['name'] ?? null),
        ]);

        foreach ($variations as $v) {
            if ($src = data_get($v, 'image.src')) {
                $sources->push(['src' => $src, 'alt' => data_get($v, 'image.alt') ?: ($woo['name'] ?? null)]);
            }
        }

        return $sources
            ->filter(fn ($f) => ! empty($f['src']))
            ->unique('src')
            ->map(fn ($f) => [
                'originalSource' => $f['src'],
                'alt' => $f['alt'],
                'contentType' => 'IMAGE',
            ])
            ->values()
            ->all();
    }

    /** Resolve Woo category ids -> already-migrated Shopify collection gids. */
    private function collections(StoreMigration $migration, array $woo): array
    {
        return collect($woo['categories'] ?? [])
            ->map(fn ($cat) => $this->shopifyIdFor($migration, 'category', (int) $cat['id']))
            ->filter()
            ->values()
            ->all();
    }

    private function primaryLocationId(StoreMigration $migration): ?string
    {
        $shopId = $migration->shop_id;
        if (array_key_exists($shopId, self::$locationCache)) {
            return self::$locationCache[$shopId];
        }

        // Only the id is requested — reading `name`/`isActive` needs the
        // read_locations scope, but the id alone is available with write_inventory.
        $res = $this->shopify($migration)->graphql('{ locations(first: 1) { nodes { id } } }');
        $id = data_get($res, 'data.locations.nodes.0.id');

        return self::$locationCache[$shopId] = $id;
    }
}
