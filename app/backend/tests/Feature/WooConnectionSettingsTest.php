<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\WoocommerceConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The connection form, which a merchant only ever visits when something is
 * already wrong — so it is the last screen that should punish a mistake.
 *
 * Each of these was a way to get stuck on it: a typo in the URL that could only
 * be fixed by generating new API keys, a failed save that destroyed the working
 * credentials it replaced, a store URL rejected for missing "https://", and an
 * error message that said "Request failed (422)" instead of what WooCommerce
 * actually answered.
 */
class WooConnectionSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.env' => 'testing']);

        $this->shop = Shop::create([
            'shopify_domain' => 'settings.myshopify.com',
            'access_token' => 'shpat_x',
            'token_expires_at' => now()->addHour(),
        ]);
    }

    private function connect(array $payload)
    {
        return $this->withHeaders(['X-Shop-Domain' => $this->shop->shopify_domain])
            ->postJson('/api/woocommerce/connect', $payload);
    }

    private function existingConnection(): WoocommerceConnection
    {
        return WoocommerceConnection::create([
            'shop_id' => $this->shop->id,
            'store_url' => 'https://old.example.com',
            'consumer_key' => 'ck_working',
            'consumer_secret' => 'cs_working',
            'status' => 'connected',
        ]);
    }

    private function fakeStore(bool $ok): void
    {
        Http::fake([
            '*' => $ok
                ? Http::response(['environment' => ['version' => '8.5.1', 'wp_version' => '6.4']], 200)
                : Http::response(['message' => 'Sorry, you cannot list resources.'], 401),
        ]);
    }

    public function test_the_store_url_can_be_corrected_without_the_api_keys(): void
    {
        $this->existingConnection();
        $this->fakeStore(true);

        // The form can never show the secret it holds, so demanding all three
        // fields meant fixing a typo in the URL cost a trip to WordPress to
        // generate a whole new key pair.
        $this->connect(['store_url' => 'https://new.example.com'])->assertOk();

        $connection = $this->shop->fresh()->woocommerceConnection;

        $this->assertSame('https://new.example.com', $connection->store_url);
        $this->assertSame('ck_working', $connection->consumer_key);
        $this->assertSame('cs_working', $connection->consumer_secret);
    }

    public function test_a_failed_save_does_not_destroy_the_working_credentials(): void
    {
        $this->existingConnection();
        $this->fakeStore(false);

        $response = $this->connect([
            'store_url' => 'https://typo.example.com',
            'consumer_key' => 'ck_wrong',
            'consumer_secret' => 'cs_wrong',
        ]);

        $response->assertStatus(422);
        // The old code wrote first and tested afterwards, so one typo left the
        // merchant with no working key, no way to see the old one, and no route
        // back except issuing fresh credentials in WordPress.
        $response->assertJson(['kept_existing' => true]);

        $connection = $this->shop->fresh()->woocommerceConnection;

        $this->assertSame('https://old.example.com', $connection->store_url);
        $this->assertSame('ck_working', $connection->consumer_key);
        $this->assertSame('connected', $connection->status);
    }

    public function test_a_store_url_without_a_scheme_is_accepted(): void
    {
        $this->fakeStore(true);

        // "mystore.com" is not a mistake — it is the store. Bouncing it back with
        // "must be a valid URL" made the form read as broken.
        $this->connect([
            'store_url' => 'mystore.com',
            'consumer_key' => 'ck_1',
            'consumer_secret' => 'cs_1',
        ])->assertOk();

        $this->assertSame(
            'https://mystore.com',
            $this->shop->fresh()->woocommerceConnection->store_url,
        );
    }

    public function test_a_first_connection_still_requires_both_credentials(): void
    {
        Http::fake();

        // Optional only means "keep what is stored". With nothing stored there is
        // nothing to keep, and saving a URL alone would create a connection that
        // could never authenticate.
        $this->connect(['store_url' => 'https://first.example.com'])->assertStatus(422);

        $this->assertNull($this->shop->fresh()->woocommerceConnection);
    }

    public function test_the_reason_the_store_refused_is_returned_to_the_client(): void
    {
        $this->fakeStore(false);

        $response = $this->connect([
            'store_url' => 'https://bad.example.com',
            'consumer_key' => 'ck_1',
            'consumer_secret' => 'cs_1',
        ]);

        $response->assertStatus(422);

        // "HTTP 401 ... check the API key" is the single most useful sentence this
        // app produces. The client reads `error`, so it has to be there.
        $this->assertStringContainsString('401', $response->json('error'));
    }

    public function test_no_stored_connection_is_reported_as_literal_null(): void
    {
        $response = $this->withHeaders(['X-Shop-Domain' => $this->shop->shopify_domain])
            ->getJson('/api/woocommerce/connection');

        $response->assertOk();

        // `{}` is NOT an acceptable answer here, and it is what
        // `response()->json(null)` sends — Symfony's JsonResponse swaps a null
        // payload for an empty ArrayObject. An empty object is truthy, so the
        // client took it for a real connection, read `store_url` off it, got
        // undefined and crashed the page. That locked out precisely the merchants
        // with no connection yet: the only ones who need this form.
        $this->assertSame('null', $response->getContent());
        // Decoded here rather than through `$response->json()`, which asserts the
        // payload is an array and so cannot express "this endpoint answered null".
        $this->assertNull(json_decode($response->getContent(), true));
    }
}
