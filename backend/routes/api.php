<?php

use App\Http\Controllers\Auth\ShopifyOAuthController;
use App\Http\Controllers\MigrationController;
use App\Http\Controllers\ShopController;
use App\Http\Controllers\WebhookController;
use App\Http\Controllers\WooCommerceController;
use Illuminate\Support\Facades\Route;

// --- Shopify OAuth (install flow) ---
Route::get('/auth', [ShopifyOAuthController::class, 'begin']);
Route::get('/auth/callback', [ShopifyOAuthController::class, 'callback']);

// --- Shopify webhooks (HMAC-verified inside the controller) ---
Route::post('/webhooks/app-uninstalled', [WebhookController::class, 'appUninstalled']);
Route::post('/webhooks/customers-data-request', [WebhookController::class, 'customersDataRequest']);
Route::post('/webhooks/customers-redact', [WebhookController::class, 'customersRedact']);
Route::post('/webhooks/shop-redact', [WebhookController::class, 'shopRedact']);

// Everything below is authenticated with a Shopify App Bridge session token
// (Bearer JWT). Handlers are scoped to the current shop via CurrentShop.
Route::middleware('shopify.session')->group(function () {
    Route::get('/shop', [ShopController::class, 'show']);

    Route::prefix('woocommerce')->group(function () {
        Route::post('/connect', [WooCommerceController::class, 'connect']);
        Route::post('/test', [WooCommerceController::class, 'test']);
        Route::get('/preview', [WooCommerceController::class, 'preview']);
    });

    Route::prefix('migrations')->group(function () {
        Route::get('/', [MigrationController::class, 'index']);
        Route::post('/', [MigrationController::class, 'store']);
        Route::get('/{id}', [MigrationController::class, 'show']);
        Route::get('/{id}/items', [MigrationController::class, 'items']);
        Route::get('/{id}/download', [MigrationController::class, 'download']);
        Route::post('/{id}/cancel', [MigrationController::class, 'cancel']);
        Route::post('/{id}/retry-failed', [MigrationController::class, 'retryFailed']);
    });
});
