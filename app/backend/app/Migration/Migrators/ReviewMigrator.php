<?php

namespace App\Migration\Migrators;

use App\Migration\AbstractMigrator;
use App\Migration\MigrationItemResult;
use App\Models\StoreMigration;
use App\Models\WoocommerceConnection;
use App\Services\WooCommerceClient;
use App\Support\SafeCache;

/**
 * WooCommerce product reviews -> Shopify product metafields.
 *
 * Shopify's Admin API has NO product reviews. The first-party Product Reviews
 * app was sunset, and every replacement (Judge.me, Loox, Okendo …) keeps reviews
 * in its own system behind its own API — which the merchant may not even have
 * installed. There is nowhere native to put a review.
 *
 * So reviews are migrated as DATA rather than as review objects: every review of
 * a product is written to one `woocommerce.reviews` JSON metafield on that
 * product, with its date, rating, author and text intact. Nothing is lost, the
 * merchant owns it, and any review app or theme block can be pointed at it
 * later. What it does NOT do is make stars appear on the storefront — that needs
 * a review app, and this app cannot honestly promise it.
 *
 * THE SOURCE IS REVIEWS; THE WRITE IS PER PRODUCT. Those are different grains,
 * and getting that wrong is easy: walking PRODUCTS instead would have made the
 * wizard offer "50,000 reviews" to a store with thirty, and made the review pass
 * re-read the entire catalogue to find the handful that had any. So the source
 * is the reviews endpoint — an honest count, and only the products that actually
 * have reviews are touched — while the metafield write covers a product's WHOLE
 * review set at once and is done only once per product per run (see written()).
 */
class ReviewMigrator extends AbstractMigrator
{
    private const SET_MUTATION = <<<'GQL'
    mutation metafieldsSet($metafields: [MetafieldsSetInput!]!) {
      metafieldsSet(metafields: $metafields) {
        metafields { id }
        userErrors { field message }
      }
    }
    GQL;

    /** Reviews kept per product. A product past this is reported, not silently cut. */
    private const MAX_PER_PRODUCT = 500;

    public function entityType(): string
    {
        return 'review';
    }

    public function dependsOn(): array
    {
        // A review has nowhere to live until its product exists in Shopify.
        return ['product'];
    }

    public function count(WoocommerceConnection $connection): int
    {
        return (new WooCommerceClient($connection))->total('products/reviews', ['status' => 'approved']);
    }

    protected function wooSource(): ?array
    {
        return ['products/reviews', ['status' => 'approved']];
    }

    public function fetchBatch(WoocommerceConnection $connection, int $page, int $perPage): array
    {
        return (new WooCommerceClient($connection))
            ->list('products/reviews', $page, $perPage, ['status' => 'approved']);
    }

