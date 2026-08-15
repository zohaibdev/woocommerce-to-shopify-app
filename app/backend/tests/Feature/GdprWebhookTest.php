<?php

namespace Tests\Feature;

use App\Models\MigrationItem;
use App\Models\Shop;
use App\Models\StoreMigration;
use App\Models\WoocommerceConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The three privacy webhooks Shopify requires of every app.
 *
 * `customers/redact` was a stub for the life of the app: it logged a line and
 * answered ok while deleting nothing. That is a compliance failure rather than a
 * missing feature — the app holds a mapping row per migrated customer and order,
 * and a row for a record that FAILED also keeps Shopify's rejection message,
 * which for a customer routinely quotes the email or address it objected to.
 *
 * These tests exist because the endpoint answering `{"ok": true}` looks
 * identical whether it erased something or nothing at all, so nothing short of
 * asserting on the rows can tell the difference.
 */
class GdprWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'shpss_test_secret';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.shopify.api_secret' => self::SECRET]);
    }

    private function shop(string $domain): Shop
    {
        return Shop::create([
            'shopify_domain' => $domain,
            'access_token' => 'shpat_x',
            'token_expires_at' => now()->addHour(),
        ]);
    }

    private function migration(Shop $shop): StoreMigration
    {
        $conn = WoocommerceConnection::create([
            'shop_id' => $shop->id,
            'store_url' => 'https://woo.example.com',
            'consumer_key' => 'ck',
            'consumer_secret' => 'cs',
            'status' => 'connected',
        ]);

        return StoreMigration::create([
            'shop_id' => $shop->id,
            'woocommerce_connection_id' => $conn->id,
            'status' => 'completed',
            'selected_entities' => ['customer', 'order'],
        ]);
    }

    private function mapping(StoreMigration $migration, string $entityType, int $wooId, string $shopifyId): MigrationItem
    {
        return MigrationItem::create([
            'migration_id' => $migration->id,
            'shop_id' => $migration->shop_id,
            'entity_type' => $entityType,
            'woocommerce_id' => $wooId,
            'shopify_id' => $shopifyId,
            'status' => 'success',
        ]);
    }

    /** Shopify signs the RAW body, so the test has to post exactly what it signed. */
    private function send(string $route, array $payload, string $domain, bool $validHmac = true)
    {
        $body = json_encode($payload);

        return $this->call(
            'POST',
            "/api/webhooks/{$route}",
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_SHOPIFY_SHOP_DOMAIN' => $domain,
                'HTTP_X_SHOPIFY_HMAC_SHA256' => $validHmac
                    ? base64_encode(hash_hmac('sha256', $body, self::SECRET, true))
                    : 'not-the-right-signature',
            ],
            $body,
        );
    }

    /** @test */
    public function customers_redact_actually_deletes_the_subjects_mapping_rows(): void
    {
        $shop = $this->shop('redact.myshopify.com');
        $migration = $this->migration($shop);

        $subject = $this->mapping($migration, 'customer', 10, 'gid://shopify/Customer/191167');
        $order = $this->mapping($migration, 'order', 20, 'gid://shopify/Order/299938');
        $bystander = $this->mapping($migration, 'customer', 11, 'gid://shopify/Customer/555555');

        $this->send('customers-redact', [
            'shop_domain' => $shop->shopify_domain,
            'customer' => ['id' => 191167, 'email' => 'john@example.com'],
            'orders_to_redact' => [299938],
        ], $shop->shopify_domain)->assertOk();

        $this->assertModelMissing($subject);
        $this->assertModelMissing($order);
        $this->assertModelExists($bystander);
    }

    /** @test */
    public function a_customer_id_is_matched_whole_and_never_by_suffix(): void
    {
        // gid 4191167 ends with the digits of 191167. Matching on the tail alone
        // would erase a completely unrelated shopper — the one mistake in this
        // endpoint that is worse than doing nothing.
        $shop = $this->shop('suffix.myshopify.com');
        $migration = $this->migration($shop);

        $other = $this->mapping($migration, 'customer', 10, 'gid://shopify/Customer/4191167');

        $this->send('customers-redact', [
            'customer' => ['id' => 191167],
        ], $shop->shopify_domain)->assertOk();

        $this->assertModelExists($other);
    }

    /** @test */
    public function one_shops_redaction_cannot_reach_another_shops_rows(): void
    {
        $mine = $this->shop('mine.myshopify.com');
        $theirs = $this->shop('theirs.myshopify.com');

        $theirRow = $this->mapping($this->migration($theirs), 'customer', 10, 'gid://shopify/Customer/191167');

        $this->send('customers-redact', [
            'customer' => ['id' => 191167],
        ], $mine->shopify_domain)->assertOk();

        $this->assertModelExists($theirRow);
    }

    /** @test */
    public function an_unsigned_redaction_is_refused(): void
    {
        $shop = $this->shop('unsigned.myshopify.com');
        $row = $this->mapping($this->migration($shop), 'customer', 10, 'gid://shopify/Customer/191167');

        $this->send('customers-redact', [
            'customer' => ['id' => 191167],
        ], $shop->shopify_domain, validHmac: false)->assertStatus(401);

        $this->assertModelExists($row);
    }

    /** @test */
    public function a_redaction_for_a_shop_we_do_not_have_is_still_a_success(): void
    {
        // Answering anything else makes Shopify retry a webhook whose outcome
        // cannot change.
        $this->send('customers-redact', [
            'customer' => ['id' => 191167],
        ], 'never-installed.myshopify.com')->assertOk();
    }

    /** @test */
    public function shop_redact_purges_everything_belonging_to_the_shop(): void
    {
        $shop = $this->shop('gone.myshopify.com');
        $row = $this->mapping($this->migration($shop), 'customer', 10, 'gid://shopify/Customer/1');

        $this->send('shop-redact', ['shop_domain' => $shop->shopify_domain], $shop->shopify_domain)->assertOk();

        $this->assertModelMissing($shop);
        $this->assertModelMissing($row);
    }

    /** @test */
    public function a_data_request_is_acknowledged(): void
    {
        $shop = $this->shop('request.myshopify.com');

        $this->send('customers-data-request', [
            'customer' => ['id' => 191167],
        ], $shop->shopify_domain)->assertOk();
    }
}
