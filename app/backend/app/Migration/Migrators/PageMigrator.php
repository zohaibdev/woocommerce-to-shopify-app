<?php

namespace App\Migration\Migrators;

use App\Migration\AbstractMigrator;
use App\Migration\Concerns\ShedsRejectedFields;
use App\Migration\MigrationItemResult;
use App\Migration\SeoMeta;
use App\Models\StoreMigration;
use App\Models\WoocommerceConnection;
use App\Services\WooCommerceClient;

/**
 * WordPress pages -> Shopify pages.
 *
 * These are the About / Shipping / Returns / Size Guide pages a store has
 * accumulated over years. They are rarely the reason a merchant migrates, and
 * always the thing they discover missing the week after — usually because a
 * footer link or a checkout policy points at one.
 *
 * Read from `wp/v2/pages`, which the consumer key/secret does NOT authenticate.
 * That is fine for published pages (WordPress serves those to anyone) and means
 * drafts are invisible to us — see WooCommerceClient::baseFor().
 */
class PageMigrator extends AbstractMigrator
{
    use ShedsRejectedFields;

    private const CREATE_MUTATION = <<<'GQL'
    mutation pageCreate($page: PageCreateInput!) {
      pageCreate(page: $page) {
        page { id }
        userErrors { field message }
      }
    }
    GQL;

    public function entityType(): string
    {
        return 'page';
    }

    public function count(WoocommerceConnection $connection): int
    {
        return (new WooCommerceClient($connection))->total('wp/v2/pages', ['status' => 'publish']);
    }

    protected function wooSource(): ?array
    {
        return ['wp/v2/pages', ['status' => 'publish']];
    }

    public function fetchBatch(WoocommerceConnection $connection, int $page, int $perPage): array
    {
        return (new WooCommerceClient($connection))
            ->list('wp/v2/pages', $page, $perPage, ['status' => 'publish']);
    }

    protected function doMigrate(StoreMigration $migration, array $woo, ?string $existingShopifyId = null): MigrationItemResult
    {
        $title = trim((string) data_get($woo, 'title.rendered', '')) ?: 'Untitled';

        $input = array_filter([
            'title' => $title,
            // Keeps /about-us reachable at /pages/about-us, so the merchant's
            // footer links and any redirect they set up still resolve.
            'handle' => $this->handleFromSlug($woo['slug'] ?? null),
            'body' => data_get($woo, 'content.rendered') ?: '',
            'isPublished' => true,
            // WordPress dates are the page's real history; a store's policy pages
            // reading as written-today is a small lie the merchant did not ask for.
            'publishDate' => $this->date($woo['date_gmt'] ?? ($woo['date'] ?? null)),
            // Shopify has no `seo` field on a page — the storefront reads the
            // title and description tags from these two reserved metafields, so
            // that is where a WordPress page's SEO copy has to land.
            'metafields' => $this->seoMetafields($woo),
        ], fn ($v) => $v !== null && $v !== '' && $v !== []);

        return $this->writeSheddingBadFields(
            function (array $attempt) use ($migration) {
                $shopify = $this->shopify($migration);
                $body = $shopify->graphql(self::CREATE_MUTATION, ['page' => $attempt]);

                $errors = $shopify->userErrors($body, 'pageCreate');

                // Surface top-level errors too (e.g. the write_content scope).
                if (empty($errors)) {
                    $errors = $shopify->topLevelErrors($body);
                }

                return [data_get($body, 'data.pageCreate.page.id'), $errors];
            },
            $input,
            // The title and body ARE the page; a handle Shopify has already given
            // something else, or a date it will not take, is worth a warning.
            ['handle', 'publishDate', 'templateSuffix', 'metafields'],
        );
    }

    /**
     * SEO title/description as the reserved metafields Shopify's themes read.
     *
     * @return array<int, array{namespace: string, key: string, type: string, value: string}>
     */
    private function seoMetafields(array $woo): array
    {
        $seo = SeoMeta::forRecord($woo);

        $map = ['title' => 'title_tag', 'description' => 'description_tag'];
        $metafields = [];

        foreach ($map as $from => $key) {
            if (! empty($seo[$from])) {
                $metafields[] = [
                    'namespace' => 'global',
                    'key' => $key,
                    'type' => 'single_line_text_field',
                    'value' => $seo[$from],
                ];
            }
        }

        return $metafields;
    }

    private function date(?string $date): ?string
    {
        return $date ? date('c', strtotime($date)) : null;
    }
}
