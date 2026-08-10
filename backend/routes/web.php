<?php

use Illuminate\Support\Facades\Route;

/**
 * Public landing page + the built React SPA.
 *
 * "/"  → landing page (public/landing.html) for ordinary visitors,
 *        but the SPA when Shopify opens the app (it always passes ?shop= / ?host=).
 * Any other non-/api path → the SPA, so client-side routes such as /admin,
 *        /migrations and /support survive a hard refresh.
 *
 * The SPA shell lives in resources/spa/index.html (NOT public/) so deploying
 * can never collide with a host's default public_html/index.html.
 */
Route::get('/{any?}', function (\Illuminate\Http\Request $request, ?string $any = null) {
    $spa = resource_path('spa/index.html');
    $landing = public_path('landing.html');

    // Shopify always sends shop/host when loading or returning to the app.
    $isShopifyContext = $request->hasAny(['shop', 'host', 'embedded', 'hmac']);
    $isRoot = $any === null || $any === '';

    if ($isRoot && ! $isShopifyContext && file_exists($landing)) {
        return response()->file($landing, ['Cache-Control' => 'no-cache, must-revalidate']);
    }

    if (! file_exists($spa)) {
        $spa = public_path('index.html'); // legacy/local fallback
    }

    return file_exists($spa)
        ? response()->file($spa, ['Cache-Control' => 'no-cache, must-revalidate'])
        : view('welcome');
})->where('any', '^(?!api).*$');
