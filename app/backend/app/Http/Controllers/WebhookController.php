<?php

namespace App\Http\Controllers;

use App\Models\MigrationItem;
use App\Models\Shop;
use App\Services\ShopifyBilling;
use App\Services\ShopifyOAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    /**
     * app/uninstalled — clear the shop's token, mark it uninstalled, and record
     * that Shopify has cancelled its charge. Per Shopify no credit is applied and
     * the merchant may use the rest of the paid period if they reinstall.
     */
    public function appUninstalled(Request $request, ShopifyBilling $billing): JsonResponse
    {
        $shopDomain = $this->verify($request);

        return $this->once($request, function () use ($shopDomain, $billing, $request) {
            $shop = Shop::where('shopify_domain', $shopDomain)->first();

            if ($shop) {
                $shop->forceFill(['access_token' => null, 'refresh_token' => null, 'uninstalled_at' => now()])->save();
                $billing->handleUninstall($shop, $this->triggeredAt($request));
            }
        });
    }

    /**
     * app_subscriptions/update — a subscription's status changed on Shopify
     * (approved, cancelled, declined, expired, frozen, unfrozen).
     */
    public function appSubscriptionsUpdate(Request $request, ShopifyBilling $billing): JsonResponse
    {
        $shopDomain = $this->verify($request);

        return $this->once($request, function () use ($shopDomain, $billing, $request) {
            $shop = Shop::where('shopify_domain', $shopDomain)->first();

            if (! $shop) {
                Log::warning('billing.webhook_unknown_shop', ['shop' => $shopDomain]);

                return;
            }

            $billing->handleSubscriptionUpdate($shop, $request->json()->all());
        });
    }

    /**
     * Process a delivery at most once. Shopify retries until it gets a 2xx and
     * may deliver the same event more than once; X-Shopify-Webhook-Id identifies
     * a delivery. The id is remembered only AFTER processing succeeds, so a
     * delivery that failed here is processed again when Shopify retries it. The
     * handlers are idempotent regardless — this just avoids repeating work.
     */
    private function once(Request $request, callable $process): JsonResponse
    {
        $deliveryId = (string) $request->header('X-Shopify-Webhook-Id');
        $key = $deliveryId !== '' ? 'shopify:webhook:'.sha1($deliveryId) : null;

        if ($key && Cache::has($key)) {
            return response()->json(['ok' => true, 'duplicate' => true]);
        }

        $process();

        if ($key) {
            Cache::put($key, 1, now()->addDays(2));
        }

        return response()->json(['ok' => true]);
    }

    /** When Shopify triggered the delivery, if it said. */
    private function triggeredAt(Request $request): ?Carbon
    {
        $header = $request->header('X-Shopify-Triggered-At');

        try {
            return $header ? Carbon::parse($header) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** GDPR: customers/data_request — log the request for the merchant to fulfil. */
    public function customersDataRequest(Request $request): JsonResponse
    {
        $this->verify($request);
        Log::info('GDPR customers/data_request', $request->json()->all());

        return response()->json(['ok' => true]);
    }

    /**
     * GDPR: customers/redact — erase what we hold about one data subject.
     *
     * Shopify REQUIRES this webhook of every app, and it used to log a line and
     * answer ok without deleting anything. Nothing here is optional: the app
     * keeps a mapping row per migrated customer and per migrated order, and an
     * item that failed also keeps Shopify's rejection message, which for a
     * customer routinely quotes the address or the email that was rejected.
     *
     * The payload names the subject by SHOPIFY id, and that is exactly what
     * migration_items.shopify_id holds — as a gid, so the match is on the tail of
     * it. Deleting the mapping means a later re-run would create that customer
     * again, which is the correct trade: the merchant asked us to forget them,
     * and re-migrating is a fresh decision by the merchant rather than something
     * we remembered.
     *
     * @see https://shopify.dev/docs/apps/build/privacy-law-compliance
     */
    public function customersRedact(Request $request): JsonResponse
    {
        $shopDomain = $this->verify($request);
        $payload = $request->json()->all();

        $shop = Shop::where('shopify_domain', $shopDomain)->first();

        if (! $shop) {
            // Nothing of theirs is here to erase. Still a success: answering
            // anything else makes Shopify retry a webhook that can never differ.
            return response()->json(['ok' => true]);
        }

        $deleted = $this->forget($shop, 'customer', array_filter([data_get($payload, 'customer.id')]))
            + $this->forget($shop, 'order', (array) data_get($payload, 'orders_to_redact', []));

        Log::info('GDPR customers/redact', ['shop' => $shopDomain, 'rows_deleted' => $deleted]);

        return response()->json(['ok' => true]);
    }

    /**
     * Delete this shop's mapping rows for the given SHOPIFY ids of one entity.
     *
     * Matched on the tail of the gid (`gid://shopify/Customer/123`) because that
     * is the form the mapping stores. Anchored with the entity type and the
     * leading slash so id 123 can never be matched by 4123.
     *
     * @param  array<int, mixed>  $shopifyIds
     */
    private function forget(Shop $shop, string $entityType, array $shopifyIds): int
    {
        $deleted = 0;

        foreach ($shopifyIds as $id) {
            $id = trim((string) $id);

            if ($id === '' || ! ctype_digit($id)) {
                continue;
            }

            $deleted += MigrationItem::where('shop_id', $shop->id)
                ->where('entity_type', $entityType)
                ->where('shopify_id', 'like', '%/'.ucfirst($entityType).'/'.$id)
                ->delete();
        }

        return $deleted;
    }

    /** GDPR: shop/redact — purge all data for an uninstalled shop after 48h. */
    public function shopRedact(Request $request): JsonResponse
    {
        $shopDomain = $this->verify($request);

        $shop = Shop::where('shopify_domain', $shopDomain)->first();
        $shop?->delete(); // cascades to connections, migrations, items, logs

        return response()->json(['ok' => true]);
    }

    /**
     * Verify the webhook HMAC against the RAW body and return the shop domain.
     * Aborts 401 on failure — never process an unverified webhook.
     */
    private function verify(Request $request): string
    {
        $verified = ShopifyOAuth::verifyWebhook(
            $request->getContent(),
            $request->header('X-Shopify-Hmac-Sha256'),
        );

        abort_unless($verified, 401, 'Invalid webhook HMAC');

        return (string) $request->header('X-Shopify-Shop-Domain');
    }
}
