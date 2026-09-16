<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'shopify' => [
        'api_key' => env('SHOPIFY_API_KEY'),
        'api_secret' => env('SHOPIFY_API_SECRET'),
        // Pin a SUPPORTED stable version. Shopify serves an unsupported one (the old
        // default here, 2024-10) from the oldest supported version instead, and
        // apps using unsupported resources get delisted. 2026-07 is supported
        // until July 16, 2027 — see https://shopify.dev/docs/api/usage/versioning
        'api_version' => env('SHOPIFY_API_VERSION', '2026-07'),
        // write_content covers blogs, articles and pages; write_discounts covers
        // coupons. Adding a scope means every already-installed merchant is asked
        // to re-authorize on their next load — ShopifyOAuth diffs the granted
        // scopes against these and re-runs the OAuth flow when they differ.
        'scopes' => env('SHOPIFY_SCOPES', 'write_products,write_customers,read_products,read_customers,write_inventory,read_orders,write_orders,write_content,write_discounts'),
        // Public base URL of this app (the tunnel URL in dev). Used to build the
        // OAuth redirect_uri and the post-install redirect back into the app.
        'app_url' => rtrim(env('SHOPIFY_APP_URL', env('APP_URL')), '/'),
        // Allowed clock skew (seconds) when validating session-token exp/nbf.
        'token_leeway' => (int) env('SHOPIFY_TOKEN_LEEWAY', 60),
        // Test charges: merchants are never billed. Keep true until the app is live.
        'billing_test' => (bool) env('SHOPIFY_BILLING_TEST', true),
        // appSubscriptionCancel(prorate:). false (Shopify's default): no credit,
        // and the merchant keeps the plan until the end of the period they already
        // paid for. true: Shopify credits the unused part — deducted from the
        // Partner payout — and the plan ends immediately. The cancel dialog
        // explains whichever is configured.
        'billing_prorate_cancellations' => (bool) env('SHOPIFY_BILLING_PRORATE_CANCELLATIONS', false),
    ],

    'support' => [
        // Where new support tickets are emailed. Comma-separated for several
        // inboxes. Blank => every super admin account gets them.
        'admin_email' => env('SUPPORT_ADMIN_EMAIL'),
    ],

];
