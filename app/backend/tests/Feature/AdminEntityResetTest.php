<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\MigrationItem;
use App\Models\Shop;
use App\Models\StoreMigration;
use App\Models\WoocommerceConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The support tool for "this merchant's products are wrong, let them run it
 * again".
 *
 * migration_items is unique per (shop, entity, source id), which is what stops a
 * re-run duplicating anything — and it is also what makes a genuine do-over
 * impossible without a deliberate reset. Before this existed the honest answer
 * to a merchant whose catalogue had been re-imported on the WooCommerce side was
 * "we cannot".
 *
 * The mode is the whole design. `resync` leaves a record that a re-run will
 * ADOPT what is already in Shopify; `forget` leaves one it will CREATE again.
 * Getting that backwards gives a merchant two of everything, so these tests
 * assert on the exact row state each mode leaves behind, not just on row counts.
 */
class AdminEntityResetTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): array
    {
        $token = str_repeat('b', 64);

        AdminUser::create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => bcrypt('secret'),
            'api_token_hash' => hash('sha256', $token),
        ]);

        return ['Authorization' => "Bearer {$token}"];
    }

    private function shopWithItems(string $status = 'completed'): Shop
    {
        $shop = Shop::create([
            'shopify_domain' => 'reset.myshopify.com',
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
            'status' => $status,
            'selected_entities' => ['product', 'customer'],
        ]);

        foreach ([1, 2, 3] as $n) {
            MigrationItem::create([
                'migration_id' => $migration->id,
                'shop_id' => $shop->id,
                'entity_type' => 'product',
                'woocommerce_id' => $n,
                'shopify_id' => "gid://shopify/Product/{$n}",
                'status' => 'success',
                'attempts' => 1,
            ]);
        }

        MigrationItem::create([
            'migration_id' => $migration->id,
            'shop_id' => $shop->id,
            'entity_type' => 'customer',
            'woocommerce_id' => 9,
            'shopify_id' => 'gid://shopify/Customer/9',
            'status' => 'success',
            'attempts' => 1,
        ]);

        return $shop;
    }

    /** @test */
    public function resync_keeps_the_rows_in_the_state_that_makes_a_rerun_adopt_rather_than_duplicate(): void
    {
        $shop = $this->shopWithItems();

        $this->withHeaders($this->admin())
            ->postJson("/api/admin/stores/{$shop->id}/reset-entity", ['entity_type' => 'product'])
            ->assertOk()
            ->assertJson(['mode' => 'resync', 'affected' => 3]);

        $rows = MigrationItem::where('shop_id', $shop->id)->where('entity_type', 'product')->get();

        $this->assertCount(3, $rows, 'resync must not delete anything');

        foreach ($rows as $row) {
            $this->assertSame('pending', $row->status);
            $this->assertNull($row->shopify_id);
            // The critical one. Zero means "never attempted", which sends a re-run
            // straight to creating a second copy; one means "we tried and never
            // learned the outcome", which is what findExisting() resolves.
            $this->assertSame(1, (int) $row->attempts);
        }
    }

    /** @test */
    public function forget_removes_the_rows_entirely(): void
    {
        $shop = $this->shopWithItems();

        $this->withHeaders($this->admin())
            ->postJson("/api/admin/stores/{$shop->id}/reset-entity", [
                'entity_type' => 'product',
                'mode' => 'forget',
            ])
            ->assertOk()
            ->assertJson(['mode' => 'forget', 'affected' => 3]);

        $this->assertSame(0, MigrationItem::where('shop_id', $shop->id)->where('entity_type', 'product')->count());
    }

    /** @test */
    public function forget_says_out_loud_that_it_will_duplicate(): void
    {
        $shop = $this->shopWithItems();

        $response = $this->withHeaders($this->admin())
            ->postJson("/api/admin/stores/{$shop->id}/reset-entity", [
                'entity_type' => 'product',
                'mode' => 'forget',
            ]);

        $this->assertStringContainsString('duplicates', (string) $response->json('message'));
    }

    /** @test */
    public function only_the_named_entity_is_touched(): void
    {
        $shop = $this->shopWithItems();

        $this->withHeaders($this->admin())
            ->postJson("/api/admin/stores/{$shop->id}/reset-entity", ['entity_type' => 'product'])
            ->assertOk();

        $customer = MigrationItem::where('shop_id', $shop->id)->where('entity_type', 'customer')->first();

        $this->assertSame('success', $customer->status);
        $this->assertSame('gid://shopify/Customer/9', $customer->shopify_id);
    }

    /** @test */
    public function another_shops_records_are_never_reached(): void
    {
        $mine = $this->shopWithItems();

        $theirs = Shop::create([
            'shopify_domain' => 'other.myshopify.com',
            'access_token' => 'shpat_y',
            'token_expires_at' => now()->addHour(),
        ]);

        $theirConn = WoocommerceConnection::create([
            'shop_id' => $theirs->id,
            'store_url' => 'https://other.example.com',
            'consumer_key' => 'ck',
            'consumer_secret' => 'cs',
            'status' => 'connected',
        ]);

        $theirRow = MigrationItem::create([
            'migration_id' => StoreMigration::create([
                'shop_id' => $theirs->id,
                'woocommerce_connection_id' => $theirConn->id,
                'status' => 'completed',
                'selected_entities' => ['product'],
            ])->id,
            'shop_id' => $theirs->id,
            'entity_type' => 'product',
            'woocommerce_id' => 1,
            'shopify_id' => 'gid://shopify/Product/999',
            'status' => 'success',
        ]);

        $this->withHeaders($this->admin())
            ->postJson("/api/admin/stores/{$mine->id}/reset-entity", ['entity_type' => 'product', 'mode' => 'forget'])
            ->assertOk();

        $this->assertModelExists($theirRow);
    }

    /** @test */
    public function a_reset_is_refused_while_a_migration_is_running(): void
    {
        // The run's counters are DERIVED from these rows, so resetting underneath
        // it makes its progress travel backwards and has it re-create what it
        // just made.
        $shop = $this->shopWithItems(status: 'processing');

        $this->withHeaders($this->admin())
            ->postJson("/api/admin/stores/{$shop->id}/reset-entity", ['entity_type' => 'product'])
            ->assertStatus(409);

        $this->assertSame(3, MigrationItem::where('shop_id', $shop->id)
            ->where('entity_type', 'product')->where('status', 'success')->count());
    }

    /** @test */
    public function an_unknown_entity_type_is_refused(): void
    {
        $shop = $this->shopWithItems();

        $this->withHeaders($this->admin())
            ->postJson("/api/admin/stores/{$shop->id}/reset-entity", ['entity_type' => 'sprockets'])
            ->assertStatus(422);
    }

    /** @test */
    public function the_store_detail_shows_what_there_is_to_reset(): void
    {
        // "Reset products" means nothing until you know whether that is 12 rows
        // or 20,000.
        $shop = $this->shopWithItems();

        $response = $this->withHeaders($this->admin())->getJson("/api/admin/stores/{$shop->id}");

        $response->assertOk();

        $products = collect($response->json('entities'))->firstWhere('type', 'product');

        $this->assertSame(3, $products['total']);
        $this->assertSame(3, $products['success']);
    }

    /** @test */
    public function the_reset_endpoint_requires_an_admin(): void
    {
        $shop = $this->shopWithItems();

        $this->postJson("/api/admin/stores/{$shop->id}/reset-entity", ['entity_type' => 'product'])
            ->assertStatus(401);
    }
}
