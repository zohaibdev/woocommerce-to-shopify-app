<?php

namespace App\Migration\Migrators;

use App\Migration\AbstractMigrator;
use App\Migration\MigrationItemResult;
use App\Models\StoreMigration;
use App\Models\WoocommerceConnection;
use App\Services\WooCommerceClient;

/**
 * Migrates product images/media. Runs after products so each image can be
 * attached to its migrated Shopify product. An image failure is recorded as a
 * failed media item and must NOT fail the parent product.
 */
class MediaMigrator extends AbstractMigrator
{
    private const CREATE_MEDIA = <<<'GQL'
    mutation productCreateMedia($productId: ID!, $media: [CreateMediaInput!]!) {
      productCreateMedia(productId: $productId, media: $media) {
        media { id status }
        mediaUserErrors { field message }
      }
    }
    GQL;

    public function entityType(): string
    {
        return 'media';
    }

    public function dependsOn(): array
    {
        return ['product'];
    }

    public function count(WoocommerceConnection $connection): int
    {
        // One media job per product; exact image counts resolved at processing time.
        return (new WooCommerceClient($connection))->total('products');
    }

    public function fetchBatch(WoocommerceConnection $connection, int $page, int $perPage): array
    {
        return (new WooCommerceClient($connection))->list('products', $page, $perPage, ['status' => 'any']);
    }

    protected function doMigrate(StoreMigration $migration, array $wooProduct, ?string $existingShopifyId = null): MigrationItemResult
    {
        // Product images are now migrated INLINE by ProductMigrator (productSet
        // `files`), so attaching them again here would create duplicates. This
        // entity is kept for backward compatibility but does no work.
        $wooId = $this->sourceId($wooProduct);

        return MigrationItemResult::skipped($this->shopifyIdFor($migration, 'product', $wooId));
    }
}
