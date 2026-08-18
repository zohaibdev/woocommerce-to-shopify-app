<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Shop;
use App\Models\StoreMigration;
use App\Models\Subscription;
use App\Models\WoocommerceConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Reviews are written as a metafield ON a product, so a migration of reviews
 * without products cannot do anything: every record is recorded "the product was
 * not migrated" and the merchant has spent a run to be told so.
 *
 * The wizard disables the combination, but the wizard is a page that can be
 * stale and an API that can be called directly — so the rule has to hold here
 * too. It is asserted through the HTTP endpoint rather than against a helper for
 * that reason.
 */
class ReviewDependencyTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'shopify_domain' => 'reviews.myshopify.com',
            'access_token' => 'shpat_x',
            'token_expires_at' => now()->addHour(),
            'installed_at' => now(),
        ]);

        WoocommerceConnection::create([
            'shop_id' => $this->shop->id,
            'store_url' => 'https://woo.example.com',
            'consumer_key' => 'ck',
            'consumer_secret' => 'cs',
            'status' => 'connected',
        ]);
    }

    private function subscribeTo(array $limits): void
    {
        $plan = Plan::create([
            'code' => 'test-plan',
            'name' => 'Test',
            'price' => 19,
            'interval' => 'EVERY_30_DAYS',
            'limits' => $limits,
            'active' => true,
        ]);

        Subscription::create([
            'shop_id' => $this->shop->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now(),
        ]);
    }

    private function start(array $entities)
    {
        return $this->withHeaders(['X-Shop-Domain' => $this->shop->shopify_domain])
            ->postJson('/api/migrations', ['entities' => $entities]);
    }

    public function test_selecting_reviews_brings_products_with_them(): void
    {
        Queue::fake();
        $this->subscribeTo(['products' => null, 'reviews' => null]);

        $this->start(['review'])->assertCreated();

        // The dependency is satisfied by ADDING products, not by refusing — a
        // merchant who ticks reviews means "migrate my reviews", and the summary
        // shows them what will actually run.
        $this->assertContains(
            'product',
            StoreMigration::latest('id')->first()->selected_entities,
        );
    }

    public function test_reviews_are_refused_when_the_plan_has_no_products_to_attach_them_to(): void
    {
        Queue::fake();

        // A plan that sells reviews but not products. The implication above is
        // deliberately skipped for a plan-locked dependency (so the merchant is
        // never refused over an entity they cannot see), which used to leave this
        // run to start and migrate precisely nothing.
        $this->subscribeTo(['products' => 0, 'reviews' => null]);

        $response = $this->start(['review']);

        $response->assertStatus(422);
        $this->assertStringContainsString('Products', (string) $response->json('message'));

        Queue::assertNothingPushed();
        $this->assertSame(0, StoreMigration::count());
    }
}
