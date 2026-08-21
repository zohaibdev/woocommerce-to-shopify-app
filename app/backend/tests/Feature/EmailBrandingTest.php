<?php

namespace Tests\Feature;

use App\Mail\MigrationCompleted;
use App\Models\Shop;
use App\Models\StoreMigration;
use App\Models\WoocommerceConnection;
use App\Services\Branding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The logo an admin uploads has to reach the merchant's inbox.
 *
 * Everything about this feature LOOKED right from the admin panel — the upload
 * succeeded, the settings page showed the new image, and the public logo route
 * served it — so "the logo does not update" was reported as a caching problem.
 * It is asserted here on the RENDERED EMAIL HTML instead, because that is the
 * only surface that matters and the only one nobody was checking.
 */
class EmailBrandingTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): StoreMigration
    {
        $shop = Shop::create([
            'shopify_domain' => 'branding.myshopify.com',
            'access_token' => 'shpat_x',
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
            'status' => 'completed',
            'selected_entities' => ['product'],
        ]);

        return $migration->load('entities');
    }

    public function test_the_uploaded_logo_reaches_the_rendered_email(): void
    {
        Storage::fake('local');
        config(['services.shopify.app_url' => 'https://shopshift.net/app']);

        Branding::storeLogo(UploadedFile::fake()->image('logo.png'));

        $html = (new MigrationCompleted($this->migration()))->render();

        // An absolute URL, because an email is read outside the app and a
        // relative src resolves against the mail client, not against us.
        $this->assertStringContainsString('https://shopshift.net/app/api/branding/logo', $html);
        $this->assertStringContainsString('<img', $html);
    }

    public function test_replacing_the_logo_changes_the_url_so_clients_cannot_serve_the_old_one(): void
    {
        Storage::fake('local');
        config(['services.shopify.app_url' => 'https://shopshift.net/app']);

        Branding::storeLogo(UploadedFile::fake()->image('old.png'));
        $first = Branding::current()['logo_url'];

        // Gmail and Outlook proxy and cache images by URL, and the logo route
        // sends a day of Cache-Control. Without the version bust the merchant
        // uploads a new logo and keeps receiving mail showing the old one — which
        // is exactly what "the logo does not update" looked like.
        $this->travel(2)->seconds();
        Branding::storeLogo(UploadedFile::fake()->image('new.png'));

        $this->assertNotSame($first, Branding::current()['logo_url']);
        $this->assertStringContainsString('?v=', Branding::current()['logo_url']);
    }

    public function test_the_name_is_used_when_no_logo_has_been_uploaded(): void
    {
        config(['services.shopify.app_url' => 'https://shopshift.net/app']);

        $html = (new MigrationCompleted($this->migration()))->render();

        $this->assertStringContainsString('Shop Shift', $html);
        $this->assertStringNotContainsString('Store Shift', $html);
    }

    public function test_an_uploaded_logo_survives_the_settings_cache(): void
    {
        Storage::fake('local');
        config(['services.shopify.app_url' => 'https://shopshift.net/app']);

        // Warm the forever-cache with the "no logo" answer first, the way any
        // email sent before the upload would have.
        $this->assertFalse(Branding::current()['has_logo']);

        Branding::storeLogo(UploadedFile::fake()->image('logo.png'));

        $this->assertTrue(Branding::current()['has_logo']);
    }
}