    protected function doMigrate(StoreMigration $migration, array $woo, ?string $existingShopifyId = null): MigrationItemResult
    {
        $productId = (int) ($woo['product_id'] ?? 0);

        if ($productId <= 0) {
            return MigrationItemResult::failed('Review is not attached to a product');
        }

        $shopifyProductId = $this->shopifyIdFor($migration, 'product', $productId);

        if (! $shopifyProductId) {
            // SKIPPED, not failed. On a capped plan this is the NORMAL case, not
            // an error: products stop at the plan limit while reviews are
            // unmetered, so every review of product 11 upwards lands here. Marking
            // those failed turned an ordinary free-plan run into
            // "completed_with_errors" with hundreds of red rows the merchant could
            // do nothing about, and buried the real failures among them.
            return MigrationItemResult::skipped()->withWarnings([
                "The product this review belongs to (WooCommerce #{$productId}) has not been "
                    .'migrated, so the review had nowhere to attach. Migrate that product — or '
                    .'upgrade if your plan limit stopped it — and run this again.',
            ]);
        }

        // Another review of the same product already wrote the complete set this
        // run. Writing again would send Shopify the identical metafield once per
        // review — correct, but a wasted call per review on a well-reviewed product.
        if ($this->alreadyWritten($migration, $productId)) {
            return MigrationItemResult::success($shopifyProductId);
        }

        $warnings = [];
        $reviews = $this->reviewsFor($migration, $productId, $warnings);

        if (empty($reviews)) {
            // The reviews endpoint answered but gave us nothing usable for this
            // product — record it rather than claiming a write we did not make.
            return MigrationItemResult::skipped($shopifyProductId);
        }

        $shopify = $this->shopify($migration);

        $body = $shopify->graphql(self::SET_MUTATION, [
            'metafields' => [[
                'ownerId' => $shopifyProductId,
                'namespace' => config('migration.meta_namespace', 'woocommerce'),
                'key' => 'reviews',
                'type' => 'json',
                'value' => json_encode([
                    'count' => count($reviews),
                    'average' => round(collect($reviews)->avg('rating') ?? 0, 2),
                    'reviews' => $reviews,
                ]),
            ]],
        ]);

        if ($fatal = $this->fatalShopifyError($shopify, $body)) {
            return MigrationItemResult::failed($fatal);
        }

        $errors = $shopify->userErrors($body, 'metafieldsSet');

        if (! empty($errors)) {
            return MigrationItemResult::failed(json_encode($errors));
        }

        $this->markWritten($migration, $productId);

        return MigrationItemResult::success($shopifyProductId)->withWarnings(array_merge($warnings, [
            count($reviews).' review(s) were saved on this product as data. Shopify has no built-in '
                .'reviews, so they will not show on your storefront until a review app or theme block '
                .'is pointed at the "reviews" metafield.',
        ]));
    }

    /**
     * Every approved review of one product, newest first.
     *
     * Fetched in full regardless of which review triggered the write, which is
     * what makes the per-product guard safe: whichever review gets there first
     * writes the complete set, so the result does not depend on batch boundaries.
     *
     * @param  array<int, string>|null  $warnings
     * @return array<int, array>
     */
    private function reviewsFor(StoreMigration $migration, int $productId, ?array &$warnings = null): array
    {
        $warnings = [];
        $client = $this->woo($migration);
        $all = [];
        $page = 1;

        while (count($all) <= self::MAX_PER_PRODUCT) {
            try {
                $chunk = $client->list('products/reviews', $page, 100, [
                    'product' => $productId,
                    'status' => 'approved',
                ]);
            } catch (\Throwable) {
                // A store with reviews disabled answers 404 here. Whatever we
                // already have is still worth keeping.
                break;
            }

            $all = array_merge($all, $chunk);

            if (count($chunk) < 100) {
                break;
            }

            $page++;
        }

        if (count($all) > self::MAX_PER_PRODUCT) {
            $warnings[] = 'This product has '.count($all).' reviews; the most recent '
                .self::MAX_PER_PRODUCT.' were saved.';

            $all = array_slice($all, 0, self::MAX_PER_PRODUCT);
        }

        return collect($all)
            ->map(fn ($r) => array_filter([
                'date' => $r['date_created_gmt'] ?? ($r['date_created'] ?? null),
                'rating' => isset($r['rating']) ? (int) $r['rating'] : null,
                'author' => $r['reviewer'] ?? null,
                // The reviewer's EMAIL is deliberately not carried over: it is
                // personal data the merchant has no need for, and metafields are
                // readable by the storefront.
                'verified' => (bool) ($r['verified'] ?? false),
                'content' => trim(strip_tags((string) ($r['review'] ?? ''))),
            ], fn ($v) => $v !== null && $v !== ''))
            ->values()
            ->all();
    }

    /**
     * Has this product's review set already been written this run?
     *
     * A miss (or a dead cache) only costs a repeated write of identical content,
     * so this is an optimisation and goes through SafeCache — never something
     * that can fail the record.
     */
    private function alreadyWritten(StoreMigration $migration, int $productId): bool
    {
        return (bool) SafeCache::get($this->writtenKey($migration, $productId), false);
    }

    private function markWritten(StoreMigration $migration, int $productId): void
    {
        SafeCache::put($this->writtenKey($migration, $productId), true, now()->addDay());
    }

    private function writtenKey(StoreMigration $migration, int $productId): string
    {
        return "migration:{$migration->id}:reviews:{$productId}";
    }
}
