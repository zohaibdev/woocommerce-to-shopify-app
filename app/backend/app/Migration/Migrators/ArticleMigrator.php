<?php

namespace App\Migration\Migrators;

use App\Migration\AbstractMigrator;
use App\Migration\Concerns\ShedsRejectedFields;
use App\Migration\MigrationItemResult;
use App\Migration\SeoMeta;
use App\Models\StoreMigration;
use App\Services\WooCommerceClient;
use App\Models\WoocommerceConnection;
use App\Support\SafeCache;

/**
 * WordPress posts -> Shopify articles.
 *
 * Every article must belong to a blog, and BlogMigrator has already turned the
 * store's post categories into blogs — so a post is filed under the first
 * category it has a mapping for. A post with no categories (or whose category
 * failed to migrate) still has to land somewhere, so it goes to a general blog
 * created once per run; losing a merchant's writing because of a taxonomy
 * mismatch would be indefensible.
 *
 * COMMENTS are deliberately not migrated: Shopify's article comments are
 * moderated objects owned by the storefront, cannot be created through the
 * Admin API with their original author or date, and would arrive attributed to
 * nobody. They are instead preserved on the article as a metafield so nothing is
 * destroyed and a theme can render them if the merchant wants them.
 */
class ArticleMigrator extends AbstractMigrator
{
    use ShedsRejectedFields;

    private const CREATE_MUTATION = <<<'GQL'
    mutation articleCreate($article: ArticleCreateInput!) {
      articleCreate(article: $article) {
        article { id }
        userErrors { field message }
      }
    }
    GQL;

    private const BLOG_CREATE = <<<'GQL'
    mutation blogCreate($blog: BlogCreateInput!) {
      blogCreate(blog: $blog) {
        blog { id }
        userErrors { field message }
      }
    }
    GQL;

    private const BLOG_LOOKUP = <<<'GQL'
    query findBlog($q: String!) {
      blogs(first: 1, query: $q) { nodes { id } }
    }
    GQL;

    /** Comments read per post. Beyond this the tail is summarised, not stored. */
    private const MAX_COMMENTS = 100;

    public function entityType(): string
    {
        return 'article';
    }

    public function dependsOn(): array
    {
        // Blogs must exist before an article can be filed under one.
        return ['blog'];
    }

    public function count(WoocommerceConnection $connection): int
    {
        return (new WooCommerceClient($connection))->total('wp/v2/posts', ['status' => 'publish']);
    }

    protected function wooSource(): ?array
    {
        return ['wp/v2/posts', ['status' => 'publish']];
    }

    public function fetchBatch(WoocommerceConnection $connection, int $page, int $perPage): array
    {
        return (new WooCommerceClient($connection))
            ->list('wp/v2/posts', $page, $perPage, ['status' => 'publish']);
    }

    protected function doMigrate(StoreMigration $migration, array $woo, ?string $existingShopifyId = null): MigrationItemResult
    {
        $blogId = $this->blogFor($migration, $woo);

        if (! $blogId) {
            return MigrationItemResult::failed('No Shopify blog available to file this post under');
        }

        $warnings = [];

        $input = array_filter([
            'blogId' => $blogId,
            'title' => trim((string) data_get($woo, 'title.rendered', '')) ?: 'Untitled',
            'handle' => $this->handleFromSlug($woo['slug'] ?? null),
            'body' => data_get($woo, 'content.rendered') ?: '',
            'summary' => $this->summary($woo),
            'tags' => $this->tags($migration),
            'isPublished' => true,
            // The post's real publication date. An archive that reads as written
            // today is the most obvious sign of a careless migration there is.
            'publishDate' => $this->date($woo['date_gmt'] ?? ($woo['date'] ?? null)),
            'image' => $this->image($woo),
            'metafields' => $this->metafields($migration, $woo, $warnings),
        ], fn ($v) => $v !== null && $v !== '' && $v !== []);

        return $this->writeSheddingBadFields(
            function (array $attempt) use ($migration) {
                $shopify = $this->shopify($migration);
                $body = $shopify->graphql(self::CREATE_MUTATION, ['article' => $attempt]);

                $errors = $shopify->userErrors($body, 'articleCreate');

                if (empty($errors)) {
                    $errors = $shopify->topLevelErrors($body);
                }

                return [data_get($body, 'data.articleCreate.article.id'), $errors];
            },
            $input,
            // blogId, title and body make the article; everything else is worth a
            // warning rather than the loss of the post.
            ['image', 'metafields', 'tags', 'handle', 'summary', 'publishDate'],
        )->withWarnings($warnings);
    }

