<?php

use Illuminate\Support\Facades\Route;

/**
 * Serves the built React SPA.
 *
 * In production the app is mounted under /app/ (the landing page owns the
 * domain root and is served statically by Apache), so Laravel only ever sees
 * requests inside that folder — it detects the subdirectory automatically from
 * SCRIPT_NAME, which is why routes stay declared without the /app prefix.
 *
 * The SPA shell lives in resources/spa/index.html (NOT public/) so deploying
 * can never collide with a host's default index.html.
 *
 * The catch-all keeps client-side routes (/admin, /migrations, /support)
 * working on hard refresh; /api/* is matched earlier by routes/api.php.
 */
Route::get('/{any?}', function () {
    $spa = resource_path('spa/index.html');

    if (! file_exists($spa)) {
        $spa = public_path('index.html'); // local/legacy fallback
    }

    return file_exists($spa)
        ? response()->file($spa, ['Cache-Control' => 'no-cache, must-revalidate'])
        : view('welcome');
})->where('any', '^(?!api).*$');
