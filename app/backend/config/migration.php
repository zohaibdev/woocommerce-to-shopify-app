<?php

return [
    // Items per WooCommerce API page / Shopify batch write.
    'per_page' => (int) env('MIGRATION_PER_PAGE', 100),

    // Ceiling on how many batch jobs may run at once for ONE shop. The actual
    // number is derived from the shop's real Shopify budget (see ShopifyPlan),
    // but past a handful of workers the merchant's WooCommerce host — not
    // Shopify — becomes the limit, and piling on more only knocks their store
    // over. Raise this only once you have workers to run them: with a single
    // queue worker the jobs still execute one after another.
    'max_concurrency' => (int) env('MIGRATION_MAX_CONCURRENCY', 8),

    // --- Images ---
    // Default image size when the merchant expresses no preference.
    // One of: standard (1024px), large (1536px), full (original file).
    'image_size' => env('MIGRATION_IMAGE_SIZE', 'standard'),

    // WooCommerce doesn't return its weight unit per product, so map it here.
    // One of: KILOGRAMS, GRAMS, POUNDS, OUNCES.
    'weight_unit' => env('MIGRATION_WEIGHT_UNIT', 'KILOGRAMS'),

    // --- Barcodes (UPC / EAN / GTIN) ---
    // WooCommerce core has no barcode field, so every plugin that adds one picks
    // its own meta key. These are the ones in common use; add the merchant's if
    // it is not here. First key holding a barcode-shaped value wins.
    'barcode_keys' => env('MIGRATION_BARCODE_KEYS', '_upc,_ean,_gtin,_barcode,_wpm_gtin_code,hwp_product_gtin,_alg_ean,_woo_upc'),

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
