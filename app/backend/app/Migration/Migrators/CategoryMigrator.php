<?php

namespace App\Migration\Migrators;

use App\Migration\AbstractMigrator;
use App\Migration\Concerns\ShedsRejectedFields;
use App\Migration\MigrationItemResult;
use App\Migration\SeoMeta;
use App\Models\StoreMigration;
use App\Models\WoocommerceConnection;
use App\Services\WooCommerceClient;

class CategoryMigrator extends AbstractMigrator
{
    use ShedsRejectedFields;

    private const CREATE_MUTATION = <<<'GQL'
    mutation collectionCreate($input: CollectionInput!) {
      collectionCreate(input: $input) {
        collection { id }
        userErrors { field message }
      }
    }
    GQL;

    private const UPDATE_MUTATION = <<<'GQL'
    mutation collectionUpdate($input: CollectionInput!) {
      collectionUpdate(input: $input) {
        collection { id }
        userErrors { field message }
      }
    }
    GQL;

    public function entityType(): string
    {
        return 'category';
    }

    protected function upserts(): bool
    {
        // Re-running updates existing collections (and recreates deleted ones) so
        // product->collection links stay valid across migrations.
        return true;
    }

    public function count(WoocommerceConnection $connection): int
    {
        return (new WooCommerceClient($connection))->total('products/categories');
    }

    /** Source resource for retry-by-id (see AbstractMigrator::fetchByIds). */
    protected function wooSource(): ?array
    {
        return ['products/categories', []];
    }

    public function fetchBatch(WoocommerceConnection $connection, int $page, int $perPage): array
    {
        return (new WooCommerceClient($connection))->list('products/categories', $page, $perPage);
    }

    protected function doMigrate(StoreMigration $migration, array $wooItem, ?string $existingShopifyId = null): MigrationItemResult
    {
        $warnings = [];

        $input = [
            'title' => $wooItem['name'] ?? 'Untitled',
            // Keeps /product-category/shoes as /collections/shoes, so the
            // merchant's old category URLs can be redirected one-to-one.
            'handle' => $this->handleFromSlug($wooItem['slug'] ?? null),
            'descriptionHtml' => $this->rehostDescription($migration, $wooItem['description'] ?? '', $warnings),
            // A term carries no meta_data in the WooCommerce REST response, so
            // this only finds anything when Yoast exposes yoast_head_json —
            // see SeoMeta. Empty is normal and harmless.
            'seo' => SeoMeta::forRecord($wooItem),
        ];

        $input = array_filter($input, fn ($v) => $v !== null && $v !== []);

        // Category image -> collection image.
        if ($src = data_get($wooItem, 'image.src')) {
            $input['image'] = [
                'src' => $src,
                'altText' => data_get($wooItem, 'image.alt') ?: ($wooItem['name'] ?? null),
            ];
        }

        // A category image that no longer exists at the source, or a description
        // Shopify won't take, must not cost us the collection. The title stays
        // required — a collection without one is meaningless.
        return $this->writeSheddingBadFields(
            function (array $attempt) use ($migration, $existingShopifyId) {
                $id = $this->write($migration, $attempt, $existingShopifyId);

                // Shopify refused the request itself — final answer; shedding the
                // image or the description cannot change a scope problem.
                if ($id instanceof MigrationItemResult) {
                    return $id;
                }

                return is_array($id) ? [null, $id] : [$id, []];
            },
            $input,
            // `handle` is shed like the rest: Shopify rejects one it has already
            // given another collection, and that is worth a URL, not a collection.
            ['image', 'descriptionHtml', 'seo', 'handle'],
        )->withWarnings($warnings);
    }

    /** Update the existing collection when we have one; recreate if it's gone. */
    private function write(StoreMigration $migration, array $input, ?string $existingShopifyId): MigrationItemResult|string|array|null
    {
        if (! $existingShopifyId) {
            return $this->run($migration, self::CREATE_MUTATION, 'collectionCreate', $input);
        }

        $id = $this->run($migration, self::UPDATE_MUTATION, 'collectionUpdate', $input + ['id' => $existingShopifyId]);

        // Only a genuine "that collection is gone" falls through to a create.
        // Anything else — including a refusal that used to read as null — must
        // not, or a store whose token lost a scope mid-run would answer every
        // update with nothing and get a second copy of every collection.
        if ($id !== null) {
            return $id;
        }

        return $this->run($migration, self::CREATE_MUTATION, 'collectionCreate', $input);
    }

    /**
     * Returns gid on success, errors array on real failure, null if the target
     * collection is gone, or a finished failure result when Shopify refused the
     * request outright (a missing scope, an expired token, an outage).
     */
    private function run(StoreMigration $migration, string $mutation, string $field, array $input): MigrationItemResult|string|array|null
    {
        $shopify = $this->shopify($migration);
        $body = $shopify->graphql($mutation, ['input' => $input]);

        if ($fatal = $this->fatalShopifyError($shopify, $body)) {
            return MigrationItemResult::failed($fatal);
        }

        $errors = $shopify->userErrors($body, $field);

        if (! empty($errors)) {
            if (isset($input['id']) && stripos(json_encode($errors), 'does not exist') !== false) {
                return null;
            }
            return $errors;
        }

        return data_get($body, "data.{$field}.collection.id");
    }
}
