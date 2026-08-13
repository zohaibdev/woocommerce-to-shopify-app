<?php

namespace Tests\Feature;

use App\Jobs\RetryFailedItemsJob;
use App\Mail\SupportTicketOpened;
use App\Mail\SupportTicketReplied;
use App\Migration\MigratorRegistry;
use App\Models\AdminUser;
use App\Models\MigrationEntity;
use App\Models\MigrationItem;
use App\Models\Shop;
use App\Models\StoreMigration;
use App\Models\SupportTicket;
use App\Models\WoocommerceConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RetryAndSupportTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'shopify_domain' => 'retry.myshopify.com',
            'access_token' => 'shpat_test',
            'email' => 'owner@example.com',
            'token_expires_at' => now()->addHour(),
        ]);

        WoocommerceConnection::create([
            'shop_id' => $this->shop->id,
            'store_url' => 'https://woo.example.com',
            'consumer_key' => 'ck',
            'consumer_secret' => 'cs',
            'status' => 'connected',
        ]);
    }

    private function migrationWithFailures(array $failedIds): StoreMigration
    {
        $migration = StoreMigration::create([
            'shop_id' => $this->shop->id,
            'woocommerce_connection_id' => $this->shop->woocommerceConnection->id,
            'status' => 'completed_with_errors',
            'selected_entities' => ['category'],
            'completed_at' => now(),
        ]);

        MigrationEntity::create([
            'migration_id' => $migration->id,
            'entity_type' => 'category',
            'status' => 'completed_with_errors',
            'total_items' => count($failedIds),
        ]);

        foreach ($failedIds as $id) {
            MigrationItem::create([
                'migration_id' => $migration->id,
                'shop_id' => $this->shop->id,
                'entity_type' => 'category',
                'woocommerce_id' => $id,
                'status' => 'failed',
                'error' => 'Image upload failed',
            ]);
        }

        return $migration;
    }

    // ---------------------------------------------------------------------
    // Retry failed items
    // ---------------------------------------------------------------------

    public function test_retry_marks_the_migration_active_in_the_response(): void
    {
        Queue::fake();

        $migration = $this->migrationWithFailures([11, 12]);

        $response = $this->withHeader('X-Shop-Domain', $this->shop->shopify_domain)
            ->postJson("/api/migrations/{$migration->id}/retry-failed")
            ->assertOk();

        // The page reads this to decide whether to keep polling. A terminal status
        // here made the button look like it did nothing.
        $this->assertSame('processing', $response->json('status'));
        $this->assertSame('processing', $migration->fresh()->status);

        Queue::assertPushed(RetryFailedItemsJob::class);
    }

    public function test_retry_is_refused_when_there_is_nothing_to_retry(): void
    {
        Queue::fake();

        $migration = $this->migrationWithFailures([]);

        $this->withHeader('X-Shop-Domain', $this->shop->shopify_domain)
            ->postJson("/api/migrations/{$migration->id}/retry-failed")
            ->assertStatus(422);

        Queue::assertNothingPushed();
    }

    public function test_retry_fetches_only_the_failed_ids(): void
    {
        Queue::fake();

        Http::fake([
            '*/wp-json/wc/v3/products/categories*' => Http::response([
                ['id' => 11, 'name' => 'Eleven'],
                ['id' => 12, 'name' => 'Twelve'],
            ], 200),
            '*/graphql.json' => Http::response([
                'data' => ['collectionCreate' => ['collection' => ['id' => 'gid://shopify/Collection/1'], 'userErrors' => []]],
            ], 200),
        ]);

        $migration = $this->migrationWithFailures([11, 12]);

        (new RetryFailedItemsJob($migration->id))->handle(app(MigratorRegistry::class));

        // One targeted WooCommerce request for the two failures — not a walk of
        // every page of every entity, which is what a retry used to cost.
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'products/categories')
                && str_contains(urldecode($request->url()), 'include=11,12');
        });

        $this->assertSame(
            2,
            MigrationItem::where('migration_id', $migration->id)->where('status', 'success')->count(),
        );
    }

    public function test_a_source_record_that_no_longer_exists_is_skipped_not_retried_forever(): void
    {
        Queue::fake();

        // WooCommerce returns only one of the two — the other was deleted there.
        Http::fake([
            '*/wp-json/wc/v3/products/categories*' => Http::response([['id' => 11, 'name' => 'Eleven']], 200),
            '*/graphql.json' => Http::response([
                'data' => ['collectionCreate' => ['collection' => ['id' => 'gid://shopify/Collection/1'], 'userErrors' => []]],
            ], 200),
        ]);

        $migration = $this->migrationWithFailures([11, 12]);

        (new RetryFailedItemsJob($migration->id))->handle(app(MigratorRegistry::class));

        $gone = MigrationItem::where('migration_id', $migration->id)
            ->where('woocommerce_id', 12)->first();

        $this->assertSame('skipped', $gone->status);
        $this->assertStringContainsString('no longer exists', strtolower((string) $gone->error));
    }

    // ---------------------------------------------------------------------
    // Re-authorize
    // ---------------------------------------------------------------------

    public function test_reauthorize_url_forces_the_consent_flow(): void
    {
        $url = $this->withHeader('X-Shop-Domain', $this->shop->shopify_domain)
            ->getJson('/api/shop')
            ->assertOk()
            ->json('reauth_url');

        $this->assertStringContainsString('force=1', $url);
    }

    public function test_forced_auth_reaches_shopify_instead_of_bouncing_into_the_app(): void
    {
        config(['services.shopify.api_key' => 'k', 'services.shopify.api_secret' => 's']);

        // A perfectly healthy install: without force this short-circuits back to
        // /app/, which is exactly what made the button look broken.
        $this->shop->update(['scopes' => implode(',', \App\Services\ShopifyOAuth::requiredScopes())]);

        $response = $this->get("/api/auth?force=1&shop={$this->shop->shopify_domain}");

        $response->assertRedirect();
        $this->assertStringContainsString(
            'myshopify.com/admin/oauth/authorize',
            $response->headers->get('Location'),
        );
    }

    // ---------------------------------------------------------------------
    // Support ticket email — both ways
    // ---------------------------------------------------------------------

    public function test_opening_a_ticket_emails_the_admin_and_the_merchant(): void
    {
        Mail::fake();

        AdminUser::create([
            'name' => 'Root',
            'email' => 'admin@storeshift.app',
            'password' => 'secret-password',
            'is_super' => true,
        ]);

        $this->withHeader('X-Shop-Domain', $this->shop->shopify_domain)
            ->postJson('/api/support/tickets', [
                'subject' => 'Products not importing',
                'message' => 'Nothing happens when I start a migration.',
                'contact_email' => 'merchant@example.com',
            ])
            ->assertCreated();

        Mail::assertSent(SupportTicketOpened::class, fn ($m) => $m->hasTo('admin@storeshift.app') && $m->forAdmin);
        Mail::assertSent(SupportTicketOpened::class, fn ($m) => $m->hasTo('merchant@example.com') && ! $m->forAdmin);
    }

    public function test_an_admin_reply_emails_the_merchant(): void
    {
        Mail::fake();

        $admin = AdminUser::create([
            'name' => 'Root',
            'email' => 'admin@storeshift.app',
            'password' => 'secret-password',
            'is_super' => true,
        ]);

        $ticket = SupportTicket::create([
            'shop_id' => $this->shop->id,
            'subject' => 'Help',
            'message' => 'Please help',
            'contact_email' => 'merchant@example.com',
            'status' => 'open',
            'priority' => 'normal',
        ]);

        $this->withHeader('Authorization', 'Bearer '.$admin->issueToken())
            ->postJson("/api/admin/tickets/{$ticket->id}/reply", ['message' => 'Try re-running it.'])
            ->assertOk();

        Mail::assertSent(SupportTicketReplied::class, fn ($m) => $m->hasTo('merchant@example.com'));
    }

    public function test_a_broken_mail_server_does_not_lose_the_ticket(): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => '127.0.0.1',
            'mail.mailers.smtp.port' => 1,
            'mail.mailers.smtp.timeout' => 1,
        ]);
        Mail::forgetMailers();

        $this->withHeader('X-Shop-Domain', $this->shop->shopify_domain)
            ->postJson('/api/support/tickets', [
                'subject' => 'Still saved',
                'message' => 'Even with no working SMTP.',
                'contact_email' => 'merchant@example.com',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('support_tickets', ['subject' => 'Still saved']);
    }
}
