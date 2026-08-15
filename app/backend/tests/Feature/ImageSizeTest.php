<?php

namespace Tests\Feature;

use App\Migration\ImageSizeResolver;
use App\Models\MigrationLog;
use App\Models\Shop;
use App\Models\StoreMigration;
use App\Models\WoocommerceConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Shopify downloads every image itself and the product write waits for it, so
 * handing it a 1024px copy instead of an 8 MB camera original is the single
 * biggest lever a merchant has over how long a migration takes.
 *
 * The rule these all defend: losing image QUALITY is a trade the merchant opted
 * into. Losing the IMAGE is not — every failure path must land back on the
 * original URL.
 */
class ImageSizeTest extends TestCase
{
    use RefreshDatabase;

    private function migration(?string $imageSize): StoreMigration
    {
        $shop = Shop::create([
            'shopify_domain' => 'demo.myshopify.com',
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
            'options' => $imageSize ? ['image_size' => $imageSize] : [],
        ]);

        $migration->setRelation('shop', $shop);
        $migration->setRelation('connection', $conn);

        return $migration;
    }

    /**
     * WordPress names generated copies by their REAL scaled dimensions, which is
     * why these URLs have to be read rather than guessed: the height depends on
     * the source image's aspect ratio.
     */
    private function fakeMedia(array $sizes): void
    {
        Http::fake([
            '*/wp-json/wp/v2/media*' => Http::response([[
                'id' => 7,
                'media_details' => ['sizes' => $sizes],
            ]], 200),
        ]);
    }

    private const WP_SIZES = [
        'thumbnail' => ['width' => 150, 'source_url' => 'https://woo.example.com/p-150x150.jpg'],
        'medium' => ['width' => 300, 'source_url' => 'https://woo.example.com/p-300x225.jpg'],
        'large' => ['width' => 1024, 'source_url' => 'https://woo.example.com/p-1024x768.jpg'],
        'full' => ['width' => 4000, 'source_url' => 'https://woo.example.com/p.jpg'],
    ];

    public function test_standard_picks_the_largest_copy_under_1024(): void
    {
        $this->fakeMedia(self::WP_SIZES);

        $migration = $this->migration('standard');
        $resolver = app(ImageSizeResolver::class);
        $resolver->prefetch($migration, [7]);

        $this->assertSame(
            'https://woo.example.com/p-1024x768.jpg',
            $resolver->url($migration, 7, 'https://woo.example.com/p.jpg'),
        );
    }

    public function test_large_reaches_for_a_bigger_copy_when_one_exists(): void
    {
        $this->fakeMedia(self::WP_SIZES + [
            '1536x1536' => ['width' => 1536, 'source_url' => 'https://woo.example.com/p-1536x1152.jpg'],
        ]);

        $migration = $this->migration('large');
        $resolver = app(ImageSizeResolver::class);
        $resolver->prefetch($migration, [7]);

        $this->assertSame(
            'https://woo.example.com/p-1536x1152.jpg',
            $resolver->url($migration, 7, 'https://woo.example.com/p.jpg'),
        );
    }

    public function test_full_never_touches_wordpress_at_all(): void
    {
        Http::fake();

        $migration = $this->migration('full');
        $resolver = app(ImageSizeResolver::class);

        $this->assertFalse($resolver->isActive($migration));

        $resolver->prefetch($migration, [7]);

        Http::assertNothingSent();
        $this->assertSame(
            'https://woo.example.com/p.jpg',
            $resolver->url($migration, 7, 'https://woo.example.com/p.jpg'),
        );
    }

    public function test_an_image_already_smaller_than_the_target_is_sent_as_is(): void
    {
        // An 800px upload has no 1024px copy — nothing here beats the original.
        $this->fakeMedia([
            'medium' => ['width' => 300, 'source_url' => 'https://woo.example.com/p-300x225.jpg'],
            'full' => ['width' => 800, 'source_url' => 'https://woo.example.com/p.jpg'],
        ]);

        $migration = $this->migration('standard');
        $resolver = app(ImageSizeResolver::class);
        $resolver->prefetch($migration, [7]);

        $this->assertSame(
            'https://woo.example.com/p.jpg',
            $resolver->url($migration, 7, 'https://woo.example.com/p.jpg'),
        );
    }

    public function test_a_host_that_blocks_wp_v2_falls_back_to_originals(): void
    {
        // Plenty of stores sit behind a security plugin that closes wp/v2 off.
        Http::fake(['*/wp-json/wp/v2/media*' => Http::response('Forbidden', 403)]);

        $migration = $this->migration('standard');
        $resolver = app(ImageSizeResolver::class);
        $resolver->prefetch($migration, [7]);

        $this->assertSame(
            'https://woo.example.com/p.jpg',
            $resolver->url($migration, 7, 'https://woo.example.com/p.jpg'),
        );

        // Said once, then it stops asking for the rest of the run.
        $this->assertSame(1, MigrationLog::where('migration_id', $migration->id)->count());
        $this->assertFalse($resolver->isActive($migration));
    }

    public function test_an_unknown_image_falls_back_to_the_original(): void
    {
        $this->fakeMedia(self::WP_SIZES);

        $migration = $this->migration('standard');
        $resolver = app(ImageSizeResolver::class);
        $resolver->prefetch($migration, [7]);

        // id 99 was never looked up, and an image with no id at all is common.
        $this->assertSame('https://x/other.jpg', $resolver->url($migration, 99, 'https://x/other.jpg'));
        $this->assertSame('https://x/other.jpg', $resolver->url($migration, null, 'https://x/other.jpg'));
    }

    public function test_topping_up_keeps_what_the_page_already_looked_up(): void
    {
        $this->fakeMedia(self::WP_SIZES);

        $migration = $this->migration('standard');
        $resolver = app(ImageSizeResolver::class);

        $resolver->prefetch($migration, [7], reset: true);
        // A variation image arriving later must not wipe the page's table.
        $resolver->prefetch($migration, [8]);

        $this->assertSame(
            'https://woo.example.com/p-1024x768.jpg',
            $resolver->url($migration, 7, 'https://woo.example.com/p.jpg'),
        );
    }
}
