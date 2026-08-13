<?php

namespace App\Migration;

use App\Migration\Contracts\Migrator;
use App\Models\MigrationItem;
use App\Models\StoreMigration;
use App\Models\WoocommerceConnection;
use App\Services\ShopifyClient;
use App\Services\WooCommerceClient;

abstract class AbstractMigrator implements Migrator
{
    /**
     * Concrete migrators implement the actual transform + Shopify write here.
     * $existingShopifyId is the gid from a prior run (null if none). Upserting
     * migrators use it to UPDATE the existing object instead of skipping.
     * The mapping/idempotency bookkeeping is handled by migrateItem() below.
     */
    abstract protected function doMigrate(
        StoreMigration $migration,
        array $wooItem,
        ?string $existingShopifyId = null
    ): MigrationItemResult;

    public function dependsOn(): array
    {
        return [];
    }

    /**
     * The WooCommerce REST resource this migrator reads, plus any query it always
     * needs. Migrators that have no source of their own (the inline no-ops) return
     * null and are simply not retryable by id.
     *
     * @return array{0: string, 1: array}|null [resource, query]
     */
    protected function wooSource(): ?array
    {
        return null;
    }

    public function fetchByIds(WoocommerceConnection $connection, array $ids): array
    {
        $source = $this->wooSource();

        if ($source === null || empty($ids)) {
            return [];
        }

        [$resource, $query] = $source;

        return (new WooCommerceClient($connection))->list(
            $resource,
            1,
            max(1, count($ids)),
            $query + ['include' => implode(',', array_map('intval', $ids))],
        );
    }

    public function sourceId(array $wooItem): int
    {
        return (int) ($wooItem['id'] ?? 0);
    }

    /**
     * When true, the migrator re-processes already-migrated items as an UPSERT
     * (update existing / recreate if deleted) instead of skipping. This keeps a
     * re-run correct even if the merchant deleted the Shopify object. When false,
     * an already-successful item is skipped (fast, dedup-only).
     */
    protected function upserts(): bool
    {
        return false;
    }

    /**
     * Template method enforcing idempotency for EVERY migrator:
     *   1. ensure a mapping row exists,
     *   2. skip if already migrated successfully (unless this migrator upserts),
     *   3. run the concrete migration (passing any known Shopify id),
     *   4. persist the outcome to the mapping row.
     */
    public function migrateItem(StoreMigration $migration, array $wooItem): MigrationItemResult
    {
        $wooId = $this->sourceId($wooItem);
        $mapping = $this->existingMapping($migration, $wooId);

        if (! $this->upserts() && $mapping->status === 'success' && $mapping->shopify_id) {
            // Claim the row for this run so the skip is still counted as progress
            // (counters are derived from these rows, not from blind increments).
            if ($mapping->migration_id !== $migration->id) {
                $mapping->update(['migration_id' => $migration->id]);
            }

            return MigrationItemResult::skipped($mapping->shopify_id);
        }

        $result = $this->doMigrate($migration, $wooItem, $mapping->shopify_id);

        $mapping->update([
            'migration_id' => $migration->id,
            'shopify_id' => $result->shopifyId ?? $mapping->shopify_id,
            'status' => $result->status,
            'error' => $result->error,
        ]);

        return $result;
    }

    protected function woo(StoreMigration $migration): WooCommerceClient
    {
        return new WooCommerceClient($migration->connection);
    }

    protected function shopify(StoreMigration $migration): ShopifyClient
    {
        return new ShopifyClient($migration->shop);
    }

    /**
     * Idempotency guard. Returns an existing successful mapping's shopify_id,
     * or null if the item still needs to be migrated. Creates the pending
     * mapping row if it does not yet exist.
     */
    protected function existingMapping(StoreMigration $migration, int $wooId): ?MigrationItem
    {
        return MigrationItem::firstOrCreate(
            [
                'shop_id' => $migration->shop_id,
                'entity_type' => $this->entityType(),
                'woocommerce_id' => $wooId,
            ],
            [
                'migration_id' => $migration->id,
                'status' => 'pending',
            ],
        );
    }

    /** Look up a previously migrated Shopify gid for a related entity (e.g. product for a variation). */
    protected function shopifyIdFor(StoreMigration $migration, string $entityType, int $wooId): ?string
    {
        return MigrationItem::query()
            ->where('shop_id', $migration->shop_id)
            ->where('entity_type', $entityType)
            ->where('woocommerce_id', $wooId)
            ->where('status', 'success')
            ->value('shopify_id');
    }
}
