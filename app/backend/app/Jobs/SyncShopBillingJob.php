<?php

namespace App\Jobs;

use App\Models\Shop;
use App\Services\ShopifyBilling;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Reconcile one shop's billing with Shopify.
 *
 * Unique per shop: a burst of webhooks (a plan change sends one for the old
 * subscription and one for the new) collapses into a single Shopify call.
 */
class SyncShopBillingJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public int $uniqueFor = 120;

    public function __construct(
        public readonly int $shopId,
        public readonly string $source = 'sync',
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->shopId;
    }

    public function handle(ShopifyBilling $billing): void
    {
        $shop = Shop::find($this->shopId);

        // Uninstalled or never authorised: there is no token to ask Shopify with.
        if (! $shop || $shop->uninstalled_at || ! $shop->access_token) {
            return;
        }

        $billing->sync($shop, $this->source);
    }
}
