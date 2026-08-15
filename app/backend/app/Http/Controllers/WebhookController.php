<?php

namespace App\Http\Controllers;

use App\Models\MigrationItem;
use App\Models\Shop;
use App\Services\ShopifyOAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    /** app/uninstalled — clear the shop's token and mark it uninstalled. */
    public function appUninstalled(Request $request): JsonResponse
    {
        $shopDomain = $this->verify($request);

        Shop::where('shopify_domain', $shopDomain)->update([
            'access_token' => null,
            'uninstalled_at' => now(),
        ]);

        return response()->json(['ok' => true]);
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
