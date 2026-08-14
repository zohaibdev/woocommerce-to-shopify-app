<?php

namespace Tests\Feature;

use App\Migration\Migrators\OrderMigrator;
use App\Models\Shop;
use App\Models\StoreMigration;
use App\Models\WoocommerceConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Shopify treats a line item's `sku` as a product reference. When it matches no
 * existing variant it silently creates a draft product named
 * "Import placeholder for {sku}".
 *
 * Importing historical orders that way buried a real catalogue under hundreds of
 * junk drafts — the merchant's Products list was nothing but placeholders. Order
 * line items are custom lines, so the SKU must never be sent as a reference.
 */
class OrderLineItemTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): StoreMigration
    {
        $shop = Shop::create([
            'shopify_domain' => 'orders.myshopify.com',
            'access_token' => 'shpat_test',
            'token_expires_at' => now()->addHour(),
        ]);

        $connection = WoocommerceConnection::create([
            'shop_id' => $shop->id,
            'store_url' => 'https://woo.example.com',
            'consumer_key' => 'ck',
            'consumer_secret' => 'cs',
            'status' => 'connected',
        ]);

        $migration = StoreMigration::create([
            'shop_id' => $shop->id,
            'woocommerce_connection_id' => $connection->id,
            'status' => 'processing',
            'selected_entities' => ['order'],
        ]);

        $migration->setRelation('shop', $shop);
        $migration->setRelation('connection', $connection);

        return $migration;
    }

    private function fakeOrderCreate(): void
    {
        Http::fake([
            '*/graphql.json' => Http::response([
                'data' => ['orderCreate' => ['order' => ['id' => 'gid://shopify/Order/1'], 'userErrors' => []]],
            ], 200),
        ]);
    }

    private function wooOrder(array $lineItem): array
    {
        return [
            'id' => 66018,
            'currency' => 'USD',
            'status' => 'completed',
            'date_created' => '2024-01-01T10:00:00',
            'billing' => ['email' => 'buyer@example.com'],
            'line_items' => [$lineItem + ['quantity' => 1, 'price' => '10.00']],
        ];
    }

    private function sentLineItems(): array
    {
        $items = [];

        Http::assertSent(function ($request) use (&$items) {
            $sent = data_get(json_decode($request->body(), true), 'variables.order.lineItems');
            if ($sent) {
                $items = $sent;
            }

            return true;
        });

        return $items;
    }

    public function test_the_sku_is_never_sent_as_a_line_item_reference(): void
    {
        $this->fakeOrderCreate();

        $result = (new OrderMigrator())->migrateItem(
            $this->migration(),
            $this->wooOrder(['name' => 'Blue Widget', 'sku' => '1001277']),
        );

        $this->assertSame('success', $result->status);

        foreach ($this->sentLineItems() as $line) {
            $this->assertArrayNotHasKey(
                'sku',
                $line,
                'sending a SKU makes Shopify create an "Import placeholder" draft product',
            );
        }
    }

    public function test_the_sku_is_preserved_in_the_line_item_title(): void
    {
        $this->fakeOrderCreate();

        (new OrderMigrator())->migrateItem(
            $this->migration(),
            $this->wooOrder(['name' => 'Blue Widget', 'sku' => '1001277']),
        );

        $this->assertSame('Blue Widget (SKU: 1001277)', $this->sentLineItems()[0]['title']);
    }

    public function test_a_line_item_without_a_sku_keeps_its_plain_title(): void
    {
        $this->fakeOrderCreate();

        (new OrderMigrator())->migrateItem(
            $this->migration(),
            $this->wooOrder(['name' => 'Blue Widget']),
        );

        $this->assertSame('Blue Widget', $this->sentLineItems()[0]['title']);
    }

    public function test_a_sku_already_in_the_name_is_not_repeated(): void
    {
        $this->fakeOrderCreate();

        (new OrderMigrator())->migrateItem(
            $this->migration(),
            $this->wooOrder(['name' => 'Widget 1001277', 'sku' => '1001277']),
        );

        $this->assertSame('Widget 1001277', $this->sentLineItems()[0]['title']);
    }

    public function test_a_nameless_line_item_still_has_a_title(): void
    {
        $this->fakeOrderCreate();

        (new OrderMigrator())->migrateItem(
            $this->migration(),
            $this->wooOrder(['sku' => '1001277']),
        );

        $this->assertSame('Item (SKU: 1001277)', $this->sentLineItems()[0]['title']);
    }
}
