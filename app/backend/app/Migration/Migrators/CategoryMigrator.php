<?php

namespace App\Migration\Migrators;

use App\Migration\AbstractMigrator;
use App\Migration\MigrationItemResult;
use App\Models\StoreMigration;
use App\Models\WoocommerceConnection;
use App\Services\WooCommerceClient;

class CategoryMigrator extends AbstractMigrator
{
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

    public function fetchBatch(WoocommerceConnection $connection, int $page, int $perPage): array
    {
        return (new WooCommerceClient($connection))->list('products/categories', $page, $perPage);
    }

    protected function doMigrate(StoreMigration $migration, array $wooItem, ?string $existingShopifyId = null): MigrationItemResult
    {
        $input = [
            'title' => $wooItem['name'] ?? 'Untitled',
            'descriptionHtml' => $wooItem['description'] ?? '',
        ];

        // Category image -> collection image.
        if ($src = data_get($wooItem, 'image.src')) {
            $input['image'] = [
                'src' => $src,
                'altText' => data_get($wooItem, 'image.alt') ?: ($wooItem['name'] ?? null),
            ];
        }

        // Update the existing collection when we have one; recreate if it's gone.
        if ($existingShopifyId) {
            $id = $this->run($migration, self::UPDATE_MUTATION, 'collectionUpdate', $input + ['id' => $existingShopifyId]);
            if ($id === null) {
                $id = $this->run($migration, self::CREATE_MUTATION, 'collectionCreate', $input);
            }
        } else {
            $id = $this->run($migration, self::CREATE_MUTATION, 'collectionCreate', $input);
        }

        if (is_array($id)) {
            return MigrationItemResult::failed(json_encode($id));
        }

        return $id
            ? MigrationItemResult::success($id)
            : MigrationItemResult::failed('No collection id returned by Shopify');
    }

    /** Returns gid on success, errors array on real failure, null if the target collection is gone. */
    private function run(StoreMigration $migration, string $mutation, string $field, array $input): string|array|null
    {
        $shopify = $this->shopify($migration);
        $body = $shopify->graphql($mutation, ['input' => $input]);
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
