<?php

namespace App\Migration\Migrators;

use App\Migration\AbstractMigrator;
use App\Migration\MigrationItemResult;
use App\Models\StoreMigration;
use App\Models\WoocommerceConnection;

/**
 * DELIBERATE NO-OP, kept only so migrations planned by an older release still
 * resolve. It is not in the wizard and does no work.
 *
 * WooCommerce attributes become Shopify product OPTIONS, and options only exist
 * as part of a product — there is no standalone Shopify object to create. So
 * ProductMigrator builds them inline from the parent product's attributes and
 * its variations' chosen values (see variableOptionsAndVariants), which is the
 * only place the mapping between the two models can actually be made.
 *
 * count() returns 0 ON PURPOSE. It used to ask WooCommerce for the real
 * attribute total, and StartMigrationJob duly planned a batch per page — jobs
 * that fetch a page from the merchant's store and then do nothing with it. On a
 * large catalogue that is hundreds of pointless requests against the host that
 * is already the migration's bottleneck. The old TODO here implied the work was
 * merely unfinished; it is not, it belongs somewhere else and is done there.
 */
class AttributeMigrator extends AbstractMigrator
{
    public function entityType(): string
    {
        return 'attribute';
    }

    public function count(WoocommerceConnection $connection): int
    {
        return 0;
    }

    public function fetchBatch(WoocommerceConnection $connection, int $page, int $perPage): array
    {
        return [];
    }

    protected function doMigrate(StoreMigration $migration, array $wooItem, ?string $existingShopifyId = null): MigrationItemResult
    {
        // Only reachable from a batch row planned before this became a no-op.
        // The "shopify_id" is a synthetic marker — there is no Shopify resource.
        return MigrationItemResult::skipped('woo-attribute:'.$this->sourceId($wooItem));
    }
}
