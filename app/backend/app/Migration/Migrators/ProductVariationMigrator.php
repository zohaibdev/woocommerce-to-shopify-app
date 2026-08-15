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
 * Variants cannot be migrated separately from their product: Shopify's
 * productSet takes the whole product graph — options, variants, prices, SKUs,
 * inventory, weight and media — in one call, and a variant has no meaning
 * outside it. ProductMigrator therefore reads every variation of a variable
 * product (allVariations(), which pages properly) and sends them with the
 * product.
 *
 * count() returns 0 ON PURPOSE. It used to count variable products and plan a
 * batch per page, each of which fetched a page from the merchant's store to
 * then do nothing — pure load on the host that is already the bottleneck. The
 * old TODO about summing variation counts implied unfinished work; the work
 * exists, in ProductMigrator, where it has to be.
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
        return 0;
    }

    public function fetchBatch(WoocommerceConnection $connection, int $page, int $perPage): array
    {
        return [];
    }

    protected function doMigrate(StoreMigration $migration, array $wooProduct, ?string $existingShopifyId = null): MigrationItemResult
    {
        // Only reachable from a batch row planned before this became a no-op.
        $parentWooId = $this->sourceId($wooProduct);

        return MigrationItemResult::skipped($this->shopifyIdFor($migration, 'product', $parentWooId));
    }
}
