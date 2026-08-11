<?php

namespace App\Http\Controllers;

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
            'reauth_url' => ShopifyOAuth::appUrl() . '/api/auth?shop=' . urlencode($shop->shopify_domain),
        ]);
    }
}
