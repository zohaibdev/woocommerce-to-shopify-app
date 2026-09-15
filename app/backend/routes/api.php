<?php

use App\Http\Controllers\Admin\AdminAuthController;
use App\Http\Controllers\Admin\AdminContentController;
use App\Http\Controllers\Admin\AdminMigrationController;
use App\Http\Controllers\Admin\AdminPlanController;
use App\Http\Controllers\Admin\AdminSettingsController;
use App\Http\Controllers\Admin\AdminStoreController;
use App\Http\Controllers\Admin\AdminTicketController;
use App\Http\Controllers\Auth\ShopifyOAuthController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\BrandingController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\DashboardContentController;
use App\Http\Controllers\MigrationController;
use App\Http\Controllers\ShopController;
use App\Http\Controllers\WebhookController;
use App\Http\Controllers\WooCommerceController;
use Illuminate\Support\Facades\Route;

// --- Shopify OAuth (install flow) ---
Route::get('/auth', [ShopifyOAuthController::class, 'begin']);
Route::get('/auth/callback', [ShopifyOAuthController::class, 'callback']);

// --- Billing callback (Shopify redirects the merchant here) ---
Route::get('/billing/callback', [BillingController::class, 'callback']);

// --- Email logo (public: mail clients fetch it with no session) ---
Route::get('/branding/logo', [BrandingController::class, 'logo']);

// --- Public contact form on the marketing site (rate limited: it has no auth) ---
Route::post('/contact', [ContactController::class, 'send'])->middleware('throttle:5,60');

// --- Public plan list for the marketing site's pricing table (no shop context) ---
Route::get('/plans', [BillingController::class, 'publicPlans']);

// --- Shopify webhooks (HMAC-verified inside the controller) ---
Route::post('/webhooks/app-uninstalled', [WebhookController::class, 'appUninstalled']);
Route::post('/webhooks/app-subscriptions-update', [WebhookController::class, 'appSubscriptionsUpdate']);
Route::post('/webhooks/customers-data-request', [WebhookController::class, 'customersDataRequest']);
Route::post('/webhooks/customers-redact', [WebhookController::class, 'customersRedact']);
Route::post('/webhooks/shop-redact', [WebhookController::class, 'shopRedact']);

// Everything below is authenticated with a Shopify App Bridge session token
// (Bearer JWT). Handlers are scoped to the current shop via CurrentShop.
Route::middleware('shopify.session')->group(function () {
    Route::get('/shop', [ShopController::class, 'show']);

    Route::prefix('woocommerce')->group(function () {
        // Read the stored connection without touching the merchant's store.
        Route::get('/connection', [WooCommerceController::class, 'connection']);
        Route::post('/connect', [WooCommerceController::class, 'connect']);
        Route::post('/test', [WooCommerceController::class, 'test']);
        Route::get('/preview', [WooCommerceController::class, 'preview']);
    });

    // Admin-managed content shown in the merchant dashboard + support tickets.
    Route::get('/billing', [BillingController::class, 'index']);
    Route::post('/billing/subscribe', [BillingController::class, 'subscribe']);
    Route::post('/billing/cancel', [BillingController::class, 'cancel']);

    Route::get('/content', [DashboardContentController::class, 'index']);
    Route::post('/support/tickets', [DashboardContentController::class, 'createTicket']);
    Route::get('/support/tickets', [DashboardContentController::class, 'myTickets']);
    Route::post('/support/tickets/{id}/reply', [DashboardContentController::class, 'replyToTicket']);

    Route::prefix('migrations')->group(function () {
        Route::get('/', [MigrationController::class, 'index']);
        Route::post('/', [MigrationController::class, 'store']);
        Route::get('/{id}', [MigrationController::class, 'show']);
        Route::get('/{id}/items', [MigrationController::class, 'items']);
        Route::get('/{id}/logs', [MigrationController::class, 'logs']);
        Route::get('/{id}/download', [MigrationController::class, 'download']);
        Route::post('/{id}/cancel', [MigrationController::class, 'cancel']);
        Route::post('/{id}/retry-failed', [MigrationController::class, 'retryFailed']);
    });
});

// ---------------------------------------------------------------------------
// Super Admin API — separate bearer-token auth, never Shopify session tokens.
// ---------------------------------------------------------------------------
Route::prefix('admin')->group(function () {
    Route::post('/login', [AdminAuthController::class, 'login']);

    Route::middleware('admin.auth')->group(function () {
        Route::get('/me', [AdminAuthController::class, 'me']);
        Route::post('/logout', [AdminAuthController::class, 'logout']);

        Route::get('/overview', [AdminStoreController::class, 'overview']);
        // One click instead of an SSH session — see AdminStoreController.
        Route::post('/queue/run', [AdminStoreController::class, 'runQueue']);
        Route::post('/queue/retry-failed', [AdminStoreController::class, 'retryFailedJobs']);

        // Stores & plans
        Route::get('/stores', [AdminStoreController::class, 'index']);
        Route::get('/stores/{id}', [AdminStoreController::class, 'show']);
        // Support tool: let one entity type be migrated again for one store.
        Route::post('/stores/{id}/reset-entity', [AdminStoreController::class, 'resetEntity']);
        Route::get('/plans', [AdminPlanController::class, 'index']);
        Route::post('/plans', [AdminPlanController::class, 'store']);
        Route::patch('/plans/{id}', [AdminPlanController::class, 'update']);
        Route::delete('/plans/{id}', [AdminPlanController::class, 'destroy']);

        // Migrations & error logs
        Route::get('/migrations', [AdminMigrationController::class, 'index']);
        // Before /{id}: a literal segment must be registered ahead of the
        // wildcard or "debug" is matched as an id.
        Route::get('/migrations/{id}/debug', [AdminMigrationController::class, 'debug']);
        Route::get('/migrations/{id}/debug-log', [AdminMigrationController::class, 'debugLog']);
        Route::get('/migrations/{id}', [AdminMigrationController::class, 'show']);
        Route::get('/errors', [AdminMigrationController::class, 'errors']);

        // Support tickets
        Route::get('/tickets', [AdminTicketController::class, 'index']);
        Route::get('/tickets/{id}', [AdminTicketController::class, 'show']);
        Route::post('/tickets/{id}/reply', [AdminTicketController::class, 'reply']);
        Route::patch('/tickets/{id}', [AdminTicketController::class, 'update']);

        // Application settings (SMTP) — DB-backed, not .env.
        Route::get('/settings/mail', [AdminSettingsController::class, 'mail']);
        Route::put('/settings/mail', [AdminSettingsController::class, 'updateMail']);
        Route::post('/settings/mail/test', [AdminSettingsController::class, 'testMail']);
        Route::get('/settings/branding', [AdminSettingsController::class, 'branding']);
        Route::post('/settings/branding', [AdminSettingsController::class, 'updateBranding']);

        // Dashboard content management
        Route::get('/content', [AdminContentController::class, 'index']);
        Route::post('/content', [AdminContentController::class, 'store']);
        Route::patch('/content/{id}', [AdminContentController::class, 'update']);
        Route::delete('/content/{id}', [AdminContentController::class, 'destroy']);
        Route::post('/content/reorder', [AdminContentController::class, 'reorder']);
    });
});
