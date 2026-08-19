<?php

namespace Tests\Feature;

use App\Models\MigrationEntity;
use App\Models\Shop;
use App\Models\StoreMigration;
use App\Models\WoocommerceConnection;
use App\Services\Exceptions\WooCommerceUnavailableException;
use App\Services\WooCommerceClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * "Your WooCommerce store could not be read ... check that the API key and secret
 * are still valid" — shown to a merchant whose migration was running at the time.
 *
 * Two separate mistakes produced that, and both are asserted here:
 *
 *  1. 401 and 403 shared one sentence. They are different failures. 401 means
 *     WooCommerce looked at the key and rejected it. 403 means the request was
 *     REFUSED — on a WooCommerce store overwhelmingly by a security plugin, a
 *     WAF or a rate limiter that answers before WordPress ever sees it. A
 *     migration hammering the REST API is exactly the traffic that trips those.
 *
 *  2. The FIRST core entity that threw set `source_error` for the whole store,
 *     so four entities counting perfectly were reported as a store that could
 *     not be read at all.
 *
 * The running migration is the part that makes this unambiguous: the same
 * credentials were demonstrably working while the app called them into question.
 */
class SourceReadabilityTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'shopify_domain' => 'readable.myshopify.com',
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

    private function preview()
    {
        return $this->withHeaders(['X-Shop-Domain' => $this->shop->shopify_domain])
            ->getJson('/api/woocommerce/preview');
    }

    /** Everything counts fine except orders, which one 403 refuses. */
    private function fakeOrdersBlocked(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/orders')) {
                return Http::response('Access denied by security plugin', 403);
            }

            return Http::response([], 200, ['X-WP-Total' => '25']);
        });
    }

    // ------------------------------------------------------------- the message

    public function test_a_403_does_not_blame_the_api_credentials(): void
    {
        Http::fake(['*' => Http::response('blocked', 403)]);

        $connection = $this->shop->woocommerceConnection;

        try {
            (new WooCommerceClient($connection))->total('orders');
            $this->fail('a refused count must throw');
        } catch (WooCommerceUnavailableException $e) {
            $this->assertSame(403, $e->status);
            // The old sentence sent merchants off to reissue working keys.
            $this->assertStringNotContainsString('key and secret are still valid', $e->getMessage());
            $this->assertStringContainsString('403', $e->getMessage());
            // It has to name what was refused and what usually does the refusing.
            $this->assertStringContainsString('orders', $e->getMessage());
            $this->assertStringContainsString('security plugin', $e->getMessage());
        }
    }

    public function test_a_401_still_says_the_credentials_are_the_problem(): void
    {
        // The genuine auth failure must not be softened away by the fix above.
        Http::fake(['*' => Http::response('unauthorized', 401)]);

        try {
            (new WooCommerceClient($this->shop->woocommerceConnection))->total('products');
            $this->fail('a rejected key must throw');
        } catch (WooCommerceUnavailableException $e) {
            $this->assertSame(401, $e->status);
            $this->assertStringContainsString('rejected the API key', $e->getMessage());
            $this->assertStringContainsString('products', $e->getMessage());
        }
    }

    // ------------------------------------------------------- connection status

    public function test_one_refused_endpoint_is_not_a_store_that_cannot_be_read(): void
    {
        $this->fakeOrdersBlocked();

        $response = $this->preview()->assertOk();

        // The headline claim is the one that was wrong.
        $this->assertNull($response->json('source_error'));

        // The refusal is still reported — just as itself, not as a connection
        // failure. Naming the endpoint is the whole point.
        $blocked = $response->json('blocked_entities');
        $this->assertCount(1, $blocked);
        $this->assertSame('order', $blocked[0]['type']);
        $this->assertSame(403, $blocked[0]['status']);
    }

    public function test_a_store_that_answers_nothing_is_still_reported_as_unreadable(): void
    {
        // The genuine case must survive: every core entity refused means the
        // store really cannot be read, and hiding that would be the opposite bug.
        Http::fake(['*' => Http::response('unauthorized', 401)]);

        $response = $this->preview()->assertOk();

        $this->assertNotNull($response->json('source_error'));
        $this->assertStringContainsString('rejected the API key', $response->json('source_error'));
    }

    public function test_a_migration_that_is_actually_migrating_is_never_contradicted(): void
    {
        // The reported case: a merchant watching records land in Shopify while
        // the app told them their store could not be read. Records already
        // migrated are live proof the credentials work and the store answers.
        $migration = $this->migrationWithProgress(processed: 1_400);

        Http::fake(['*' => Http::response('unauthorized', 401)]);

        $response = $this->preview()->assertOk();

        $this->assertNull($response->json('source_error'));
        $this->assertSame($migration->id, $response->json('active_migration_id'));
    }

    public function test_a_stalled_run_with_no_progress_does_not_suppress_the_reason(): void
    {
        // The same guard pointing the other way, and the more dangerous
        // direction. A run whose API keys expired underneath it sits at
        // "processing" having migrated nothing — that is precisely when the
        // merchant needs the explanation, so status alone must never be treated
        // as proof the store is readable.
        $this->migrationWithProgress(processed: 0);

        Http::fake(['*' => Http::response('unauthorized', 401)]);

        $response = $this->preview()->assertOk();

        $this->assertNotNull($response->json('source_error'));
        $this->assertStringContainsString('rejected the API key', $response->json('source_error'));
    }

    private function migrationWithProgress(int $processed): StoreMigration
    {
        $migration = StoreMigration::create([
            'shop_id' => $this->shop->id,
            'woocommerce_connection_id' => $this->shop->woocommerceConnection->id,
            'status' => 'processing',
            'selected_entities' => ['product'],
        ]);

        MigrationEntity::create([
            'migration_id' => $migration->id,
            'entity_type' => 'product',
            'status' => 'processing',
            'total_items' => 2_612,
            'processed_items' => $processed,
            'successful_items' => $processed,
        ]);

        return $migration;
    }

    public function test_a_refused_count_never_marks_the_connection_disconnected(): void
    {
        $this->fakeOrdersBlocked();

        $this->preview()->assertOk();

        // Counting is a READ. Only an explicit connection test may change the
        // stored status, or a background failure would log the merchant out of
        // their own integration.
        $this->assertSame('connected', $this->shop->woocommerceConnection->fresh()->status);

        $this->withHeaders(['X-Shop-Domain' => $this->shop->shopify_domain])
            ->getJson('/api/woocommerce/connection')
            ->assertOk()
            ->assertJsonPath('status', 'connected');
    }
}