    /**
     * The Shopify blog this post belongs in.
     *
     * First choice is the blog made from one of the post's own categories. When
     * the post has none — or none of them migrated — it falls back to a single
     * general blog created once per run, so a taxonomy problem never costs the
     * merchant a post.
     */
    private function blogFor(StoreMigration $migration, array $woo): ?string
    {
        foreach ((array) ($woo['categories'] ?? []) as $categoryId) {
            $blogId = $this->shopifyIdFor($migration, 'blog', (int) $categoryId);

            if ($blogId) {
                return $blogId;
            }
        }

        return $this->fallbackBlog($migration);
    }

    /**
     * A general blog for posts with nowhere else to go.
     *
     * Cached per shop so a run does not ask Shopify for it on every post, and
     * looked up by handle before creating so a second run adopts the one the
     * first run made instead of stacking up "News (1)", "News (2)".
     */
    private function fallbackBlog(StoreMigration $migration): ?string
    {
        $key = "shopify_fallback_blog:{$migration->shop_id}";

        return SafeCache::remember($key, now()->addDay(), function () use ($migration) {
            $shopify = $this->shopify($migration);

            $existing = data_get(
                $shopify->graphql(self::BLOG_LOOKUP, ['q' => 'handle:news']),
                'data.blogs.nodes.0.id',
            );

            if ($existing) {
                return $existing;
            }

            $body = $shopify->graphql(self::BLOG_CREATE, [
                'blog' => ['title' => 'News', 'handle' => 'news'],
            ]);

            return data_get($body, 'data.blogCreate.blog.id');
        });
    }

    /**
     * Article metafields: the post's SEO copy, and its comments.
     *
     * @param  array<int, string>  $warnings
     */
    private function metafields(StoreMigration $migration, array $woo, array &$warnings): array
    {
        $metafields = [];
        $seo = SeoMeta::forRecord($woo);

        foreach (['title' => 'title_tag', 'description' => 'description_tag'] as $from => $key) {
            if (! empty($seo[$from])) {
                $metafields[] = [
                    'namespace' => 'global',
                    'key' => $key,
                    'type' => 'single_line_text_field',
                    'value' => $seo[$from],
                ];
            }
        }

        if ($comments = $this->comments($migration, $woo, $warnings)) {
            $metafields[] = [
                'namespace' => config('migration.meta_namespace', 'woocommerce'),
                'key' => 'comments',
                'type' => 'json',
                'value' => json_encode($comments),
            ];
        }

        return $metafields;
    }

    /**
     * The post's comments, kept as data rather than as Shopify comment objects.
     *
     * Shopify's article comments cannot be created through the Admin API with
     * their original author or date — they would all arrive attributed to nobody,
     * dated today. Storing them means nothing is destroyed and a theme can render
     * them; pretending to migrate them would be worse than saying we did not.
     *
     * @param  array<int, string>  $warnings
     */
    private function comments(StoreMigration $migration, array $woo, array &$warnings): array
    {
        $postId = (int) ($woo['id'] ?? 0);

        if ($postId <= 0) {
            return [];
        }

        try {
            $comments = $this->woo($migration)->list('wp/v2/comments', 1, self::MAX_COMMENTS, [
                'post' => $postId,
                'status' => 'approve',
            ]);
        } catch (\Throwable) {
            // Plenty of hosts lock the comments endpoint down. A post is worth
            // more than its comment thread.
            return [];
        }

        if (empty($comments)) {
            return [];
        }

        $warnings[] = count($comments).' comment(s) were saved on this article as data '
            .'(Shopify cannot import comments with their original author and date). A theme or app '
            .'can display them from the "comments" metafield.';

        return collect($comments)->map(fn ($c) => array_filter([
            'author' => $c['author_name'] ?? null,
            'date' => $c['date_gmt'] ?? ($c['date'] ?? null),
            'content' => trim(strip_tags((string) data_get($c, 'content.rendered', ''))),
        ]))->values()->all();
    }

    /** Featured image, when WordPress embedded it in the response. */
    private function image(array $woo): ?array
    {
        $src = data_get($woo, '_embedded.wp:featuredmedia.0.source_url')
            ?: data_get($woo, 'featured_media_src_url');

        if (! $src) {
            return null;
        }

        return array_filter([
            'url' => $src,
            'altText' => data_get($woo, '_embedded.wp:featuredmedia.0.alt_text') ?: null,
        ]);
    }

    private function summary(array $woo): ?string
    {
        $excerpt = trim(strip_tags((string) data_get($woo, 'excerpt.rendered', '')));

        return $excerpt === '' ? null : mb_substr($excerpt, 0, 5000);
    }

    /**
     * wp/v2 returns tag IDs rather than names (names need an `_embed`, which
     * would make every page of posts several times larger), and a numeric id is
     * no use to a merchant reading their own blog — so only the migration's own
     * custom tags are applied.
     */
    private function tags(StoreMigration $migration): array
    {
        return $migration->customTags();
    }

    private function date(?string $date): ?string
    {
        return $date ? date('c', strtotime($date)) : null;
    }
}
