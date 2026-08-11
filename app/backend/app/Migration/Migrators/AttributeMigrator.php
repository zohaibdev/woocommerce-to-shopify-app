<?php

namespace App\Migration\Migrators;

use App\Migration\AbstractMigrator;
use App\Migration\MigrationItemResult;
use App\Models\StoreMigration;
use App\Models\WoocommerceConnection;
use App\Services\WooCommerceClient;

/**
 * Migrates WooCommerce global attributes. In Shopify, attributes become product
 * *options* which are set when creating/updating products & variants, so this
 * migrator's job is primarily to record a mapping of attribute terms that the
 * ProductVariationMigrator consumes.
 */
class AttributeMigrator extends AbstractMigrator
{
    public function entityType(): string
    {
        return 'attribute';
    }

    public function count(WoocommerceConnection $connection): int
    {
        return (new WooCommerceClient($connection))->total('products/attributes');
    }

    public function fetchBatch(WoocommerceConnection $connection, int $page, int $perPage): array
    {
        return (new WooCommerceClient($connection))->list('products/attributes', $page, $perPage);
    }

    protected function doMigrate(StoreMigration $migration, array $wooItem, ?string $existingShopifyId = null): MigrationItemResult
    {
        $wooId = $this->sourceId($wooItem);

        // Attributes have no standalone Shopify object; we simply record them so
        // variations can resolve option names/values. The "shopify_id" is a
        // synthetic marker (there is no Shopify resource to create here).
        // TODO(phase-7): fetch attribute terms and persist option values per product.
        return MigrationItemResult::success("woo-attribute:{$wooId}");
    }
}
