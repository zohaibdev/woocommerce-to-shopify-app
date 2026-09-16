<?php

namespace App\Console\Commands;

use App\Jobs\SyncShopBillingJob;
use App\Models\Shop;
use App\Models\Subscription;
use App\Services\ShopifyBilling;
use Illuminate\Console\Command;

/**
 * Keep local billing in step with Shopify for the shops where it can drift.
 *
 * Only shops that need it — not every shop every run:
 *   - an active/frozen subscription whose billing period has ended (Shopify
 *     sends no webhook on a routine renewal, so this is how renewals, expiries
 *     and missed cancellations are seen), or whose period end is unknown;
 *   - an open checkout (Shopify expires it after two days, or it was approved
 *     while our callback and webhook were lost);
 *   - any shop with an active/frozen subscription not reconciled for a day.
 *
 * Jobs are spread out by --spacing seconds so a large run never bursts against
 * Shopify, and SyncShopBillingJob is unique per shop.
 */
class SyncBilling extends Command
{
    protected $signature = 'billing:sync
        {--shop= : Sync one myshopify domain now, in this process}
        {--limit=200 : Most shops to queue in one run}
        {--spacing=2 : Seconds between queued syncs}';

    protected $description = 'Reconcile local subscriptions with Shopify for shops whose billing may have drifted';

    public function handle(ShopifyBilling $billing): int
    {
        if ($domain = $this->option('shop')) {
            $shop = Shop::where('shopify_domain', $domain)->first();

            if (! $shop) {
                $this->error("No shop {$domain}.");

                return self::FAILURE;
            }

            $ok = $billing->sync($shop, 'sync');
            $this->line($ok ? "Synced {$domain}." : "Could not reach Shopify for {$domain} — see billing events.");

            return $ok ? self::SUCCESS : self::FAILURE;
        }

        $live = [Subscription::ACTIVE, Subscription::FROZEN];

        $shops = Shop::query()
            ->whereNull('uninstalled_at')
            ->whereNotNull('access_token')
            ->where(function ($q) use ($live) {
                $q->whereHas('subscriptions', fn ($s) => $s->whereIn('status', $live)
                    ->where(fn ($s) => $s->whereNull('current_period_end')->orWhere('current_period_end', '<=', now())))
                    ->orWhereHas('subscriptions', fn ($s) => $s->where('status', Subscription::PENDING)
                        ->where('created_at', '>', now()->subHours(ShopifyBilling::PENDING_LIFETIME_HOURS + 24)))
                    ->orWhere(fn ($q) => $q->whereHas('subscriptions', fn ($s) => $s->whereIn('status', $live))
                        ->where(fn ($q) => $q->whereNull('billing_synced_at')->orWhere('billing_synced_at', '<', now()->subDay())));
            })
            // Longest-unsynced first, so a capped run makes progress on everyone.
            ->orderByRaw('billing_synced_at IS NOT NULL, billing_synced_at')
            ->limit(max(1, (int) $this->option('limit')))
            ->pluck('id');

        $spacing = max(0, (int) $this->option('spacing'));

        foreach ($shops->values() as $i => $shopId) {
            SyncShopBillingJob::dispatch($shopId, 'sync')->delay(now()->addSeconds($i * $spacing));
        }

        $this->line("Queued billing sync for {$shops->count()} shop(s).");

        return self::SUCCESS;
    }
}
