<?php

namespace App\Migration\Migrators;

use App\Migration\AbstractMigrator;
use App\Migration\Concerns\ShedsRejectedFields;
use App\Migration\ImageSizeResolver;
use App\Migration\MetafieldMapper;
use App\Migration\MigrationItemResult;
use App\Migration\MigrationRunner;
use App\Models\MigrationItem;
use App\Models\StoreMigration;
use App\Services\WooCommerceClient;
use App\Models\WoocommerceConnection;
use App\Support\SafeCache;

/**
 * Migrates a WooCommerce product (simple or variable) into Shopify using the
 * productSet mutation, which upserts the whole product graph in one call:
 * options, variants, prices, SKUs, inventory, weight, images and collection
 * membership. Variants are handled here inline (no separate variation job).
 */
class ProductMigrator extends AbstractMigrator
{
    use ShedsRejectedFields;

    /** Products whose images Shopify rejected before we stop sending images at all. */
    private const MEDIA_GIVE_UP_AFTER = 5;

    /**
     * Shopify's hard limits on a single product. WooCommerce enforces none of
     * these, so a real catalogue routinely exceeds all three — and until they
     * were handled here, exceeding any of them failed the whole product with a
     * raw GraphQL error the merchant could not act on.
     *
     * @see https://shopify.dev/docs/api/admin-graphql/latest/input-objects/productsetinput
     */
    private const MAX_OPTIONS = 3;

    private const MAX_VARIANTS = 2048;

    private const MAX_MEDIA = 250;

    /** Longest option name / value Shopify accepts. */
    private const MAX_OPTION_TEXT = 255;

    /** Variations are paged; stop after this many pages as a runaway guard. */
    private const VARIATION_PAGE_SIZE = 100;

    /**
     * Recovery lookups after an interrupted write. SKU first — it is the key a
     * merchant already treats as unique — then the WooCommerce slug, which
     * Shopify normally adopts verbatim as the product handle.
     */
    private const FIND_BY_SKU = <<<'GQL'
    query findVariant($q: String!) {
      productVariants(first: 1, query: $q) { nodes { product { id } } }
    }
    GQL;

    private const FIND_BY_HANDLE = <<<'GQL'
    query findProduct($q: String!) {
      products(first: 1, query: $q) { nodes { id } }
    }
    GQL;

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
     * migration id => ['at' => float, 'map' => [woo category id => collection gid]].
     * Static, so it survives the job; see collectionMap() for why it expires.
     */
    private static array $collectionCache = [];

    /** Seconds a cached category mapping is trusted before being re-read. */
    private const COLLECTION_MAP_TTL = 60.0;

    /**
     * Weight unit for this run. WooCommerce doesn't report its unit through the
     * REST API, so the merchant picks it per migration (falling back to config).
     */
    private string $weightUnit = 'KILOGRAMS';

    /**
     * Held on the migrator rather than resolved per call: ProcessBatchJob keeps
     * one migrator for the whole page, and the resolver's size table is filled
     * once per page by prefetchPage().
     */
    private ?ImageSizeResolver $imageSizes = null;

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

    /** Source resource for retry-by-id (see AbstractMigrator::fetchByIds). */
    protected function wooSource(): ?array
    {
        return ['products', ['status' => 'any']];
    }

    public function fetchBatch(WoocommerceConnection $connection, int $page, int $perPage): array
    {
        return (new WooCommerceClient($connection))
            ->list('products', $page, $perPage, ['status' => 'any']);
    }

