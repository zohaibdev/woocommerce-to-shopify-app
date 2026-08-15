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
 * Product images go to Shopify as productSet's `files`, inline with the product
 * — that is what lets a variation image be attached to its variant in the same
 * call. Attaching them a second time here would give the merchant every photo
 * twice.
 *
 * count() returns 0 ON PURPOSE. It used to count PRODUCTS, so selecting media
 * planned a full second pass over the catalogue: on a 20,000-product store, two
 * hundred jobs that each downloaded a page of products from the merchant's host
 * and then skipped every record. The unused productCreateMedia mutation that
 * used to sit here has gone with it — a query nothing runs is a promise the
 * code does not keep.
 */
class MediaMigrator extends AbstractMigrator
{
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
        return 0;
    }

    public function fetchBatch(WoocommerceConnection $connection, int $page, int $perPage): array
    {
        return [];
    }

    protected function doMigrate(StoreMigration $migration, array $wooProduct, ?string $existingShopifyId = null): MigrationItemResult
    {
        // Only reachable from a batch row planned before this became a no-op.
        $wooId = $this->sourceId($wooProduct);

        return MigrationItemResult::skipped($this->shopifyIdFor($migration, 'product', $wooId));
    }
}
