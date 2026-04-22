<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default tuning (overridable via env)
    |--------------------------------------------------------------------------
    */
    'default_target_stock' => (int) env('INVENTORY_CHECK_TARGET_STOCK', 3),
    'max_order_line_qty' => (int) env('INVENTORY_CHECK_MAX_ORDER_LINE', 25),
    'exclude_zero_day_sell_speed' => filter_var(env('INVENTORY_CHECK_EXCLUDE_RSD_ZERO', true), FILTER_VALIDATE_BOOLEAN),

    /*
    |--------------------------------------------------------------------------
    | Per-bucket thresholds — used by InventoryCheckService::buildBuckets
    |--------------------------------------------------------------------------
    | These encode Sarah's process as of 2026-04-22. Tune in place; the
    | service reads each key at query time so no deploy needed after edit.
    */
    'buckets' => [
        // 🔥 Fast-moving, out of stock — vinyl
        'fast_oos_vinyl' => [
            'category_pattern' => 'Sealed Vinyl',
            'sale_days' => 60,
            'min_sold' => 2,
            'max_stock' => 0,
            'target_stock' => 3,
        ],
        // 🔥 Fast-moving, out of stock — CD
        'fast_oos_cd' => [
            'category_pattern' => 'Sealed CD',
            'sale_days' => 90,
            'min_sold' => 1,
            'max_stock' => 0,
            'target_stock' => 2,
        ],
        // Fast sellers (any category) — avg purchase→sell days ≤ threshold
        'fast_seller' => [
            'sale_days' => 90,
            'max_avg_sell_days' => 21,
            'max_stock' => 2,
            'target_stock' => 3,
        ],
        // Long out-of-stock essentials — auto-detected
        'long_oos_essentials' => [
            'lookback_days' => 365,
            'min_lifetime_sold' => 12,
            'min_oos_days' => 14,
            'target_stock' => 2,
        ],
        // Top artists — lookback for "popular in our store"
        'top_artists' => [
            'lookback_days' => 90,
            'top_n' => 50,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | AMS-oriented export (adjust to match supplier import template)
    |--------------------------------------------------------------------------
    */
    'ams_export_columns' => [
        'bucket',
        'sku',
        'product',
        'artist',
        'format',
        'location',
        'current_stock',
        'suggested_qty',
        'source_tags',
        'reason',
    ],

    'copy_line_format' => '{qty} x {sku} — {product}',

    /*
    |--------------------------------------------------------------------------
    | Presets — location/category resolved by name pattern at runtime
    |--------------------------------------------------------------------------
    */
    'presets' => [
        'hollywood_all' => [
            'label' => 'Hollywood — all sections',
            'location_name_pattern' => 'Hollywood',
            'sale_days' => 90,
        ],
        'hollywood_sealed_vinyl' => [
            'label' => 'Hollywood · Sealed Vinyl',
            'location_name_pattern' => 'Hollywood',
            'category_name_pattern' => 'Sealed Vinyl',
            'sale_days' => 90,
        ],
        'hollywood_sealed_cd' => [
            'label' => 'Hollywood · Sealed CD',
            'location_name_pattern' => 'Hollywood',
            'category_name_pattern' => 'Sealed CD',
            'sale_days' => 90,
        ],
    ],

    'default_supplier_name_pattern' => env('INVENTORY_CHECK_SUPPLIER_PATTERN', 'AMS'),

    'max_candidate_rows' => (int) env('INVENTORY_CHECK_MAX_ROWS', 2000),

    /*
    |--------------------------------------------------------------------------
    | Events — pulled from the Nivessa website's public events API
    |--------------------------------------------------------------------------
    | Set NIVESSA_EVENTS_API_URL in .env to the full URL (e.g.
    | https://api.nivessa.com/api/v1/events/allEvents). If unset, the
    | "Upcoming events" bucket is skipped silently.
    */
    'events_api_url' => env('NIVESSA_EVENTS_API_URL', ''),
    'events_lookahead_days' => (int) env('NIVESSA_EVENTS_LOOKAHEAD_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Email auto-fetch (Street Pulse + UMe Universal weekly charts)
    |--------------------------------------------------------------------------
    | Required env vars to enable:
    |   INVENTORY_CHECK_IMAP_HOST=imap.gmail.com
    |   INVENTORY_CHECK_IMAP_PORT=993
    |   INVENTORY_CHECK_IMAP_USERNAME=sarah@nivessa.com
    |   INVENTORY_CHECK_IMAP_PASSWORD=<gmail app password>   # 16 chars, no spaces
    |   INVENTORY_CHECK_IMAP_ENCRYPTION=ssl
    |
    | Generate the app password at:
    |   https://myaccount.google.com/apppasswords
    | (requires 2-Step Verification on the Google account). Label it
    | "Nivessa ERP chart import" so you can revoke it cleanly later.
    |
    | Also requires the PHP imap extension. Install with:
    |   sudo apt install php8.1-imap && sudo service php8.1-fpm restart
    | (adjust for your PHP version).
    */
    'email' => [
        'host' => env('INVENTORY_CHECK_IMAP_HOST', 'imap.gmail.com'),
        'port' => (int) env('INVENTORY_CHECK_IMAP_PORT', 993),
        'username' => env('INVENTORY_CHECK_IMAP_USERNAME', ''),
        'password' => env('INVENTORY_CHECK_IMAP_PASSWORD', ''),
        'encryption' => env('INVENTORY_CHECK_IMAP_ENCRYPTION', 'ssl'),
        'sources' => [
            'street_pulse' => [
                'from' => env('INVENTORY_CHECK_STREETPULSE_FROM', 'info@streetpulse.com'),
            ],
            'universal_top' => [
                'from' => env('INVENTORY_CHECK_UNIVERSAL_FROM', 'Tony.Kulzer@umusic.com'),
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Legacy keys retained for backward compat with older blade version
    |--------------------------------------------------------------------------
    */
    'empty_tab_max_stock' => (float) env('INVENTORY_CHECK_EMPTY_TAB_MAX_STOCK', 1),
    'empty_tab_min_sold_window' => (float) env('INVENTORY_CHECK_EMPTY_TAB_MIN_SOLD', 2),
    'most_sold_min_qty' => (float) env('INVENTORY_CHECK_MOST_SOLD_MIN', 1),
    'fast_seller_max_avg_days' => (float) env('INVENTORY_CHECK_FAST_MAX_DAYS', 21),
];