    /**
     * Did an interrupted attempt already create this product?
     *
     * Asked only when the mapping says we called Shopify but never learned the
     * outcome. Two natural keys, cheapest and most reliable first:
     *
     *   SKU    — the merchant's own unique key. Exact, when the product has one.
     *   handle — Shopify derives it from the title, but a WooCommerce slug is
     *            almost always what it lands on, so it catches the rest.
     *
     * A wrong answer here would attach the migration to somebody else's product,
     * so both lookups are exact-match and anything ambiguous returns null: at
     * worst we are back to the duplicate we started with.
     */
    protected function findExisting(StoreMigration $migration, array $woo): ?string
    {
        $sku = trim((string) ($woo['sku'] ?? ''));

        if ($sku !== '') {
            $id = data_get(
                $this->shopify($migration)->graphql(self::FIND_BY_SKU, ['q' => 'sku:'.$this->quote($sku)]),
                'data.productVariants.nodes.0.product.id',
            );

            if ($id) {
                return $id;
            }
        }

        $slug = trim((string) ($woo['slug'] ?? ''));

        if ($slug === '') {
            return null;
        }

        return data_get(
            $this->shopify($migration)->graphql(self::FIND_BY_HANDLE, ['q' => 'handle:'.$this->quote($slug)]),
            'data.products.nodes.0.id',
        );
    }

    /** Quote a value for a Shopify search query so it is matched literally. */
    private function quote(string $value): string
    {
        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }

    protected function doMigrate(StoreMigration $migration, array $woo, ?string $existingShopifyId = null): MigrationItemResult
    {
        // Merchant-selected unit for this migration, else the configured default.
        $this->weightUnit = $migration->options['weight_unit']
            ?? config('migration.weight_unit', 'KILOGRAMS');

        $locationId = $this->primaryLocationId($migration);

        $metafields = $this->metafields($migration, $woo);

        // Warnings raised while reshaping the product to fit Shopify's limits.
        // The product still migrates; the merchant is told what changed.
        $warnings = [];

        // Fetch variations up front so their images can be added to the product's
        // media AND attached to each variant.
        $variations = ($woo['type'] ?? 'simple') === 'variable'
            ? $this->allVariations($migration, (int) $woo['id'], $warnings)
            : [];

        // A variation image is usually also in the product gallery, so the page
        // prefetch already covers it — but not always. Top up the ones it missed,
        // or those images would quietly ship at full size while the merchant
        // believes they asked for smaller ones.
        $this->prefetchVariationImages($migration, $variations);

        $input = [
            'title' => $woo['name'] ?? 'Untitled',
            'descriptionHtml' => $woo['description'] ?? '',
            'vendor' => $this->vendor($migration, $woo),
            'productType' => data_get($woo, 'categories.0.name') ?: null,
            'status' => $this->status($woo['status'] ?? 'publish'),
            'tags' => $this->tags($migration, $woo),
            'files' => $this->mediaIsUnreachable($migration) ? [] : $this->files($migration, $woo, $variations, $warnings),
            'collections' => $this->collections($migration, $woo),
            'metafields' => $metafields,
        ];

        if (! empty($variations)) {
            [$options, $variants] = $this->variableOptionsAndVariants($migration, $woo, $variations, $locationId, $warnings);
            if (empty($variants)) {
                // No usable variations — fall back to a single default variant.
                [$options, $variants] = $this->simpleOptionAndVariant($migration, $woo, $locationId);
            }
        } else {
            [$options, $variants] = $this->simpleOptionAndVariant($migration, $woo, $locationId);
        }

        $input['productOptions'] = $options;
        $input['variants'] = $variants;

        $input = array_filter($input, fn ($v) => $v !== null && $v !== []);

        // Upsert: target the existing product so a re-run UPDATES it in place.
        if ($existingShopifyId) {
            $input['id'] = $existingShopifyId;
        }

        // Optional fields Shopify may reject on messy Woo data. Losing any of them
        // is a warning; losing the product is not acceptable. Title, options and
        // variants are deliberately absent — without them there is no product.
        $optional = ['files', 'metafields', 'collections', 'tags', 'vendor', 'productType', 'descriptionHtml'];

        return $this->writeSheddingBadFields(
            function (array $attempt) use ($migration, $existingShopifyId) {
                // Dropping product-level files means dropping variant files too —
                // they reference the same sources.
                if (! array_key_exists('files', $attempt)) {
                    $attempt = $this->withoutVariantMedia($attempt);
                }

                $result = $this->runSet($migration, $attempt);

                // Self-heal: the product we tried to update no longer exists
                // (deleted in Shopify), so recreate it fresh.
                if ($result === null && $existingShopifyId) {
                    unset($attempt['id']);
                    $result = $this->runSet($migration, $attempt);
                }

                return is_array($result) ? [null, $result] : [$result, []];
            },
            $input,
            $optional,
            fn (string $field) => $field === 'files' ? $this->noteMediaRejected($migration) : null,
        )->withWarnings($warnings);
    }

