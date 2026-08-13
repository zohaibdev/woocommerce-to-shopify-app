<?php

namespace Tests\Feature;

use App\Mail\ContactMessage;
use App\Mail\SupportTicketReplied;
use App\Models\AdminUser;
use App\Models\Shop;
use App\Models\SupportTicket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MerchantReplyAndContactTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'shopify_domain' => 'reply.myshopify.com',
            'access_token' => 'shpat_test',
            'name' => 'Reply Store',
            'email' => 'owner@example.com',
            'token_expires_at' => now()->addHour(),
        ]);

        AdminUser::create([
            'name' => 'Root',
            'email' => 'admin@storeshift.app',
            'password' => 'secret-password',
            'is_super' => true,
        ]);
    }

    private function ticket(array $overrides = []): SupportTicket
    {
        return SupportTicket::create(array_merge([
            'shop_id' => $this->shop->id,
            'subject' => 'Help me',
            'message' => 'Something is wrong',
            'contact_email' => 'merchant@example.com',
            'status' => 'pending',
            'priority' => 'normal',
        ], $overrides));
    }

    // ---------------------------------------------------------------------
    // Merchant can reply — support used to be one-way
    // ---------------------------------------------------------------------

    public function test_a_merchant_can_reply_to_their_own_ticket(): void
    {
        Mail::fake();

        $ticket = $this->ticket();

        $this->withHeader('X-Shop-Domain', $this->shop->shopify_domain)
            ->postJson("/api/support/tickets/{$ticket->id}/reply", ['message' => 'Here is more detail.'])
            ->assertCreated()
            ->assertJson(['author_type' => 'merchant', 'message' => 'Here is more detail.']);

        $this->assertDatabaseHas('ticket_replies', [
            'support_ticket_id' => $ticket->id,
            'author_type' => 'merchant',
        ]);

        // Reopened so it resurfaces for the admins, who are told about it.
        $this->assertSame('open', $ticket->fresh()->status);
        Mail::assertSent(SupportTicketReplied::class, fn ($m) => $m->hasTo('admin@storeshift.app') && $m->forAdmin);
    }

    public function test_a_merchant_cannot_reply_to_another_shops_ticket(): void
    {
        Mail::fake();

        $other = Shop::create([
            'shopify_domain' => 'intruder.myshopify.com',
            'access_token' => 'shpat_other',
            'token_expires_at' => now()->addHour(),
        ]);

        $ticket = $this->ticket();

        $this->withHeader('X-Shop-Domain', $other->shopify_domain)
            ->postJson("/api/support/tickets/{$ticket->id}/reply", ['message' => 'let me in'])
            ->assertNotFound();

        Mail::assertNothingSent();
    }

    public function test_a_closed_ticket_cannot_be_replied_to(): void
    {
        $ticket = $this->ticket(['status' => 'closed']);

        $this->withHeader('X-Shop-Domain', $this->shop->shopify_domain)
            ->postJson("/api/support/tickets/{$ticket->id}/reply", ['message' => 'hello?'])
            ->assertStatus(422);
    }

    public function test_the_reply_shows_up_in_the_merchants_ticket_list(): void
    {
        Mail::fake();

        $ticket = $this->ticket();

        $this->withHeader('X-Shop-Domain', $this->shop->shopify_domain)
            ->postJson("/api/support/tickets/{$ticket->id}/reply", ['message' => 'More detail.'])
            ->assertCreated();

        $replies = $this->withHeader('X-Shop-Domain', $this->shop->shopify_domain)
            ->getJson('/api/support/tickets')
            ->assertOk()
            ->json('data.0.replies');

        $this->assertCount(1, $replies);
        $this->assertSame('merchant', $replies[0]['author_type']);
    }

    // ---------------------------------------------------------------------
    // Public contact form
    // ---------------------------------------------------------------------

    public function test_the_public_contact_form_emails_the_admins(): void
    {
        Mail::fake();

        $this->postJson('/api/contact', [
            'name' => 'Jane',
            'email' => 'jane@example.com',
            'shop' => 'jane.myshopify.com',
            'subject' => 'Pre-sales question',
            'message' => 'Does it migrate subscriptions?',
        ])->assertOk();

        Mail::assertSent(ContactMessage::class, fn ($m) => $m->hasTo('admin@storeshift.app'));
    }

    public function test_the_contact_form_validates_its_input(): void
    {
        Mail::fake();

        $this->postJson('/api/contact', ['name' => 'Jane'])->assertStatus(422);
        $this->postJson('/api/contact', [
            'name' => 'Jane', 'email' => 'not-an-email', 'subject' => 'x', 'message' => 'y',
        ])->assertStatus(422);

        Mail::assertNothingSent();
    }

    public function test_the_honeypot_rejects_bots(): void
    {
        Mail::fake();

        // A real person never sees this field, so anything in it is a bot.
        $this->postJson('/api/contact', [
            'name' => 'Bot',
            'email' => 'bot@example.com',
            'subject' => 'Cheap watches',
            'message' => 'spam',
            'website' => 'http://spam.example',
        ])->assertStatus(422);

        Mail::assertNothingSent();
    }

    public function test_contact_does_not_require_authentication(): void
    {
        Mail::fake();

        // No shop header, no admin token — the marketing site has neither.
        $this->postJson('/api/contact', [
            'name' => 'Jane',
            'email' => 'jane@example.com',
            'subject' => 'Hello',
            'message' => 'Just asking.',
        ])->assertOk();
    }
}
