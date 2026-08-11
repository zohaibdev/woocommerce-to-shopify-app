<?php

namespace App\Http\Controllers;

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

    /** GDPR: customers/redact — delete migrated customer mappings for the data subject. */
    public function customersRedact(Request $request): JsonResponse
    {
        $shopDomain = $this->verify($request);
        Log::info('GDPR customers/redact', ['shop' => $shopDomain]);
        // TODO: delete migration_items for the given customer id(s) once linked.

        return response()->json(['ok' => true]);
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
