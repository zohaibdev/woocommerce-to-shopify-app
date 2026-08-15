<?php

namespace App\Migration\Migrators;

use App\Migration\AbstractMigrator;
use App\Migration\Concerns\ShedsRejectedFields;
use App\Migration\MigrationItemResult;
use App\Models\StoreMigration;
use App\Models\WoocommerceConnection;
use App\Services\WooCommerceClient;

/**
 * WordPress post categories -> Shopify blogs.
 *
 * The two platforms disagree about what a blog IS. WordPress has exactly one
 * blog whose posts are filed under categories; Shopify has many blogs, each an
 * independent container with its own handle and its own set of articles.
 *
 * Post CATEGORY is therefore the only honest mapping for a Shopify blog: it is
 * the thing a WordPress reader navigates by, it already has a title and a slug,
 * and it gives articles somewhere to belong. A store with no post categories at
 * all still gets one blog, because ArticleMigrator has to file its posts
 * somewhere — see ArticleMigrator::blogFor().
 */
class BlogMigrator extends AbstractMigrator
{
    use ShedsRejectedFields;

    private const CREATE_MUTATION = <<<'GQL'
    mutation blogCreate($blog: BlogCreateInput!) {
      blogCreate(blog: $blog) {
        blog { id }
        userErrors { field message }
      }
    }
    GQL;

    private const UPDATE_MUTATION = <<<'GQL'
    mutation blogUpdate($id: ID!, $blog: BlogUpdateInput!) {
      blogUpdate(id: $id, blog: $blog) {
        blog { id }
        userErrors { field message }
      }
    }
    GQL;

    public function entityType(): string
    {
        return 'blog';
    }

    protected function upserts(): bool
    {
        // Articles look their blog up by mapping on every run, so a blog the
        // merchant deleted in Shopify has to be able to come back — otherwise a
        // re-run files every article against an id that no longer exists.
        return true;
    }

    public function count(WoocommerceConnection $connection): int
    {
        return (new WooCommerceClient($connection))->total('wp/v2/categories');
    }

    protected function wooSource(): ?array
    {
        return ['wp/v2/categories', []];
    }

    public function fetchBatch(WoocommerceConnection $connection, int $page, int $perPage): array
    {
        return (new WooCommerceClient($connection))->list('wp/v2/categories', $page, $perPage);
    }

    protected function doMigrate(StoreMigration $migration, array $woo, ?string $existingShopifyId = null): MigrationItemResult
    {
        $input = array_filter([
            'title' => trim((string) ($woo['name'] ?? '')) ?: 'Blog',
            // Keeps /category/news reachable as /blogs/news.
            'handle' => $this->handleFromSlug($woo['slug'] ?? null),
        ], fn ($v) => $v !== null && $v !== '');

        return $this->writeSheddingBadFields(
            function (array $attempt) use ($migration, $existingShopifyId) {
                $id = $this->write($migration, $attempt, $existingShopifyId);

                return is_array($id) ? [null, $id] : [$id, []];
            },
            $input,
            ['handle'],
        );
    }

    /**
     * Update the blog we already made, or create it.
     *
     * This migrator UPSERTS, and an upsert that only ever calls blogCreate is not
     * an upsert — it is a duplicate factory. A second run would have given the
     * merchant "Company News" twice, and a third three times, while the mapping
     * table pointed at whichever one was made last.
     *
     * Returns the gid, an errors array on a real failure, or null when the blog
     * we meant to update has been deleted in Shopify (caller recreates).
     */
    private function write(StoreMigration $migration, array $input, ?string $existingShopifyId): string|array|null
    {
        if (! $existingShopifyId) {
            return $this->run($migration, self::CREATE_MUTATION, 'blogCreate', ['blog' => $input]);
        }

        $id = $this->run($migration, self::UPDATE_MUTATION, 'blogUpdate', [
            'id' => $existingShopifyId,
            'blog' => $input,
        ]);

        return $id === null
            ? $this->run($migration, self::CREATE_MUTATION, 'blogCreate', ['blog' => $input])
            : $id;
    }

    /** @return string|array|null gid, errors, or null when the target is gone */
    private function run(StoreMigration $migration, string $mutation, string $field, array $variables): string|array|null
    {
        $shopify = $this->shopify($migration);
        $body = $shopify->graphql($mutation, $variables);

        $errors = $shopify->userErrors($body, $field);

        // Surface top-level errors too (e.g. the write_content scope).
        if (empty($errors)) {
            $errors = $shopify->topLevelErrors($body);
        }

        if (! empty($errors)) {
            if (isset($variables['id']) && stripos(json_encode($errors), 'does not exist') !== false) {
                return null;
            }

            return $errors;
        }

        return data_get($body, "data.{$field}.blog.id");
    }
}
