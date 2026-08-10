<?php

use Illuminate\Support\Facades\Route;

/**
 * Serves the built React SPA (frontend/dist copied into public/).
 *
 * The catch-all lets client-side routes such as /admin, /migrations and
 * /support survive a hard refresh. API, OAuth and webhook routes live under
 * /api and are matched before this by routes/api.php, so they are unaffected.
 *
 * Falls back to Laravel's welcome view when the SPA hasn't been deployed yet.
 */
Route::get('/{any?}', function () {
    $spa = public_path('index.html');

    return file_exists($spa)
        ? response()->file($spa)
        : view('welcome');
})->where('any', '^(?!api).*$');
