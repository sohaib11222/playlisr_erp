<?php

/*
|--------------------------------------------------------------------------
| Nivessa COGS purchase-price assumptions
|--------------------------------------------------------------------------
|
| Used when a sold item has no recorded purchase price (pl.purchase_price_inc_tax
| is NULL or 0) so COGS / gross-margin reports don't silently drop those rows.
|
| Matching is case-insensitive substring match on the sub-category name first,
| then the main category name. First match wins, evaluated in array order, so
| put more specific patterns before more general ones ("Damaged Vinyl" before
| "Vinyl").
|
| Source: Sarah, 2026-04-20 Slack thread — these are the assumption prices
| she used for 2025 taxes when purchase data was missing.
|
*/

return [

    'enabled' => env('COGS_FALLBACK_ENABLED', true),

    // First match wins — put specific patterns before general ones.
    'patterns' => [
        ['match' => 'damaged',           'price' => 0.00],   // Damaged Vinyl & CDs
        ['match' => 'used cassette',     'price' => 0.30],
        ['match' => 'new cassette',      'price' => 6.00],
        ['match' => 'sealed cassette',   'price' => 6.00],
        ['match' => 'used vinyl',        'price' => 0.10],
        ['match' => 'new vinyl',         'price' => 17.00],
        ['match' => 'sealed vinyl',      'price' => 17.00],  // same as new
        ['match' => 'used cd',           'price' => 0.10],   // includes "CD (Used)"
        ['match' => 'cd (used)',         'price' => 0.10],
        ['match' => 'new cd',            'price' => 6.00],
        ['match' => 'sealed cd',         'price' => 6.00],
        ['match' => 'cd (sealed)',       'price' => 6.00],
        ['match' => 'vhs',               'price' => 0.10],
        // Bare-format fallbacks for rows where only the general category is set
        ['match' => 'cassette',          'price' => 6.00],   // assume new if not tagged
        ['match' => 'vinyl',             'price' => 17.00],  // assume new if not tagged
        ['match' => 'cd',                'price' => 6.00],   // assume new if not tagged
    ],

    // If no pattern matches, use this. NULL means "leave cost blank" (old behavior).
    'default' => null,
];