    /**
     * Every variation of a variable product, not just the first page.
     *
     * This used to ask for page 1 with per_page=100 and stop. Apparel and
     * electronics catalogues routinely go past that — five colours by ten sizes
     * by two fits is 100 on its own — and everything beyond the first hundred was
     * dropped silently, with the product still reported as a clean success.
     *
     * @param  array<int, string>  $warnings
     */
    private function allVariations(StoreMigration $migration, int $productId, array &$warnings): array
    {
        $all = [];
        $page = 1;

        // Shopify will not take more than MAX_VARIANTS anyway, so stop reading
        // once we have enough to fill the product.
        $maxPages = (int) ceil(self::MAX_VARIANTS / self::VARIATION_PAGE_SIZE);

        while ($page <= $maxPages) {
            $chunk = $this->woo($migration)->list(
                "products/{$productId}/variations",
                $page,
                self::VARIATION_PAGE_SIZE,
            );

            $all = array_merge($all, $chunk);

            if (count($chunk) < self::VARIATION_PAGE_SIZE) {
                break;
            }

            $page++;
        }

        if (count($all) > self::MAX_VARIANTS) {
            $warnings[] = 'This product has '.count($all).' variations but Shopify allows '
                .self::MAX_VARIANTS.' — the rest were not migrated.';

            $all = array_slice($all, 0, self::MAX_VARIANTS);
        }

        return $all;
    }

    /**
     * Once enough products in a run have had their images rejected, the store's
     * media is simply unreachable (a deleted uploads folder, hotlink protection).
     * Sending them again just doubles the Shopify calls for every remaining
     * product, so stop offering them for the rest of the run.
     */
    private function noteMediaRejected(StoreMigration $migration): void
    {
        $count = SafeCache::increment("migration:{$migration->id}:media_rejections");

        if ($count === self::MEDIA_GIVE_UP_AFTER) {
            SafeCache::put("migration:{$migration->id}:media_unreachable", true, now()->addDay());

            MigrationRunner::log(
                $migration->id,
                'warning',
                'product',
                "Images could not be downloaded for {$count} products — skipping images for the rest of this migration. Check that your WooCommerce media files are publicly reachable.",
            );
        }
    }

    private function mediaIsUnreachable(StoreMigration $migration): bool
    {
        return (bool) SafeCache::get("migration:{$migration->id}:media_unreachable", false);
    }

