<?php

namespace App\Migration\Migrators;

use App\Migration\AbstractMigrator;
use App\Migration\MigrationItemResult;
use App\Models\StoreMigration;
use App\Models\WoocommerceConnection;
use App\Services\WooCommerceClient;

/**
 * Migrates variations of WooCommerce variable products into Shopify variants.
 * Depends on products (parent must exist) and attributes (option names/values).
 *
 * WooCommerce exposes variations per parent product at
 *   products/{parent_id}/variations
 * so the batching here iterates variable products. For MVP scaffolding we page
 * over products and, per variable product, migrate its variations.
 */
class ProductVariationMigrator extends AbstractMigrator
{
    public function entityType(): string
    {
        return 'variation';
    }

    public function dependsOn(): array
    {
        return ['product', 'attribute'];
    }

    public function count(WoocommerceConnection $connection): int
    {
        // Approximation: number of variable products. Exact variation counts are
        // resolved during processing. TODO(phase-7): sum variation counts.
        return (new WooCommerceClient($connection))->total('products', ['type' => 'variable']);
    }

    public function fetchBatch(WoocommerceConnection $connection, int $page, int $perPage): array
    {
        return (new WooCommerceClient($connection))
            ->list('products', $page, $perPage, ['type' => 'variable']);
    }

    protected function doMigrate(StoreMigration $migration, array $wooProduct, ?string $existingShopifyId = null): MigrationItemResult
    {
        // Variants (options, prices, SKUs, inventory, weight) are now migrated
        // INLINE by ProductMigrator via the productSet mutation. This entity is
        // kept for backward compatibility / explicit selection but does no work.
        $parentWooId = $this->sourceId($wooProduct);
        $parentShopifyId = $this->shopifyIdFor($migration, 'product', $parentWooId);

        return MigrationItemResult::skipped($parentShopifyId);
    }
}
