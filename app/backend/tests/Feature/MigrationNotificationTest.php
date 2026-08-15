<?php

namespace Tests\Feature;

use App\Jobs\FinalizeMigrationJob;
use App\Mail\MigrationCompleted;
use App\Models\MigrationEntity;
use App\Models\MigrationItem;
use App\Models\MigrationLog;
use App\Models\Shop;
use App\Models\StoreMigration;
use App\Models\WoocommerceConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * A migration can run for hours. The merchant asks to be emailed when it lands so
 * they don't have to watch the page — and a mail problem must never undo a
 * migration that actually finished.
 */
class MigrationNotificationTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'shopify_domain' => 'notify.myshopify.com',
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

    private function finishedMigration(array $options = [], int $failed = 0): StoreMigration
    {
        $migration = StoreMigration::create([
            'shop_id' => $this->shop->id,
            'woocommerce_connection_id' => $this->shop->woocommerceConnection->id,
            'status' => 'processing',
            'selected_entities' => ['product'],
            'options' => $options,
            // Planning is a phase of its own now (see PlanBatchesJob), and
            // nothing may finalize a run while it is outstanding. A fixture that
            // hands out ready-made chunks is a run whose planning is done.
            'planned_at' => now(),
        ]);

        MigrationEntity::create([
            'migration_id' => $migration->id,
            'entity_type' => 'product',
            'status' => 'completed',
            'total_items' => 10,
        ]);

        // Counters are derived from the mapping table, so the rows have to exist.
        foreach (range(1, 10) as $i) {
            MigrationItem::create([
                'migration_id' => $migration->id,
                'shop_id' => $this->shop->id,
                'entity_type' => 'product',
                'woocommerce_id' => $i,
                'shopify_id' => $i > $failed ? "gid://shopify/Product/{$i}" : null,
                'status' => $i > $failed ? 'success' : 'failed',
                'error' => $i > $failed ? null : 'nope',
            ]);
        }

        return $migration;
    }

    public function test_it_emails_the_merchant_when_the_migration_finishes(): void
    {
        Mail::fake();

        $migration = $this->finishedMigration(['notify_email' => 'owner@example.com']);

        (new FinalizeMigrationJob($migration->id))->handle();

        Mail::assertSent(MigrationCompleted::class, fn ($mail) => $mail->hasTo('owner@example.com'));
    }

    public function test_it_sends_nothing_when_the_merchant_did_not_ask(): void
    {
        Mail::fake();

        (new FinalizeMigrationJob($this->finishedMigration()->id))->handle();

        Mail::assertNothingSent();
    }

    public function test_the_subject_reflects_the_outcome(): void
    {
        Mail::fake();

        $migration = $this->finishedMigration(['notify_email' => 'owner@example.com'], failed: 3);

        (new FinalizeMigrationJob($migration->id))->handle();

        $this->assertSame('completed_with_errors', $migration->fresh()->status);

        Mail::assertSent(MigrationCompleted::class, function (MigrationCompleted $mail) {
            return str_contains($mail->envelope()->subject, 'finished with some errors');
        });
    }

    public function test_a_broken_mail_server_does_not_undo_a_finished_migration(): void
    {
        // No Mail::fake() — the real mailer runs against an unreachable SMTP host.
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => '127.0.0.1',
            'mail.mailers.smtp.port' => 1,
            'mail.mailers.smtp.timeout' => 1,
        ]);
        Mail::forgetMailers();

        $migration = $this->finishedMigration(['notify_email' => 'owner@example.com']);

        (new FinalizeMigrationJob($migration->id))->handle();

        // The migration is still closed out correctly...
        $this->assertSame('completed', $migration->fresh()->status);
        $this->assertNotNull($migration->fresh()->completed_at);

        // ...and the merchant is told the email didn't go out.
        $this->assertTrue(
            MigrationLog::where('migration_id', $migration->id)
                ->where('level', 'warning')
                ->where('message', 'like', '%Could not send the completion email%')
                ->exists(),
        );
    }

    public function test_the_wizard_can_request_a_notification(): void
    {
        Queue::fake();

        $this->withHeader('X-Shop-Domain', $this->shop->shopify_domain)
            ->postJson('/api/migrations', [
                'entities' => ['category'],
                'notify_email' => 'me@example.com',
            ])
            ->assertCreated();

        $this->assertSame('me@example.com', StoreMigration::first()->options['notify_email']);
    }

    public function test_a_malformed_notification_address_is_rejected(): void
    {
        Queue::fake();

        $this->withHeader('X-Shop-Domain', $this->shop->shopify_domain)
            ->postJson('/api/migrations', [
                'entities' => ['category'],
                'notify_email' => 'not-an-email',
            ])
            ->assertStatus(422);
    }
}
