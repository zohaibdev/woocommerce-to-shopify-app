<?php

namespace Tests\Feature;

use App\Migration\Migrators\ProductMigrator;
use App\Models\Plan;
use App\Models\Shop;
use App\Models\StoreMigration;
use App\Models\Subscription;
use App\Models\WoocommerceConnection;
use App\Support\PlanAllowance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The features a plan sells, as opposed to the record volume it sells.
 *
 * Metafields is the cautionary one. Every plan has carried a `metafields` flag
 * since billing was built, the pricing page has always drawn "Metafields &
 * brands" as a paid line, and Shop::planAllows() was written to answer the
 * question — but nothing ever called it. The free plan shipped every merchant's
 * full custom meta_data and brand data for two releases, and no test noticed,
 * because every test asserted what the migrator SENT rather than what the plan
 * permitted.
 */
class PlanFeatureGatingTest extends TestCase
{
    use RefreshDatabase;

    private function shopOn(array $limits): Shop
    {
        $shop = Shop::create([
            'shopify_domain' => 'gating.myshopify.com',
            'access_token' => 'shpat_x',
            'token_expires_at' => now()->addHour(),
        ]);

        $plan = Plan::create([
            'code' => 'test-plan',
            'name' => 'Test',
            'price' => 0,
            'interval' => 'monthly',
            'limits' => $limits,
            'active' => true,
        ]);

        Subscription::create([
            'shop_id' => $shop->id,
            'plan_id' => $plan->id,
            'status' => 'active',
        ]);

        $shop->forgetPlan();

        return $shop;
    }

    private function migrationFor(Shop $shop): StoreMigration
    {
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
            'selected_entities' => ['product'],
        ]);

        $migration->setRelation('shop', $shop);
        $migration->setRelation('connection', $conn);

        return $migration;
    }

    private function captureProduct(?array &$captured): void
    {
        Http::fake(function ($request) use (&$captured) {
            $body = json_decode($request->body(), true);
            $query = (string) data_get($body, 'query');

            if (str_contains($query, 'locations')) {
                return Http::response(['data' => ['locations' => ['nodes' => [['id' => 'gid://shopify/Location/1']]]]], 200);
            }

            if (str_contains($query, 'productSet')) {
                $captured = data_get($body, 'variables.input');

                return Http::response(['data' => ['productSet' => [
                    'product' => ['id' => 'gid://shopify/Product/1'],
                    'userErrors' => [],
                ]]], 200);
            }

            return Http::response(['data' => []], 200);
        });
    }

    /** A product carrying custom meta_data and a brand. */
    private function wooProduct(): array
    {
        return [
            'id' => 5,
            'name' => 'Blue Shirt',
            'type' => 'simple',
            'regular_price' => '10.00',
            'status' => 'publish',
            'brands' => [['name' => 'Acme']],
            'meta_data' => [['key' => 'fabric', 'value' => 'cotton']],
        ];
    }

    // ------------------------------------------------------------- metafields

    public function test_a_plan_without_metafields_does_not_send_them(): void
    {
        $shop = $this->shopOn(['products' => null, 'metafields' => false]);
        $captured = null;
        $this->captureProduct($captured);

        (new ProductMigrator())->migrateItem($this->migrationFor($shop), $this->wooProduct());

        $this->assertEmpty(
            $captured['metafields'] ?? [],
            'the free plan was shipping every merchant custom field it was never sold',
        );

        // Brands ride along with metafields on the pricing page ("Metafields &
        // brands"), so the brand TAG has to go the same way — otherwise the paid
        // feature is still half given away.
        $this->assertNotContains('Acme', $captured['tags'] ?? []);
    }

    public function test_a_plan_with_metafields_still_sends_them(): void
    {
        $shop = $this->shopOn(['products' => null, 'metafields' => true]);
        $captured = null;
        $this->captureProduct($captured);

        (new ProductMigrator())->migrateItem($this->migrationFor($shop), $this->wooProduct());

        $this->assertNotEmpty($captured['metafields'] ?? []);
        $this->assertContains('Acme', $captured['tags'] ?? []);
    }

    public function test_the_vendor_survives_even_without_metafields(): void
    {
        $shop = $this->shopOn(['products' => null, 'metafields' => false]);
        $captured = null;
        $this->captureProduct($captured);

        (new ProductMigrator())->migrateItem($this->migrationFor($shop), $this->wooProduct());

        // Vendor is a core Shopify field, not a paid extra. Stripping it with the
        // metafields would leave every product on the free plan with no vendor at
        // all, which is a worse product than the one they were promised.
        $this->assertSame('Acme', $captured['vendor'] ?? null);
    }

    // ---------------------------------------------------------- content plans

    public function test_content_entities_are_locked_by_their_own_plan_keys(): void
    {
        $shop = $this->shopOn([
            'products' => null,
            'coupons' => 0,
            'blogs' => 0,
            'reviews' => 0,
        ]);

        foreach (['coupon', 'blog', 'article', 'review'] as $type) {
            $verdict = PlanAllowance::forEntity($shop, $type, 25);

            $this->assertTrue($verdict['locked'], "{$type} must be locked when its plan key is 0");
            $this->assertSame(0, $verdict['migratable']);
        }
    }

    public function test_blog_posts_follow_the_same_key_as_blogs(): void
    {
        $shop = $this->shopOn(['products' => null, 'blogs' => 5]);

        // A plan that sold blogs but not their posts would deliver empty
        // containers, so both entities read the one key.
        $this->assertSame(5, $shop->limitFor('blog'));
        $this->assertSame(5, $shop->limitFor('article'));
    }

    public function test_a_plan_that_never_set_the_content_keys_leaves_them_unlimited(): void
    {
        // Absent must NOT mean locked: every plan created before these keys
        // existed would otherwise silently lose features its merchants are
        // already paying for.
        $shop = $this->shopOn(['products' => 100, 'metafields' => true]);

        foreach (['coupon', 'blog', 'article', 'review'] as $type) {
            $this->assertNull($shop->limitFor($type), "{$type} must stay unlimited on an older plan");
        }
    }

    public function test_locked_content_entities_are_refused_by_the_api(): void
    {
        $shop = $this->shopOn(['products' => null, 'coupons' => 0]);

        WoocommerceConnection::create([
            'shop_id' => $shop->id,
            'store_url' => 'https://woo.example.com',
            'consumer_key' => 'ck',
            'consumer_secret' => 'cs',
            'status' => 'connected',
        ]);

        $this->withHeaders(['X-Shop-Domain' => $shop->shopify_domain])
            ->postJson('/api/migrations', ['entities' => ['coupon']])
            ->assertStatus(422);
    }
}
