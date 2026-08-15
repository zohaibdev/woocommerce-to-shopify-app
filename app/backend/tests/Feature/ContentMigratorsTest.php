<?php

namespace Tests\Feature;

use App\Migration\MigratorRegistry;
use App\Migration\Migrators\ArticleMigrator;
use App\Migration\Migrators\BlogMigrator;
use App\Migration\Migrators\CouponMigrator;
use App\Migration\Migrators\ReviewMigrator;
use App\Models\MigrationItem;
use App\Models\Shop;
use App\Models\StoreMigration;
use App\Models\WoocommerceConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The five entities a WooCommerce store has that the migration used to leave
 * behind entirely: coupons, blogs, blog posts and product reviews.
 *
 * Two of them do not map cleanly and the tests say so explicitly — a Woo coupon
 * carries restrictions Shopify cannot express, and Shopify has no product
 * reviews at all. Both cases are about telling the merchant the truth rather
 * than reporting a clean success over a quiet loss.
 */
class ContentMigratorsTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): StoreMigration
    {
        $shop = Shop::create([
            'shopify_domain' => 'content.myshopify.com',
            'access_token' => 'shpat_x',
            'token_expires_at' => now()->addHour(),
        ]);

        $conn = WoocommerceConnection::create([
            'shop_id' => $shop->id,
            'store_url' => 'https://woo.example.com',
            'consumer_key' => 'ck',
            'consumer_secret' => 'cs',
            'status' => 'connected',
        ]);

        $migration = StoreMigration::create([
            'shop_id' => $shop->id,
            'woocommerce_connection_id' => $conn->id,
            'status' => 'processing',
            'selected_entities' => ['blog'],
        ]);

        $migration->setRelation('shop', $shop);
        $migration->setRelation('connection', $conn);

        return $migration;
    }

    /** Capture the variables of whichever mutation names $field. */
    private function capture(?array &$captured, string $field, string $objectKey, array $wooResponses = []): void
    {
        Http::fake(function ($request) use (&$captured, $field, $objectKey, $wooResponses) {
            $url = $request->url();

            if (! str_contains($url, 'graphql.json')) {
                foreach ($wooResponses as $needle => $payload) {
                    if (str_contains($url, $needle)) {
                        return Http::response($payload, 200);
                    }
                }

                return Http::response([], 200);
            }

            $body = json_decode($request->body(), true);
            $query = (string) data_get($body, 'query');

            if (str_contains($query, $field)) {
                $captured = data_get($body, 'variables');

                return Http::response([
                    'data' => [$field => [$objectKey => ['id' => 'gid://shopify/X/1'], 'userErrors' => []]],
                ], 200);
            }

            return Http::response(['data' => []], 200);
        });
    }

    // ------------------------------------------------------------------ registry

    /** @test */
    public function the_new_entities_are_registered_and_ordered_by_dependency(): void
    {
        $registry = app(MigratorRegistry::class);

        foreach (['coupon', 'blog', 'article', 'review'] as $type) {
            $this->assertTrue($registry->has($type), "{$type} must be selectable");
        }

        $ordered = $registry->order(['review', 'article', 'product', 'blog']);

        // An article needs its blog, and a review needs its product — filing
        // either before its parent exists produces a run that fails every record
        // for a reason the merchant cannot act on.
        $this->assertLessThan(
            array_search('article', $ordered, true),
            array_search('blog', $ordered, true),
        );
        $this->assertLessThan(
            array_search('review', $ordered, true),
            array_search('product', $ordered, true),
        );
    }

    // ------------------------------------------------------------------- coupons

    /** @test */
    public function a_percentage_coupon_becomes_a_shopify_discount_code(): void
    {
        $captured = null;
        $this->capture($captured, 'discountCodeBasicCreate', 'codeDiscountNode');

        (new CouponMigrator())->migrateItem($this->migration(), [
            'id' => 1,
            'code' => 'SAVE20',
            'discount_type' => 'percent',
            'amount' => '20.00',
            'minimum_amount' => '50.00',
            'usage_limit' => 100,
        ]);

        $discount = $captured['basicCodeDiscount'];

        $this->assertSame('SAVE20', $discount['code']);
        // Woo stores whole percent; Shopify wants a fraction.
        $this->assertSame(0.2, $discount['customerGets']['value']['percentage']);
        // assertEquals, not assertSame: the value goes through json_encode on the
        // way to Shopify, which renders 50.0 as 50.
        $this->assertEquals(50.0, $discount['minimumRequirement']['subtotal']['greaterThanOrEqualToSubtotal']);
        $this->assertSame(100, $discount['usageLimit']);
    }

    /** @test */
    public function a_coupon_restriction_shopify_cannot_express_is_reported_on_the_record(): void
    {
        $captured = null;
        $this->capture($captured, 'discountCodeBasicCreate', 'codeDiscountNode');

        $result = (new CouponMigrator())->migrateItem($this->migration(), [
            'id' => 2,
            'code' => 'STAFFONLY',
            'discount_type' => 'fixed_cart',
            'amount' => '15.00',
            'email_restrictions' => ['staff@example.com'],
            'exclude_sale_items' => true,
        ]);

        // A staff-only code that now works for everyone is worse than one the
        // merchant has to re-create by hand — so it is said out loud.
        $this->assertSame('success', $result->status);
        $this->assertStringContainsString('email addresses', $result->warning);
        $this->assertStringContainsString('sale items', $result->warning);
    }

    /** @test */
    public function a_coupon_type_with_no_shopify_equivalent_fails_loudly(): void
    {
        Http::fake();

        $result = (new CouponMigrator())->migrateItem($this->migration(), [
            'id' => 3,
            'code' => 'WEIRD',
            'discount_type' => 'signup_credit',
            'amount' => '5.00',
        ]);

        $this->assertSame('failed', $result->status);
        $this->assertStringContainsString('signup_credit', $result->error);
    }

    // --------------------------------------------------------------------- blogs

    /** @test */
    public function a_post_category_becomes_a_blog(): void
    {
        $captured = null;
        $this->capture($captured, 'blogCreate', 'blog');

        (new BlogMigrator())->migrateItem($this->migration(), [
            'id' => 20,
            'name' => 'Company News',
            'slug' => 'company-news',
        ]);

        $this->assertSame('Company News', $captured['blog']['title']);
        $this->assertSame('company-news', $captured['blog']['handle']);
    }

    /** @test */
    public function re_running_updates_a_blog_instead_of_creating_a_second_one(): void
    {
        $migration = $this->migration();

        $mutations = [];

        Http::fake(function ($request) use (&$mutations) {
            if (! str_contains($request->url(), 'graphql.json')) {
                return Http::response([], 200);
            }

            $query = (string) data_get(json_decode($request->body(), true), 'query');
            $field = str_contains($query, 'blogUpdate') ? 'blogUpdate' : 'blogCreate';
            $mutations[] = $field;

            return Http::response([
                'data' => [$field => ['blog' => ['id' => 'gid://shopify/Blog/1'], 'userErrors' => []]],
            ], 200);
        });

        $migrator = new BlogMigrator();
        $category = ['id' => 60, 'name' => 'Company News', 'slug' => 'company-news'];

        $migrator->migrateItem($migration, $category);
        $migrator->migrateItem($migration, $category);

        // This migrator upserts, and an upsert that only ever calls blogCreate is
        // a duplicate factory: run twice and the merchant has "Company News"
        // twice, with the mapping pointing at whichever came last.
        $this->assertSame(['blogCreate', 'blogUpdate'], $mutations);
    }

    /** @test */
    public function a_coupon_with_no_creation_date_still_gets_a_start_date(): void
    {
        $captured = null;
        $this->capture($captured, 'discountCodeBasicCreate', 'codeDiscountNode');

        (new CouponMigrator())->migrateItem($this->migration(), [
            'id' => 4,
            'code' => 'OLDCODE',
            'discount_type' => 'percent',
            'amount' => '10.00',
            // An old store with no recorded creation date is ordinary — and
            // Shopify REQUIRES startsAt, so omitting it fails the whole write.
            'date_created' => null,
        ]);

        $this->assertNotEmpty($captured['basicCodeDiscount']['startsAt']);
    }

    /** @test */
    public function a_post_is_filed_under_the_blog_made_from_its_category(): void
    {
        $migration = $this->migration();

        // BlogMigrator already turned Woo category 20 into this Shopify blog.
        MigrationItem::create([
            'migration_id' => $migration->id,
            'shop_id' => $migration->shop_id,
            'entity_type' => 'blog',
            'woocommerce_id' => 20,
            'shopify_id' => 'gid://shopify/Blog/500',
            'status' => 'success',
        ]);

        $captured = null;
        $this->capture($captured, 'articleCreate', 'article');

        (new ArticleMigrator())->migrateItem($migration, [
            'id' => 30,
            'slug' => 'our-new-warehouse',
            'title' => ['rendered' => 'Our New Warehouse'],
            'content' => ['rendered' => '<p>Bigger and closer.</p>'],
            'date_gmt' => '2021-07-15T12:00:00',
            'categories' => [20],
        ]);

        $article = $captured['article'];

        $this->assertSame('gid://shopify/Blog/500', $article['blogId']);
        $this->assertSame('our-new-warehouse', $article['handle']);
        // An archive that reads as written today is the most obvious sign of a
        // careless migration there is.
        $this->assertStringContainsString('2021-07-15', $article['publishDate']);
    }

    /** @test */
    public function posts_are_read_with_their_featured_image_embedded(): void
    {
        $urls = [];

        Http::fake(function ($request) use (&$urls) {
            $urls[] = $request->url();

            return Http::response([], 200);
        });

        $conn = $this->migration()->connection;

        (new ArticleMigrator())->fetchBatch($conn, 1, 10);

        // A plain wp/v2/posts response carries `featured_media` as a bare
        // ATTACHMENT ID, never a URL — so without asking WordPress to embed the
        // media, image() had nothing to read and EVERY migrated post arrived with
        // no featured image. Silently, because a post without one is still valid.
        $this->assertStringContainsString('_embed=wp', urldecode($urls[0]));
    }

    /** @test */
    public function an_embedded_featured_image_reaches_shopify(): void
    {
        $migration = $this->migration();

        MigrationItem::create([
            'migration_id' => $migration->id,
            'shop_id' => $migration->shop_id,
            'entity_type' => 'blog',
            'woocommerce_id' => 20,
            'shopify_id' => 'gid://shopify/Blog/500',
            'status' => 'success',
        ]);

        $captured = null;
        $this->capture($captured, 'articleCreate', 'article');

        (new ArticleMigrator())->migrateItem($migration, [
            'id' => 31,
            'title' => ['rendered' => 'With A Picture'],
            'content' => ['rendered' => '<p>Look.</p>'],
            'categories' => [20],
            '_embedded' => ['wp:featuredmedia' => [[
                'source_url' => 'https://woo.example.com/wp-content/uploads/hero.jpg',
                'alt_text' => 'The new warehouse',
            ]]],
        ]);

        $this->assertSame(
            'https://woo.example.com/wp-content/uploads/hero.jpg',
            $captured['article']['image']['url'],
        );
        $this->assertSame('The new warehouse', $captured['article']['image']['altText']);
    }

    /** @test */
    public function a_post_with_no_usable_category_still_lands_somewhere(): void
    {
        $captured = null;

        Http::fake(function ($request) use (&$captured) {
            if (! str_contains($request->url(), 'graphql.json')) {
                return Http::response([], 200);
            }

            $body = json_decode($request->body(), true);
            $query = (string) data_get($body, 'query');

            if (str_contains($query, 'findBlog')) {
                return Http::response(['data' => ['blogs' => ['nodes' => []]]], 200);
            }

            if (str_contains($query, 'blogCreate')) {
                return Http::response([
                    'data' => ['blogCreate' => ['blog' => ['id' => 'gid://shopify/Blog/999'], 'userErrors' => []]],
                ], 200);
            }

            $captured = data_get($body, 'variables');

            return Http::response([
                'data' => ['articleCreate' => ['article' => ['id' => 'gid://shopify/Article/1'], 'userErrors' => []]],
            ], 200);
        });

        $result = (new ArticleMigrator())->migrateItem($this->migration(), [
            'id' => 31,
            'slug' => 'orphan-post',
            'title' => ['rendered' => 'Orphan Post'],
            'content' => ['rendered' => '<p>No category.</p>'],
            'categories' => [],
        ]);

        // Losing a merchant's writing to a taxonomy mismatch would be indefensible.
        $this->assertSame('success', $result->status);
        $this->assertSame('gid://shopify/Blog/999', $captured['article']['blogId']);
    }

    // ------------------------------------------------------------------- reviews

    /** @test */
    public function reviews_are_saved_on_the_product_as_a_metafield(): void
    {
        $migration = $this->migration();

        MigrationItem::create([
            'migration_id' => $migration->id,
            'shop_id' => $migration->shop_id,
            'entity_type' => 'product',
            'woocommerce_id' => 40,
            'shopify_id' => 'gid://shopify/Product/700',
            'status' => 'success',
        ]);

        $captured = null;
        $this->capture($captured, 'metafieldsSet', 'metafields', [
            'products/reviews' => [
                ['id' => 1, 'rating' => 5, 'reviewer' => 'Ali', 'reviewer_email' => 'ali@example.com',
                    'review' => '<p>Excellent</p>', 'date_created_gmt' => '2022-01-05T10:00:00', 'verified' => true],
                ['id' => 2, 'rating' => 3, 'reviewer' => 'Sara', 'reviewer_email' => 'sara@example.com',
                    'review' => 'Fine', 'date_created_gmt' => '2022-02-05T10:00:00', 'verified' => false],
            ],
        ]);

        // The SOURCE is a review; the WRITE covers the product's whole set.
        $result = (new ReviewMigrator())->migrateItem($migration, [
            'id' => 1,
            'product_id' => 40,
            'rating' => 5,
        ]);

        $metafield = $captured['metafields'][0];
        $value = json_decode($metafield['value'], true);

        $this->assertSame('gid://shopify/Product/700', $metafield['ownerId']);
        $this->assertSame('reviews', $metafield['key']);
        $this->assertSame(2, $value['count']);
        $this->assertEquals(4.0, $value['average']);
        $this->assertSame('Ali', $value['reviews'][0]['author']);
        $this->assertSame('Excellent', $value['reviews'][0]['content']);

        // The reviewer's email is personal data with no reason to sit in a
        // storefront-readable metafield.
        $this->assertStringNotContainsString('ali@example.com', $metafield['value']);

        // A merchant who ticks "reviews" and then sees no stars would reasonably
        // think the migration failed, so the record says what actually happened.
        $this->assertStringContainsString('review app or theme block', $result->warning);
    }

    /** @test */
    public function a_second_review_of_the_same_product_does_not_rewrite_the_metafield(): void
    {
        $migration = $this->migration();

        MigrationItem::create([
            'migration_id' => $migration->id,
            'shop_id' => $migration->shop_id,
            'entity_type' => 'product',
            'woocommerce_id' => 50,
            'shopify_id' => 'gid://shopify/Product/800',
            'status' => 'success',
        ]);

        $writes = 0;

        Http::fake(function ($request) use (&$writes) {
            if (! str_contains($request->url(), 'graphql.json')) {
                return Http::response([
                    ['id' => 1, 'rating' => 5, 'reviewer' => 'A', 'review' => 'Good'],
                    ['id' => 2, 'rating' => 4, 'reviewer' => 'B', 'review' => 'Fine'],
                ], 200);
            }

            $writes++;

            return Http::response([
                'data' => ['metafieldsSet' => ['metafields' => [['id' => 'gid://shopify/Metafield/1']], 'userErrors' => []]],
            ], 200);
        });

        $migrator = new ReviewMigrator();

        // Both reviews belong to product 50. The first write already covered the
        // COMPLETE set, so the second must not send Shopify the same metafield
        // again — on a well-reviewed catalogue that is a wasted call per review.
        $first = $migrator->migrateItem($migration, ['id' => 1, 'product_id' => 50]);
        $second = $migrator->migrateItem($migration, ['id' => 2, 'product_id' => 50]);

        $this->assertSame('success', $first->status);
        $this->assertSame('success', $second->status);
        $this->assertSame(1, $writes, 'the product-level guard must collapse the repeat write');
    }

    /** @test */
    public function a_review_whose_product_never_migrated_is_skipped_not_failed(): void
    {
        Http::fake();

        $result = (new ReviewMigrator())->migrateItem($this->migration(), [
            'id' => 42,
            'product_id' => 999,
        ]);

        // SKIPPED is the honest status here. Reviews are unmetered while products
        // stop at the plan cap, so on a free plan every review of product 11
        // upwards reaches this branch — as normal operation, not as a fault.
        // Recording them as failures turned an ordinary run into
        // "completed_with_errors" behind hundreds of red rows the merchant could
        // do nothing about, and hid the failures that were real.
        $this->assertSame('skipped', $result->status);
        $this->assertNull($result->error);

        // It still has to say WHY, or the record is a silent hole.
        $this->assertStringContainsString('#999', $result->warning);
        $this->assertStringContainsString('upgrade', $result->warning);
    }

    /** @test */
    public function selecting_reviews_pulls_products_in_with_them(): void
    {
        // Deliberately NOT $this->migration(): that leaves a run in flight, and
        // the one-run-per-shop guard would answer 409 before we got here.
        $shop = Shop::create([
            'shopify_domain' => 'implied.myshopify.com',
            'access_token' => 'shpat_x',
            'token_expires_at' => now()->addHour(),
        ]);

        WoocommerceConnection::create([
            'shop_id' => $shop->id,
            'store_url' => 'https://woo.example.com',
            'consumer_key' => 'ck',
            'consumer_secret' => 'cs',
            'status' => 'connected',
        ]);

        $response = $this->withHeaders(['X-Shop-Domain' => $shop->shopify_domain])
            ->postJson('/api/migrations', ['entities' => ['review']]);

        // Reviews without products would fail EVERY record with "the product was
        // not migrated" — a run guaranteed to fail is not worth starting.
        $response->assertCreated();
        $this->assertContains('product', $response->json('selected_entities'));
    }
}