    /** Variant media reference the same sources as product files — shed together. */
    private function withoutVariantMedia(array $input): array
    {
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

    private function simpleOptionAndVariant(StoreMigration $migration, array $woo, ?string $locationId): array
    {
        $options = [['name' => 'Title', 'values' => [['name' => 'Default Title']]]];

        $variant = $this->buildVariant($migration, $woo, [['optionName' => 'Title', 'name' => 'Default Title']], $locationId);

        return [$options, [$variant]];
    }

    // ---- Variable product: options from attributes, variants from Woo variations ----

    /**
     * Map a WooCommerce variable product onto Shopify's product/variant model.
     *
     * The hard part is that Shopify allows AT MOST THREE options per product and
     * WooCommerce allows any number. A clothing product with Colour, Size,
     * Material and Fit is perfectly ordinary in Woo and simply cannot be
     * expressed in Shopify as four options. Previously such a product was sent as
     * it was, rejected outright, and lost — with the merchant given a raw GraphQL
     * error to interpret.
     *
     * So when there are more than three, the first two are kept as they are and
     * every remaining attribute is folded into a single third option
     * ("Material / Fit" with values like "Cotton / Slim"). Nothing is dropped:
     * every variation still exists, still has its own price, SKU and stock, and
     * remains uniquely addressable. The merchant is told it happened.
     *
     * @param  array<int, string>  $warnings
     */
    private function variableOptionsAndVariants(
        StoreMigration $migration,
        array $woo,
        array $wooVariations,
        ?string $locationId,
        array &$warnings,
    ): array {
        // Attribute names that actually drive variations, in the parent's order.
        $attributes = collect($woo['attributes'] ?? [])
            ->filter(fn ($a) => ! empty($a['variation']) && ! empty($a['name']))
            ->values();

        if ($attributes->isEmpty()) {
            return [[], []];
        }

        $names = $attributes->pluck('name')->map(fn ($n) => (string) $n)->all();
        $slots = $this->optionSlots($names);

        if (count($slots) < count($names)) {
            $warnings[] = 'WooCommerce had '.count($names).' variation attributes but Shopify allows only '
                .self::MAX_OPTIONS.' — '.implode(', ', array_slice($names, self::MAX_OPTIONS - 1))
                .' were combined into a single option so no variation was lost.';
        }

        // Declared order of each attribute's values, so Small/Medium/Large does
        // not come back out of the migration as Large/Medium/Small.
        $declaredOrder = [];
        foreach ($attributes as $attribute) {
            $declaredOrder[(string) $attribute['name']] = array_map(
                'strval',
                (array) ($attribute['options'] ?? []),
            );
        }

        $variants = [];
        $valuesPerSlot = array_fill(0, count($slots), []);
        $seenCombinations = [];
        $skipped = 0;

        foreach ($wooVariations as $variation) {
            $byName = $this->variationValues($variation);

            // A variation that leaves an attribute as "Any" has no single value to
            // put on the option, so Shopify cannot represent it.
            if (count(array_intersect_key($byName, array_flip($names))) !== count($names)) {
                $skipped++;

                continue;
            }

            $optionValues = [];
            foreach ($slots as $index => $slot) {
                $value = $this->slotValue($slot, $byName);

                $optionValues[] = ['optionName' => $slot['label'], 'name' => $value];
                $valuesPerSlot[$index][$value] = true;
            }

            // Shopify rejects the whole product if two variants share a combination.
            $signature = implode("\u{1F}", array_column($optionValues, 'name'));
            if (isset($seenCombinations[$signature])) {
                $skipped++;

                continue;
            }
            $seenCombinations[$signature] = true;

            $variants[] = $this->buildVariant($migration, $variation, $optionValues, $locationId);
        }

        if ($skipped > 0) {
            $warnings[] = "{$skipped} variation(s) could not be migrated because they had no value set for "
                .'every attribute, or duplicated another variation.';
        }

        if (empty($variants)) {
            return [[], []];
        }

        $options = [];
        foreach ($slots as $index => $slot) {
            // Cast back to string: these were collected as array KEYS to dedupe
            // them, and PHP turns a numeric string key ("42", a shoe size) into an
            // int. Shopify types option values as String and rejects an Int.
            $values = array_map('strval', array_keys($valuesPerSlot[$index]));

            // Single-attribute options follow WooCommerce's own value order;
            // combined ones keep the order the variations produced.
            if (count($slot['names']) === 1) {
                $values = $this->inDeclaredOrder($values, $declaredOrder[$slot['names'][0]] ?? []);
            }

            $options[] = [
                'name' => $slot['label'],
                'values' => array_map(fn ($v) => ['name' => $v], $values),
            ];
        }

        return [$options, $variants];
    }

    /**
     * Group attribute names into at most MAX_OPTIONS slots. Under the limit this
     * is one slot per attribute; over it, the tail is merged into the last slot.
     *
     * @param  array<int, string>  $names
     * @return array<int, array{names: array<int, string>, label: string}>
     */
    private function optionSlots(array $names): array
    {
        if (count($names) <= self::MAX_OPTIONS) {
            return array_map(
                fn (string $name) => ['names' => [$name], 'label' => $this->clip($name)],
                $names,
            );
        }

        $slots = [];

        foreach (array_slice($names, 0, self::MAX_OPTIONS - 1) as $name) {
            $slots[] = ['names' => [$name], 'label' => $this->clip($name)];
        }

        $merged = array_slice($names, self::MAX_OPTIONS - 1);
        $slots[] = ['names' => $merged, 'label' => $this->clip(implode(' / ', $merged))];

        return $slots;
    }

    /** attribute name => chosen value, for one Woo variation. */
    private function variationValues(array $variation): array
    {
        $values = [];

        foreach ($variation['attributes'] ?? [] as $attribute) {
            $name = (string) ($attribute['name'] ?? '');
            $option = (string) ($attribute['option'] ?? '');

            if ($name !== '' && $option !== '') {
                $values[$name] = $option;
            }
        }

        return $values;
    }

    /** The value this slot carries for one variation (joined, when merged). */
    private function slotValue(array $slot, array $byName): string
    {
        $parts = array_map(fn (string $name) => $byName[$name] ?? '', $slot['names']);

        return $this->clip(implode(' / ', $parts));
    }

    /**
     * Reorder values to match WooCommerce's declared attribute order, keeping any
     * value the parent never declared at the end rather than discarding it.
     */
    private function inDeclaredOrder(array $values, array $declared): array
    {
        if (empty($declared)) {
            return $values;
        }

        $ordered = array_values(array_intersect($declared, $values));
        $extra = array_values(array_diff($values, $ordered));

        return array_merge($ordered, $extra);
    }

    /** Shopify caps option names and values; a merged label can run long. */
    private function clip(string $text): string
    {
        return mb_substr($text, 0, self::MAX_OPTION_TEXT);
    }

    // ---- Shared variant builder (works for both a simple product and a variation) ----

    private function buildVariant(StoreMigration $migration, array $data, array $optionValues, ?string $locationId): array
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
                'originalSource' => $this->imageUrl($migration, $data['image'] ?? [], $src),
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

    /**
     * Product gallery images + every variation image, de-duplicated by source URL
     * and resized to the merchant's chosen size.
     *
     * @param  array<int, string>  $warnings
     */
    private function files(StoreMigration $migration, array $woo, array $variations, array &$warnings): array
    {
        $sources = collect($woo['images'] ?? [])->map(fn ($img) => [
            'id' => isset($img['id']) ? (int) $img['id'] : null,
            'src' => $img['src'] ?? null,
            'alt' => $img['alt'] ?? ($woo['name'] ?? null),
        ]);

        foreach ($variations as $v) {
            if ($src = data_get($v, 'image.src')) {
                $sources->push([
                    'id' => ($id = data_get($v, 'image.id')) ? (int) $id : null,
                    'src' => $src,
                    'alt' => data_get($v, 'image.alt') ?: ($woo['name'] ?? null),
                ]);
            }
        }

        $files = $sources
            ->filter(fn ($f) => ! empty($f['src']))
            ->unique('src')
            ->map(fn ($f) => [
                'originalSource' => $this->imageUrl($migration, $f, $f['src']),
                'alt' => $f['alt'],
                'contentType' => 'IMAGE',
            ])
            ->values()
            ->all();

        // Shopify caps media per product regardless of how many variants it has.
        if (count($files) > self::MAX_MEDIA) {
            $warnings[] = 'This product had '.count($files).' images but Shopify allows '
                .self::MAX_MEDIA.' per product — the rest were not migrated.';

            $files = array_slice($files, 0, self::MAX_MEDIA);
        }

        return $files;
    }

    /**
     * The URL Shopify should download. Resolves to a smaller WordPress-generated
     * copy when the merchant asked for optimised images, and falls back to the
     * original whenever that is not possible.
     */
    private function imageUrl(StoreMigration $migration, array $image, string $src): string
    {
        return $this->imageSizes()->url(
            $migration,
            isset($image['id']) ? (int) $image['id'] : null,
            $src,
        );
    }

    /**
     * Load the image size table for a whole page of products in one request.
     * Called by ProcessBatchJob before the page is walked — doing it per product
     * would double the number of requests we make to an already slow Woo host.
     */
    public function prefetchPage(StoreMigration $migration, array $items): void
    {
        if (! $this->imageSizes()->isActive($migration)) {
            return;
        }

        $ids = [];

        foreach ($items as $product) {
            foreach ($product['images'] ?? [] as $image) {
                if (! empty($image['id'])) {
                    $ids[] = (int) $image['id'];
                }
            }
        }

        $this->imageSizes()->prefetch($migration, $ids, reset: true);
    }

    /** Any variation image the page-wide prefetch did not already cover. */
    private function prefetchVariationImages(StoreMigration $migration, array $variations): void
    {
        if (empty($variations) || ! $this->imageSizes()->isActive($migration)) {
            return;
        }

        $ids = [];

        foreach ($variations as $variation) {
            if ($id = data_get($variation, 'image.id')) {
                $ids[] = (int) $id;
            }
        }

        $this->imageSizes()->prefetch($migration, $ids);
    }

    private function imageSizes(): ImageSizeResolver
    {
        return $this->imageSizes ??= app(ImageSizeResolver::class);
    }

    /** Resolve Woo category ids -> already-migrated Shopify collection gids. */
    private function collections(StoreMigration $migration, array $woo): array
    {
        $map = $this->collectionMap($migration);

        return collect($woo['categories'] ?? [])
            ->map(fn ($cat) => $map[(int) $cat['id']] ?? null)
            ->filter()
            ->values()
            ->all();
    }

    /**
     * The whole category mapping in one query instead of one per category per
     * product. A product in five categories used to cost five extra SELECTs, on
     * every single product.
     *
     * Keyed by migration, and re-read after COLLECTION_MAP_TTL. The cache is
     * static, so it outlives the job that filled it — and a worker that stays up
     * (which is what a large catalogue needs) would otherwise hold the very first
     * map it ever built. A retry run migrates the failed CATEGORIES and then the
     * failed products in the same process, so without the expiry those products
     * were written against a map that predates their collections, and their
     * collection links vanished with nothing to show for it.
     */
    private function collectionMap(StoreMigration $migration): array
    {
        $entry = self::$collectionCache[$migration->id] ?? null;

        if ($entry && (microtime(true) - $entry['at']) < self::COLLECTION_MAP_TTL) {
            return $entry['map'];
        }

        $map = MigrationItem::query()
            ->where('shop_id', $migration->shop_id)
            ->where('entity_type', 'category')
            ->where('status', 'success')
            ->whereNotNull('shopify_id')
            ->pluck('shopify_id', 'woocommerce_id')
            ->all();

        self::$collectionCache[$migration->id] = ['at' => microtime(true), 'map' => $map];

        return $map;
    }

    private function primaryLocationId(StoreMigration $migration): ?string
    {
        $shopId = $migration->shop_id;
        if (array_key_exists($shopId, self::$locationCache)) {
            return self::$locationCache[$shopId];
        }

        // Cached across worker processes — the in-memory copy dies on every worker
        // restart, and this is the same answer for the life of the shop.
        $id = SafeCache::remember("shopify_primary_location:{$shopId}", now()->addDay(), function () use ($migration) {
            // Only the id is requested — reading `name`/`isActive` needs the
            // read_locations scope, but the id alone is available with write_inventory.
            // Inactive locations are excluded: Shopify rejects inventory written
            // to one, and "the first location" is not always a live one.
            $res = $this->shopify($migration)->graphql(
                '{ locations(first: 1, includeInactive: false, includeLegacy: false) { nodes { id } } }',
            );

            return data_get($res, 'data.locations.nodes.0.id');
        });

        return self::$locationCache[$shopId] = $id;
    }
}
