<?php

namespace App\Http\Controllers;

use App\Services\MailSettings;
use App\Services\ShopifyOAuth;
use App\Support\CurrentShop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShopController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $shop = CurrentShop::resolve($request);
        $connection = $shop->woocommerceConnection;

        $missingScopes = ShopifyOAuth::missingScopes($shop->scopes);

        return response()->json([
            'shopify_domain' => $shop->shopify_domain,
            'name' => $shop->name,
            'woocommerce_connected' => $connection?->status === 'connected',
            // App update / scope status.
            'granted_scopes' => collect(explode(',', (string) $shop->scopes))
                ->map(fn ($s) => trim($s))->filter()->values(),
            'required_scopes' => ShopifyOAuth::requiredScopes(),
            'missing_scopes' => $missingScopes,
            // Only prompt to update once the app is actually installed.
            'needs_scope_update' => $shop->access_token && ! empty($missingScopes),
            // force=1 makes /api/auth always run the consent flow instead of
            // short-circuiting back into the app when the install already looks fine.
            'reauth_url' => ShopifyOAuth::appUrl() . '/api/auth?force=1&shop=' . urlencode($shop->shopify_domain),
            // A shop may only have one run at a time (MigrationController::store
            // enforces it), so the UI hides "New migration" rather than offering
            // a button whose only outcome is a 409.
            'active_migration_id' => MigrationController::activeMigrationId($shop),
            // Whether "email me when it's done" can actually deliver anything.
            'email_delivery_configured' => MailSettings::canDeliver(),
            // Prefills the notification field.
            'email' => $shop->email,
        ]);
    }
}
