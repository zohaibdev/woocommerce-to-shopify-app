<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\AppSetting;
use App\Services\MailSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * SMTP is configured from the admin panel rather than .env, which the super admin
 * cannot reach on shared hosting. The credential must be encrypted at rest
 * (CLAUDE.md rule 6) and must never come back out to the client.
 */
class MailSettingsTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        $admin = AdminUser::create([
            'name' => 'Root',
            'email' => 'root@example.com',
            'password' => 'secret-password',
            'is_super' => true,
        ]);

        $this->token = $admin->issueToken();
    }

    private function saveSettings(array $data)
    {
        return $this->withHeader('Authorization', "Bearer {$this->token}")
            ->putJson('/api/admin/settings/mail', $data);
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'host' => 'smtp.hostinger.com',
            'port' => 587,
            'username' => 'support@webnday.com',
            'password' => 'super-secret',
            'scheme' => 'smtp',
            'from_address' => 'support@webnday.com',
            'from_name' => 'Shop Shift',
        ], $overrides);
    }

    public function test_settings_require_admin_auth(): void
    {
        $this->getJson('/api/admin/settings/mail')->assertStatus(401);
        $this->putJson('/api/admin/settings/mail', $this->validPayload())->assertStatus(401);
    }

    public function test_saving_settings_never_returns_the_password(): void
    {
        $response = $this->saveSettings($this->validPayload())->assertOk();

        $response->assertJsonMissing(['password' => 'super-secret']);
        $this->assertArrayNotHasKey('password', $response->json());
        $this->assertTrue($response->json('has_password'));
        $this->assertTrue($response->json('configured'));
    }

    public function test_the_password_is_encrypted_at_rest(): void
    {
        $this->saveSettings($this->validPayload())->assertOk();

        // Read the column raw — going through the model would decrypt it for us.
        $raw = (string) DB::table('app_settings')->where('key', 'mail')->value('value');

        $this->assertStringNotContainsString('super-secret', $raw);
        $this->assertStringNotContainsString('smtp.hostinger.com', $raw, 'the whole blob is encrypted');
        $this->assertSame('super-secret', AppSetting::read('mail')['password']);
    }

    public function test_a_blank_password_keeps_the_stored_one(): void
    {
        $this->saveSettings($this->validPayload())->assertOk();

        // The admin edits the host only; the form sends a blank password.
        $this->saveSettings($this->validPayload(['host' => 'smtp.gmail.com', 'password' => '']))->assertOk();

        $stored = AppSetting::read('mail');
        $this->assertSame('smtp.gmail.com', $stored['host']);
        $this->assertSame('super-secret', $stored['password'], 'editing the host must not wipe the credential');
    }

    public function test_stored_settings_override_the_env_config(): void
    {
        config([
            'mail.default' => 'log',
            'mail.mailers.smtp.host' => '127.0.0.1',
            'mail.from.address' => 'env@example.com',
        ]);

        $this->saveSettings($this->validPayload())->assertOk();

        MailSettings::apply();

        $this->assertSame('smtp', config('mail.default'));
        $this->assertSame('smtp.hostinger.com', config('mail.mailers.smtp.host'));
        $this->assertSame('super-secret', config('mail.mailers.smtp.password'));
        $this->assertSame('support@webnday.com', config('mail.from.address'));
    }

    public function test_apply_is_a_no_op_when_nothing_is_stored(): void
    {
        config(['mail.default' => 'log', 'mail.mailers.smtp.host' => '127.0.0.1']);

        MailSettings::apply();

        $this->assertSame('log', config('mail.default'), 'an unconfigured install keeps its .env behaviour');
        $this->assertSame('127.0.0.1', config('mail.mailers.smtp.host'));
    }

    public function test_validation_rejects_a_bad_smtp_config(): void
    {
        $this->saveSettings($this->validPayload(['host' => '']))->assertStatus(422);
        $this->saveSettings($this->validPayload(['port' => 70000]))->assertStatus(422);
        $this->saveSettings($this->validPayload(['scheme' => 'carrier-pigeon']))->assertStatus(422);
        $this->saveSettings($this->validPayload(['from_address' => 'not-an-email']))->assertStatus(422);
    }

    public function test_the_test_message_needs_settings_first(): void
    {
        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/admin/settings/mail/test', ['to' => 'me@example.com'])
            ->assertStatus(422);
    }

    public function test_a_test_message_is_sent_once_configured(): void
    {
        $this->saveSettings($this->validPayload())->assertOk();

        // Capture the message instead of dialling a real SMTP server. Mail::fake()
        // is no use here — MailFake::raw() is a no-op and records nothing.
        config(['mail.default' => 'array']);
        Mail::forgetMailers();

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/admin/settings/mail/test', ['to' => 'me@example.com'])
            ->assertOk()
            ->assertJson(['sent_to' => 'me@example.com']);

        $messages = Mail::mailer('array')->getSymfonyTransport()->messages();

        $this->assertCount(1, $messages);
        $this->assertSame(
            'Shop Shift SMTP test',
            $messages[0]->getOriginalMessage()->getSubject(),
        );
    }
}
