<?php

namespace Tests\Feature;

use App\Migration\Migrators\CategoryMigrator;
use App\Migration\Migrators\CustomerMigrator;
use App\Migration\Migrators\OrderMigrator;
use App\Migration\Migrators\ProductMigrator;
use App\Models\Shop;
use App\Models\StoreMigration;
use App\Models\WoocommerceConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The fields a merchant notices are MISSING only after the migration is over.
 *
 * Every one of these was silently absent: SEO copy the store ranks on, the URL
 * slug every inbound link points at, the barcode the warehouse scans, the
 * shipping address of anyone who does not ship to their billing address, and the
 * tax and discounts that make a historical order's total the number the customer
 * actually paid.
 */
class FieldCoverageTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): StoreMigration
    {
        $shop = Shop::create([
            'shopify_domain' => 'coverage.myshopify.com',
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
            'selected_entities' => ['product'],
            'options' => ['image_size' => 'full'],
        ]);

        $migration->setRelation('shop', $shop);
        $migration->setRelation('connection', $conn);

        return $migration;
    }

    /**
     * Capture the input of whichever mutation is called, answering everything
     * with a success so the migrator runs to completion.
     */
    private function captureShopify(?array &$captured, string $field, string $objectKey = 'product'): void
    {
        Http::fake(function ($request) use (&$captured, $field, $objectKey) {
            $body = json_decode($request->body(), true);
            $query = (string) data_get($body, 'query');

            if (str_contains($query, 'locations')) {
                return Http::response(['data' => ['locations' => ['nodes' => [['id' => 'gid://shopify/Location/1']]]]], 200);
            }

            if (str_contains($query, $field)) {
                $captured = data_get($body, 'variables.input') ?? data_get($body, 'variables.order');

                return Http::response([
                    'data' => [$field => [$objectKey => ['id' => "gid://shopify/X/1"], 'userErrors' => []]],
                ], 200);
            }

            return Http::response(['data' => []], 200);
        });
    }

    // ------------------------------------------------------------------ product

    /** @test */
    public function a_products_url_slug_and_seo_copy_survive(): void
    {
        $captured = null;
        $this->captureShopify($captured, 'productSet');

        (new ProductMigrator())->migrateItem($this->migration(), [
            'id' => 1,
            'name' => 'Blue Cotton Shirt',
            'slug' => 'blue-cotton-shirt',
            'type' => 'simple',
            'status' => 'publish',
            'meta_data' => [
                ['key' => '_yoast_wpseo_title', 'value' => 'Blue Cotton Shirt — Free Delivery'],
                ['key' => '_yoast_wpseo_metadesc', 'value' => 'Soft combed cotton, ships in 24h.'],
            ],
        ]);

        // Without the handle, Shopify derives one from the TITLE and every
        // inbound link to the old URL breaks with nothing to redirect.
        $this->assertSame('blue-cotton-shirt', $captured['handle']);
        $this->assertSame('Blue Cotton Shirt — Free Delivery', $captured['seo']['title']);
        $this->assertSame('Soft combed cotton, ships in 24h.', $captured['seo']['description']);
    }

    /** @test */
    public function an_unexpanded_seo_template_is_not_shipped_as_copy(): void
    {
        $captured = null;
        $this->captureShopify($captured, 'productSet');

        (new ProductMigrator())->migrateItem($this->migration(), [
            'id' => 2,
            'name' => 'Thing',
            'slug' => 'thing',
            'type' => 'simple',
            'status' => 'publish',
            // Yoast stores the TEMPLATE when the merchant never overrode it.
            'meta_data' => [['key' => '_yoast_wpseo_title', 'value' => '%%title%% %%sep%% %%sitename%%']],
        ]);

        // Better to leave it out and let Shopify generate something sensible than
        // to publish "%%title%% %%sep%% %%sitename%%" as the page title.
        $this->assertArrayNotHasKey('seo', $captured);
    }

    /** @test */
    public function a_upc_reaches_shopify_as_the_variant_barcode(): void
    {
        $captured = null;
        $this->captureShopify($captured, 'productSet');

        (new ProductMigrator())->migrateItem($this->migration(), [
            'id' => 3,
            'name' => 'Scanned Item',
            'slug' => 'scanned-item',
            'type' => 'simple',
            'status' => 'publish',
            'sku' => 'SKU-1',
            'meta_data' => [
                ['key' => '_edit_lock', 'value' => '1699999999:1'],
                ['key' => '_gtin', 'value' => '5012345678900'],
            ],
        ]);

        $this->assertSame('5012345678900', $captured['variants'][0]['inventoryItem']['barcode']);
    }

    /** @test */
    public function a_meta_value_that_is_not_a_barcode_is_ignored(): void
    {
        $captured = null;
        $this->captureShopify($captured, 'productSet');

        (new ProductMigrator())->migrateItem($this->migration(), [
            'id' => 4,
            'name' => 'Thing',
            'slug' => 'thing',
            'type' => 'simple',
            'status' => 'publish',
            // A plugin reusing the key for something else must not put junk on
            // the variant, where Shopify shows it to staff as a real barcode.
            'meta_data' => [['key' => '_upc', 'value' => 'not-a-barcode']],
        ]);

        $this->assertArrayNotHasKey('barcode', $captured['variants'][0]['inventoryItem'] ?? []);
    }

    /** @test */
    public function a_percent_encoded_slug_does_not_become_a_hex_handle(): void
    {
        $captured = null;
        $this->captureShopify($captured, 'productSet');

        (new ProductMigrator())->migrateItem($this->migration(), [
            'id' => 5,
            'name' => 'المتجر',
            // WordPress percent-encodes non-Latin slugs. Stripping that byte by
            // byte produced "d8-a7-d9-84-d9-85" — which Shopify accepts happily
            // and then serves as the product's permanent URL forever.
            'slug' => '%d8%a7%d9%84%d9%85%d8%aa%d8%ac%d8%b1',
            'type' => 'simple',
            'status' => 'publish',
        ]);

        $this->assertArrayNotHasKey('handle', $captured, 'better no handle than a hex one');
    }

    /** @test */
    public function a_mixed_slug_keeps_the_part_shopify_can_use(): void
    {
        $captured = null;
        $this->captureShopify($captured, 'productSet');

        (new ProductMigrator())->migrateItem($this->migration(), [
            'id' => 6,
            'name' => 'Café Noir',
            'slug' => 'caf%c3%a9-noir',
            'type' => 'simple',
            'status' => 'publish',
        ]);

        $this->assertSame('caf-noir', $captured['handle']);
    }

    // ----------------------------------------------------------------- category

    /** @test */
    public function a_categorys_url_slug_survives(): void
    {
        $captured = null;
        $this->captureShopify($captured, 'collectionCreate', 'collection');

        (new CategoryMigrator())->migrateItem($this->migration(), [
            'id' => 10,
            'name' => 'Running Shoes',
            'slug' => 'running-shoes',
        ]);

        $this->assertSame('running-shoes', $captured['handle']);
    }

    // ----------------------------------------------------------------- customer

    /** @test */
    public function a_customer_keeps_both_addresses_with_names_and_company(): void
    {
        $captured = null;
        $this->captureShopify($captured, 'customerCreate', 'customer');

        (new CustomerMigrator())->migrateItem($this->migration(), [
            'id' => 20,
            'email' => 'buyer@example.com',
            'first_name' => 'Ayesha',
            'last_name' => 'Khan',
            'billing' => [
                'first_name' => 'Ayesha', 'last_name' => 'Khan', 'company' => 'Khan Ltd',
                'address_1' => '1 Mall Road', 'city' => 'Lahore', 'country' => 'PK', 'postcode' => '54000',
            ],
            'shipping' => [
                'first_name' => 'Bilal', 'last_name' => 'Ahmed', 'company' => 'Warehouse Co',
                'address_1' => '9 Industrial Estate', 'city' => 'Karachi', 'country' => 'PK', 'postcode' => '74000',
            ],
        ]);

        // Only billing used to migrate, and even that arrived nameless — Shopify
        // prints the name and company ON the label, so an address without them
        // has to be retyped before it can be shipped to.
        $this->assertCount(2, $captured['addresses']);
        $this->assertSame('Khan Ltd', $captured['addresses'][0]['company']);
        $this->assertSame('Ayesha', $captured['addresses'][0]['firstName']);
        $this->assertSame('9 Industrial Estate', $captured['addresses'][1]['address1']);
        $this->assertSame('Warehouse Co', $captured['addresses'][1]['company']);
    }

    /** @test */
    public function a_shipping_address_identical_to_billing_is_not_duplicated(): void
    {
        $captured = null;
        $this->captureShopify($captured, 'customerCreate', 'customer');

        $address = [
            'first_name' => 'Sam', 'last_name' => 'Lee',
            'address_1' => '4 High Street', 'city' => 'Leeds', 'country' => 'GB', 'postcode' => 'LS1 1AA',
        ];

        (new CustomerMigrator())->migrateItem($this->migration(), [
            'id' => 21,
            'email' => 'sam@example.com',
            'billing' => $address,
            'shipping' => $address,
        ]);

        $this->assertCount(1, $captured['addresses'], 'the common case must not give everyone the same address twice');
    }

    /** @test */
    public function a_billing_phone_does_not_defeat_the_address_de_duplication(): void
    {
        $captured = null;
        $this->captureShopify($captured, 'customerCreate', 'customer');

        (new CustomerMigrator())->migrateItem($this->migration(), [
            'id' => 22,
            'email' => 'phone@example.com',
            // WooCommerce's billing block has a phone and its shipping block does
            // not — so comparing whole records found a difference on virtually
            // every customer and the de-duplication never fired.
            'billing' => [
                'first_name' => 'Zara', 'address_1' => '7 Park Lane',
                'city' => 'Dublin', 'country' => 'IE', 'postcode' => 'D02',
                'phone' => '+353 1 234 5678',
            ],
            'shipping' => [
                'first_name' => 'Zara', 'address_1' => '7 Park Lane',
                'city' => 'Dublin', 'country' => 'IE', 'postcode' => 'D02',
            ],
        ]);

        $this->assertCount(1, $captured['addresses']);
        // The one kept is billing, so the phone survives with it.
        $this->assertArrayHasKey('phone', $captured['addresses'][0]);
    }

    // -------------------------------------------------------------------- order

    /** @test */
    public function an_order_carries_its_tax_and_discount(): void
    {
        $captured = null;
        $this->captureShopify($captured, 'orderCreate', 'order');

        (new OrderMigrator())->migrateItem($this->migration(), [
            'id' => 30,
            'number' => '1030',
            'currency' => 'GBP',
            'status' => 'completed',
            'total' => '108.00',
            'billing' => ['email' => 'buyer@example.com'],
            'line_items' => [
                ['name' => 'Shirt', 'quantity' => 2, 'price' => 50, 'total' => '100.00', 'total_tax' => '20.00'],
            ],
            'coupon_lines' => [
                ['code' => 'SAVE10', 'discount' => '10.00'],
                ['code' => 'EXTRA2', 'discount' => '2.00'],
            ],
        ]);

        // Tax missing meant every historical order under-reported revenue by the
        // VAT rate, and no discount meant the order totalled MORE than the
        // customer actually paid.
        $this->assertSame('20', $captured['lineItems'][0]['taxLines'][0]['priceSet']['shopMoney']['amount']);
        $this->assertSame(0.2, $captured['lineItems'][0]['taxLines'][0]['rate']);

        // Shopify takes one code; the largest is the honest single representation.
        $this->assertSame('SAVE10', $captured['discountCode']['itemFixedDiscountCode']['code']);
        $this->assertStringContainsString('EXTRA2', $captured['note']);
    }

    /** @test */
    public function a_discounted_line_is_not_discounted_twice(): void
    {
        $captured = null;
        $this->captureShopify($captured, 'orderCreate', 'order');

        // A real Woo order with a 10.00 coupon on a 100.00 line: `subtotal` keeps
        // the pre-discount figure, while `total` AND `price` are both already
        // reduced. The old code read `price`, so Shopify was handed the discounted
        // unit price and then told to take the coupon off again — every order with
        // a coupon totalled less than the customer actually paid.
        (new OrderMigrator())->migrateItem($this->migration(), [
            'id' => 31,
            'currency' => 'GBP',
            'status' => 'completed',
            'billing' => ['email' => 'buyer@example.com'],
            'line_items' => [[
                'name' => 'Shirt',
                'quantity' => 2,
                'subtotal' => '100.00',
                'total' => '90.00',
                'price' => 45,
            ]],
            'coupon_lines' => [['code' => 'SAVE10', 'discount' => '10.00']],
        ]);

        // 100.00 / 2 — the pre-discount unit price. Shopify subtracts the 10.00
        // discount code itself, landing on the 90.00 that was really charged.
        $this->assertSame('50', $captured['lineItems'][0]['priceSet']['shopMoney']['amount']);
        $this->assertSame('SAVE10', $captured['discountCode']['itemFixedDiscountCode']['code']);
    }

    /** @test */
    public function a_line_without_a_subtotal_still_prices_before_discount(): void
    {
        $captured = null;
        $this->captureShopify($captured, 'orderCreate', 'order');

        // An old order, or a line written by a plugin, may carry no `subtotal`.
        // It must fall back to `total` — never to `price`, which would reinstate
        // the double discount for exactly the records nobody checks.
        (new OrderMigrator())->migrateItem($this->migration(), [
            'id' => 32,
            'currency' => 'USD',
            'status' => 'completed',
            'billing' => ['email' => 'buyer@example.com'],
            'line_items' => [['name' => 'Mug', 'quantity' => 4, 'total' => '80.00', 'price' => 20]],
        ]);

        $this->assertSame('20', $captured['lineItems'][0]['priceSet']['shopMoney']['amount']);
    }

    /** @test */
    public function a_rejected_handle_costs_the_url_not_the_product(): void
    {
        $attempts = [];

        Http::fake(function ($request) use (&$attempts) {
            $body = json_decode($request->body(), true);
            $query = (string) data_get($body, 'query');

            if (str_contains($query, 'locations')) {
                return Http::response(['data' => ['locations' => ['nodes' => [['id' => 'gid://shopify/Location/1']]]]], 200);
            }

            if (! str_contains($query, 'productSet')) {
                return Http::response(['data' => []], 200);
            }

            $input = data_get($body, 'variables.input');
            $attempts[] = $input;

            // Shopify prefixes the path with the MUTATION ARGUMENT here, so the
            // rejection arrives as ["input","handle"] rather than ["handle"].
            // Reading only the first segment meant "input" shadowed the real
            // field, nothing could be shed, and the whole product was lost over a
            // URL collision — the most common rejection there is on a store that
            // already has products.
            if (array_key_exists('handle', $input)) {
                return Http::response(['data' => ['productSet' => [
                    'product' => null,
                    'userErrors' => [['field' => ['input', 'handle'], 'message' => 'Handle has already been taken']],
                ]]], 200);
            }

            return Http::response(['data' => ['productSet' => [
                'product' => ['id' => 'gid://shopify/Product/9'],
                'userErrors' => [],
            ]]], 200);
        });

        $result = (new ProductMigrator())->migrateItem($this->migration(), [
            'id' => 77,
            'name' => 'Blue Shirt',
            'slug' => 'blue-shirt',
            'type' => 'simple',
            'regular_price' => '10.00',
        ]);

        $this->assertSame('success', $result->status, 'the product must survive a taken handle');
        $this->assertCount(2, $attempts, 'it should retry once without the handle');
        $this->assertArrayNotHasKey('handle', $attempts[1]);
        $this->assertStringContainsString('Handle', $result->warning);
    }

    /** @test */
    public function a_line_with_tax_but_no_net_total_sends_no_tax_line(): void
    {
        $captured = null;
        $this->captureShopify($captured, 'orderCreate', 'order');

        (new OrderMigrator())->migrateItem($this->migration(), [
            'id' => 32,
            'currency' => 'USD',
            'status' => 'completed',
            'billing' => ['email' => 'buyer@example.com'],
            // A fully discounted line that still carried tax: there is no net to
            // derive a rate from. `lineItems` is not sheddable, so a tax line
            // Shopify rejects would fail the WHOLE order — losing this one number
            // is much the cheaper mistake.
            'line_items' => [
                ['name' => 'Freebie', 'quantity' => 1, 'price' => 0, 'total' => '0.00', 'total_tax' => '2.00'],
            ],
        ]);

        $this->assertArrayNotHasKey('taxLines', $captured['lineItems'][0]);
    }

    /** @test */
    public function a_custom_order_status_is_written_down_before_it_collapses(): void
    {
        $captured = null;
        $this->captureShopify($captured, 'orderCreate', 'order');

        (new OrderMigrator())->migrateItem($this->migration(), [
            'id' => 31,
            'currency' => 'USD',
            // From an order-management plugin. Shopify has no equivalent, so it
            // becomes PENDING — and the merchant would have no way to tell.
            'status' => 'awaiting-shipment',
            'billing' => ['email' => 'buyer@example.com'],
            'line_items' => [['name' => 'Thing', 'quantity' => 1, 'price' => 5, 'total' => '5.00']],
        ]);

        $this->assertStringContainsString('awaiting-shipment', $captured['note']);
    }
}
