<?php

use Illuminate\Support\Facades\Route;

/**
 * Serves the built React SPA.
 *
 * The HTML lives in resources/spa/index.html (NOT public/) so that:
 *  - deploying never collides with a host's default public_html/index.html,
 *  - Laravel always controls the response for "/" instead of Apache serving a
 *    stray static file first.
 *
 * Only the hashed build assets go into public/assets. The catch-all keeps
 * client-side routes (/admin, /migrations, /support) working on hard refresh;
 * /api/* is matched earlier by routes/api.php and is unaffected.
 */
Route::get('/{any?}', function () {
    $spa = resource_path('spa/index.html');

    if (! file_exists($spa)) {
        // Legacy/local fallback, then Laravel's welcome page.
        $spa = public_path('index.html');
    }

    return file_exists($spa)
        ? response()->file($spa, ['Cache-Control' => 'no-cache, must-revalidate'])
        : view('welcome');
})->where('any', '^(?!api).*$');
