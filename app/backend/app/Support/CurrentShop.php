<?php

namespace App\Support;

use App\Models\Shop;
use Illuminate\Http\Request;

/**
 * Resolves the current tenant (shop).
 *
 * In the embedded app this comes from the verified Shopify session token
 * (see Phase 2). For local development we fall back to the X-Shop-Domain header
 * or the first shop, so the API is testable before OAuth is wired up.
 */
class CurrentShop
{
    public static function resolve(Request $request): Shop
    {
        if ($domain = $request->attributes->get('shop_domain')) {
            return Shop::where('shopify_domain', $domain)->firstOrFail();
        }

        if ($domain = $request->header('X-Shop-Domain')) {
            return Shop::firstOrCreate(['shopify_domain' => $domain]);
        }

        return Shop::firstOrFail();
    }
}
