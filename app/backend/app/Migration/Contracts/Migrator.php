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

    /**
     * The source ids on one page — no record bodies.
     *
     * Lets a batch resolve itself to a fixed set of ids once, and fetch by id
     * from then on: `include=` costs the same at page 500 as at page 1, and a
     * record deleted mid-run can no longer shift the page underneath a resume.
     *
     * Returns null when this migrator cannot answer, which puts the caller back
     * on ordinary page/offset paging.
     *
     * @return array<int, int>|null
     */
    public function listSourceIds(WoocommerceConnection $connection, int $page, int $perPage): ?array;

    /**
     * Fetch specific source items by WooCommerce id. Used by "retry failed items"
     * so a retry costs one request for the handful that failed, instead of a full
     * re-run of the whole entity.
     *
     * @param  array<int, int>  $ids
     */
    public function fetchByIds(WoocommerceConnection $connection, array $ids): array;

    /** Transform + create/update a single item in Shopify. Must be idempotent. */
    public function migrateItem(StoreMigration $migration, array $wooItem): MigrationItemResult;

    /** Entity types that must be migrated before this one (dependency order). */
    public function dependsOn(): array;

    /** Extract the WooCommerce source id from a raw item. */
    public function sourceId(array $wooItem): int;
}
