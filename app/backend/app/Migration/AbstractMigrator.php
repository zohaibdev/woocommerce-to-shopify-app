<?php

namespace App\Migration;

use App\Migration\Contracts\Migrator;
use App\Models\MigrationItem;
use App\Models\StoreMigration;
use App\Models\WoocommerceConnection;
use App\Services\ShopifyClient;
use App\Services\WooCommerceClient;
use Illuminate\Database\UniqueConstraintViolationException;

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

    /**
     * Page ids from the migrator's own WooCommerce resource.
     *
     * Every migrator that declares a wooSource() gets this for free, and it is
     * deliberately the SAME resource + query as fetchBatch() — an id list built
     * from a different filter than the records would be worse than no id list at
     * all. Migrators with no source of their own (the inline no-ops) return null
     * and keep the original paging.
     *
     * @return array<int, int>|null
     */
    public function listSourceIds(WoocommerceConnection $connection, int $page, int $perPage): ?array
    {
        $source = $this->wooSource();

        if ($source === null) {
            return null;
        }

        [$resource, $query] = $source;

        return (new WooCommerceClient($connection))->listIds($resource, $page, $perPage, $query);
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
     * A WooCommerce slug as a Shopify handle, so /product/blue-shirt can be
     * redirected to /products/blue-shirt one-to-one.
     *
     * Lives here because products, categories, pages, blogs and articles all
     * need exactly this and had five identical copies of it.
     *
     * The URL-DECODE is the part that matters. WordPress percent-encodes
     * non-Latin slugs, so an Arabic product arrives as
     * `%d8%a7%d9%84%d9%85...` — and stripping that byte by byte produces
     * `d8-a7-d9-84-d9-85`, which Shopify accepts happily and then serves as the
     * product's permanent URL forever. Decoding first means a mixed slug keeps
     * its Latin part (`café-noir` -> `caf-noir`) and a fully non-Latin one
     * returns null, letting Shopify generate something readable instead.
     */
    protected function handleFromSlug(mixed $slug): ?string
    {
        $slug = trim((string) $slug);

        if ($slug === '') {
            return null;
        }

        $decoded = mb_strtolower(rawurldecode($slug));

        $handle = trim(preg_replace('/-+/', '-', preg_replace('/[^a-z0-9-]+/', '-', $decoded)), '-');

        // Shopify caps handles at 255, and a handle of only stripped characters
        // is no handle at all.
        return $handle === '' ? null : mb_substr($handle, 0, 255);
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
     * Find an object this migrator may ALREADY have created in Shopify, using a
     * natural key from the source record (a SKU, an email). Only ever called to
     * recover from an interrupted write — see migrateItem() — so it may cost a
     * request. Returning null simply means "cannot tell", which is where every
     * migrator started.
     */
    protected function findExisting(StoreMigration $migration, array $wooItem): ?string
    {
        return null;
    }

    /**
     * Template method enforcing idempotency for EVERY migrator:
     *   1. ensure a mapping row exists,
     *   2. skip if already migrated successfully (unless this migrator upserts),
     *   3. recover the Shopify id if a previous attempt was interrupted mid-write,
     *   4. run the concrete migration (passing any known Shopify id),
     *   5. persist the outcome to the mapping row.
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

        $shopifyId = $mapping->shopify_id;

        // A previous attempt reached Shopify but never got back here to record
        // what it created — the worker was killed, the host rebooted, a deploy
        // landed. The mapping looks untouched, so the resume would create the
        // object a SECOND time and leave the merchant with two of everything.
        // `attempts` is what distinguishes "never started" from "we don't know",
        // and the only honest way to settle it is to ask Shopify.
        if (! $shopifyId && $mapping->attempts > 0) {
            $shopifyId = $this->findExisting($migration, $wooItem);

            if ($shopifyId) {
                MigrationRunner::log(
                    $migration->id,
                    'warning',
                    $this->entityType(),
                    "Item {$wooId} had already been created in Shopify by an interrupted run — reusing it instead of creating a duplicate.",
                );

                // Nothing to update for a migrator that only ever creates: adopt
                // the object and move on.
                if (! $this->upserts()) {
                    $mapping->update([
                        'migration_id' => $migration->id,
                        'shopify_id' => $shopifyId,
                        'status' => 'success',
                        'error' => null,
                    ]);

                    return MigrationItemResult::skipped($shopifyId);
                }
            }
        }

        // Recorded BEFORE the write, so an interruption during it is visible to
        // the recovery above on the next pass.
        $mapping->forceFill(['attempts' => (int) $mapping->attempts + 1])->save();

        $result = $this->doMigrate($migration, $wooItem, $shopifyId);

        $mapping->update([
            'migration_id' => $migration->id,
            'shopify_id' => $result->shopifyId ?? $shopifyId,
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
        $keys = [
            'shop_id' => $migration->shop_id,
            'entity_type' => $this->entityType(),
            'woocommerce_id' => $wooId,
        ];

        try {
            return MigrationItem::firstOrCreate($keys, [
                'migration_id' => $migration->id,
                'status' => 'pending',
            ]);
        } catch (UniqueConstraintViolationException) {
            // firstOrCreate is a SELECT then an INSERT, not an atomic operation.
            // Batches of one entity now run in parallel and the retry job can be
            // working the same shop, so two callers really do race for the same
            // row — and the unique key is what stops one of them winning twice.
            return MigrationItem::where($keys)->firstOrFail();
        }
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
