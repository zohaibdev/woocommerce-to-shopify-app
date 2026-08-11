<?php

namespace App\Console\Commands;

use App\Models\Shop;
use App\Services\ShopifyWebhookRegistrar;
use Illuminate\Console\Command;

/**
 * Repoints every installed shop's webhooks at the current SHOPIFY_APP_URL.
 *
 * Needed after the app URL changes (new domain, moved to /app, tunnel rotated) —
 * existing subscriptions keep pointing at the old address and every delivery
 * fails with WEBHOOK_DELIVERY until they are updated.
 *
 *   php artisan shopify:sync-webhooks
 *   php artisan shopify:sync-webhooks --shop=my-store.myshopify.com
 */
class SyncWebhooks extends Command
{
    protected $signature = 'shopify:sync-webhooks {--shop= : Limit to one myshopify domain}';

    protected $description = 'Re-register Shopify webhooks against the current app URL';

    public function handle(ShopifyWebhookRegistrar $registrar): int
    {
        $query = Shop::query()->whereNotNull('access_token')->whereNull('uninstalled_at');

        if ($shop = $this->option('shop')) {
            $query->where('shopify_domain', $shop);
        }

        $shops = $query->get();

        if ($shops->isEmpty()) {
            $this->warn('No installed shops found.');

            return self::SUCCESS;
        }

        $this->info('App URL: ' . config('services.shopify.app_url'));

        foreach ($shops as $s) {
            $this->line("→ {$s->shopify_domain}");
            foreach ($registrar->sync($s) as $topic => $result) {
                $style = str_starts_with($result, 'error') ? 'error' : 'info';
                $this->{$style}(sprintf('   %-24s %s', $topic, $result));
            }
        }

        return self::SUCCESS;
    }
}
