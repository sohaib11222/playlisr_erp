<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default tuning (overridable via env)
    |--------------------------------------------------------------------------
    */
    'default_target_stock' => (int) env('INVENTORY_CHECK_TARGET_STOCK', 3),
    'max_order_line_qty' => (int) env('INVENTORY_CHECK_MAX_ORDER_LINE', 25),
    'empty_tab_max_stock' => (float) env('INVENTORY_CHECK_EMPTY_TAB_MAX_STOCK', 1),
    'empty_tab_min_sold_window' => (float) env('INVENTORY_CHECK_EMPTY_TAB_MIN_SOLD', 2),
    'most_sold_min_qty' => (float) env('INVENTORY_CHECK_MOST_SOLD_MIN', 1),
    'fast_seller_max_avg_days' => (float) env('INVENTORY_CHECK_FAST_MAX_DAYS', 21),
    'exclude_zero_day_sell_speed' => filter_var(env('INVENTORY_CHECK_EXCLUDE_RSD_ZERO', true), FILTER_VALIDATE_BOOLEAN),

    /*
    |--------------------------------------------------------------------------
    | AMS-oriented export (adjust to match supplier import template)
    |--------------------------------------------------------------------------
    | Placeholder column headers — confirm with operations and update here.
    */
    'ams_export_columns' => [
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

    /*
    |--------------------------------------------------------------------------
    | Copy-for-cart format: one line per item
    |--------------------------------------------------------------------------
    */
    'copy_line_format' => '{qty} x {sku} — {product}',

    /*
    |--------------------------------------------------------------------------
    | Presets (location/category resolved by name pattern at runtime)
    |--------------------------------------------------------------------------
    */
    'presets' => [
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
        'hollywood_all_sealed' => [
            'label' => 'Hollywood · Sealed (Vinyl + CD)',
            'location_name_pattern' => 'Hollywood',
            'category_name_pattern' => 'Sealed',
            'sale_days' => 90,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Optional: lock default supplier name for fast-seller preset (AMS)
    |--------------------------------------------------------------------------
    */
    'default_supplier_name_pattern' => env('INVENTORY_CHECK_SUPPLIER_PATTERN', 'AMS'),

    'max_candidate_rows' => (int) env('INVENTORY_CHECK_MAX_ROWS', 2000),
];
