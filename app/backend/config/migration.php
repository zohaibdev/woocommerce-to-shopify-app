<?php

return [
    // Items per WooCommerce API page / Shopify batch write.
    'per_page' => (int) env('MIGRATION_PER_PAGE', 100),

    // ABSOLUTE ceiling on how many batch jobs may run at once for ONE shop —
    // the last word, whatever any per-entity ceiling below says. The working
    // number is derived from the shop's real Shopify budget (see ShopifyPlan).
    //
    // This is deliberately higher than the per-entity default underneath it.
    // The two guard different things, and while they were the SAME number the
    // per-entity ceilings could never rise above it: setting the enterprise
    // order ceiling to 12 did precisely nothing, because this clipped it back
    // to 8 first. Headroom here is what makes those knobs work at all.
    //
    // Raise this only once you have workers to run them: with a single queue
    // worker the jobs still execute one after another.
    'max_concurrency' => (int) env('MIGRATION_MAX_CONCURRENCY', 12),

    // --- How the parallel worker count is derived ---
    //
    // A shop can sustain `restoreRate / write_points` writes per second, and each
    // write occupies a worker for `write_seconds` — multiply the two and you get
    // the workers needed to keep the shop's API budget saturated.
    //
    // `write_seconds` was 0.6, and that was the single reason a VPS running eight
    // queue workers only ever had THREE of them busy. A Standard Shopify store
    // restores 100 points/second, so the old arithmetic derived
    // ceil(100/20 * 0.6) = 3 concurrent batches — and the other five workers had
    // nothing they were allowed to pick up. Worse, 3 workers only consume about
    // 24% of that store's actual budget, so the app was pacing itself far below
    // the limit it was trying to respect.
    //
    // 0.6s is simply not how long a Shopify write takes: productSet runs
    // SYNCHRONOUSLY while Shopify downloads every image on the product, which is
    // seconds, not milliseconds (the estimate's own baseline says 2.5s). The
    // default below is deliberately still conservative — it roughly doubles the
    // parallelism on a Standard store rather than jumping to the ceiling — and it
    // is a setting now, because the honest number depends on image sizes more
    // than on anything in this app.
    //
    // Two independent safety nets sit under this: ShopifyClient::pace() eases off
    // as the point bucket drains, and a THROTTLED response is retried with the
    // wait Shopify itself computes. Raising this cannot silently overrun the API.
    'shopify_write_points' => (float) env('MIGRATION_SHOPIFY_WRITE_POINTS', 20),
    'shopify_write_seconds' => (float) env('MIGRATION_SHOPIFY_WRITE_SECONDS', 1.2),

    // Per-entity ceiling ON TOP of the number above, for the entities Shopify
    // meters SEPARATELY from the GraphQL point budget.
    //
    // orderCreate is the only one that matters today: it has its own small rate
    // limit, reported as a plain userError with no Retry-After, so running orders
    // wide can simply convert extra workers into throttle errors.
    //
    // KEYED BY ShopifyPlan::tier() — the SAME tier detection the worker count
    // already uses, never a second copy of it. This used to be one flat number
    // (MIGRATION_ORDER_CONCURRENCY, default 2) applied to every store on the
    // installation, which meant a Plus merchant paying for ten times the API
    // budget migrated orders at exactly Basic speed.
    //
    // A word on what these numbers are and are not. Shopify does not document
    // the order-creation limit at all, so these are NOT a claim that it scales
    // with plan — they are permission to TRY, made safe by the fact that the
    // throttle handling underneath settles wherever Shopify actually draws the
    // line: a refusal pauses the batch, backs off along
    // migration.soft_throttle_backoff and resumes, and no order is ever lost or
    // written twice (migration_items is unique per source id). So a tier whose
    // real limit turns out to be lower simply throttles back down to it.
    //
    // Tier names come from ShopifyPlan::TIERS, which is derived from the shop's
    // measured API restore rate: Basic AND Grow are both `standard` (Shopify
    // gives them the same 100 points/second), Advanced is `advanced`, and Plus
    // and Enterprise are their own tiers. A shop whose limits have not been
    // measured yet reads as `standard`, which is the conservative default.
    'entity_concurrency' => [
        // The SOURCE-side bound, applied to every entity that does not name its
        // own ceiling — products, categories, customers, coupons and content.
        //
        // This is the one that protects the merchant's WooCommerce host rather
        // than Shopify: a product batch reads variations and image metadata per
        // record, so running those wide is what knocks a shared WordPress host
        // over. It is 8 because that is exactly what these entities were already
        // limited to when max_concurrency was 8 — raising the absolute ceiling
        // above must not quietly start hammering merchant stores harder.
        'default' => (int) env('MIGRATION_SOURCE_CONCURRENCY', 8),

        'order' => [
            'standard' => (int) env('MIGRATION_ORDER_CONCURRENCY_STANDARD', 2),
            'advanced' => (int) env('MIGRATION_ORDER_CONCURRENCY_ADVANCED', 3),
            'plus' => (int) env('MIGRATION_ORDER_CONCURRENCY_PLUS', 6),
            'enterprise' => (int) env('MIGRATION_ORDER_CONCURRENCY_ENTERPRISE', 8),
        ],
    ],

    // What to wait, in seconds, after Shopify soft-rate-limits a mutation —
    // "Too many attempts. Please try again later." on orderCreate, above all.
    //
    // Shopify sends NO Retry-After with these, so any number here is a guess,
    // and the guess used to be a flat 60s: on a store whose window is actually
    // shorter, every single one of those cost most of a minute of doing nothing
    // while still holding a concurrency slot. The ladder probes early and backs
    // off only when the early probe was refused, so a fast store recovers in
    // seconds and a genuinely slow one still ends up waiting a minute.
    'soft_throttle_backoff' => [5, 10, 20, 40, 60],

    // Write one `debug` log line per queue job (see App\Support\MigrationDiagnostics):
    // which worker ran it, how long it spent in Shopify vs WooCommerce vs waiting
    // out a throttle, and what it handed on. This is what the admin panel's
    // developer log is built from, so turning it off blinds that page.
    'debug_log' => (bool) env('MIGRATION_DEBUG_LOG', true),

    // Biggest serialized WooCommerce page we will park in the cache between the
    // jobs of one batch. Caching it saves re-downloading the page on every 35s
    // resume, which on a slow host is most of the batch's cost.
    //
    // Production usually runs the DATABASE cache store (shared hosting has no
    // Redis), so this value lands in a single MySQL row and has to fit inside
    // `max_allowed_packet`. That is 4MB on a stock MySQL and on plenty of shared
    // hosts, 16MB+ on most managed ones — so the default is set below the lower
    // figure rather than the higher one. A write that does not fit is not an
    // error, it just means the page is fetched again; the run says so once.
    'max_cached_page_bytes' => (int) env('MIGRATION_MAX_CACHED_PAGE_BYTES', 3_500_000),

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
