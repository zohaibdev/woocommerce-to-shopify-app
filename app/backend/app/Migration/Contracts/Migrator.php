<?php

namespace App\Migration\Contracts;

use App\Migration\MigrationItemResult;
use App\Models\StoreMigration;
use App\Models\WoocommerceConnection;

interface Migrator
{
    /** Entity type key, e.g. 'product', 'category'. */
    public function entityType(): string;

    /** Total number of source items available in WooCommerce. */
    public function count(WoocommerceConnection $connection): int;

    /** Fetch a page of source items from WooCommerce (paginated). */
    public function fetchBatch(WoocommerceConnection $connection, int $page, int $perPage): array;

    /** Transform + create/update a single item in Shopify. Must be idempotent. */
    public function migrateItem(StoreMigration $migration, array $wooItem): MigrationItemResult;

    /** Entity types that must be migrated before this one (dependency order). */
    public function dependsOn(): array;

    /** Extract the WooCommerce source id from a raw item. */
    public function sourceId(array $wooItem): int;
}
