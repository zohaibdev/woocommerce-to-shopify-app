<?php

return [
    // Items per WooCommerce API page / Shopify batch write.
    'per_page' => (int) env('MIGRATION_PER_PAGE', 100),

    // Max concurrent Shopify calls per shop (enforce via Redis::throttle if desired).
    'shopify_concurrency' => (int) env('MIGRATION_SHOPIFY_CONCURRENCY', 2),

    // WooCommerce doesn't return its weight unit per product, so map it here.
    // One of: KILOGRAMS, GRAMS, POUNDS, OUNCES.
    'weight_unit' => env('MIGRATION_WEIGHT_UNIT', 'KILOGRAMS'),

    // --- Metafields ---
    // Namespace for all migrated WooCommerce meta_data.
    'meta_namespace' => env('MIGRATION_META_NAMESPACE', 'woocommerce'),
    // Comma-separated Woo meta keys to skip (e.g. "_wpcom_is_markdown,_edit_lock").
    'skip_meta_keys' => env('MIGRATION_SKIP_META_KEYS', ''),
    // Create a Shopify metafield DEFINITION for each new key (shows in admin).
    'create_metafield_definitions' => (bool) env('MIGRATION_CREATE_METAFIELD_DEFINITIONS', true),
    // Add every brand name as a product tag (in addition to vendor + metafield).
    'brands_as_tags' => (bool) env('MIGRATION_BRANDS_AS_TAGS', true),

    // Fallback vendor for products that have NO brand in WooCommerce.
    // Empty string => let Shopify default the vendor to the shop name.
    // Can be overridden per migration via the wizard's "Default vendor" field.
    'default_vendor' => env('MIGRATION_DEFAULT_VENDOR', ''),
];
