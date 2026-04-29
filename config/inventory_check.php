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
        // 🎸 Hot used, currently out — watch-list, not reorderable
        // Used items come from customer trade-ins / Discogs, not AMS —
        // so this bucket is advisory: "when a copy walks in, prioritize".
        // Aggregates sales by product (title), not variation — used
        // inventory is usually one variation per physical copy, so
        // variation-level counts almost never hit the threshold.
        'hot_used_oos' => [
            'category_patterns' => ['Used Vinyl', 'Used CD'],
            'sale_days' => 90,
            'min_sold' => 2,
            'max_stock' => 0,
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
        'sold_qty_window',
        'avg_sell_days',
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
        'pico_all' => [
            'label' => 'Pico — all sections',
            'location_name_pattern' => 'Pico',
            'sale_days' => 90,
        ],
        'pico_sealed_vinyl' => [
            'label' => 'Pico · Sealed Vinyl',
            'location_name_pattern' => 'Pico',
            'category_name_pattern' => 'Sealed Vinyl',
            'sale_days' => 90,
        ],
        'pico_sealed_cd' => [
            'label' => 'Pico · Sealed CD',
            'location_name_pattern' => 'Pico',
            'category_name_pattern' => 'Sealed CD',
            'sale_days' => 90,
        ],
    ],

    'default_supplier_name_pattern' => env('INVENTORY_CHECK_SUPPLIER_PATTERN', 'AMS'),

    /*
    |--------------------------------------------------------------------------
    | Must-have artists per location (Sarah's display lists, Feb 2026)
    |--------------------------------------------------------------------------
    | These artists are always treated as "popular in-store" for the
    | matching location, even if they haven't sold in the recent window.
    | Used by InventoryCheckService::getTopArtists to overlay the data-
    | driven top sellers — so a Radiohead chart pick at Pico still tags
    | as top_artist even during a slow Radiohead month.
    |
    | Keys are case-insensitive substrings matched against the location
    | name. Update the lists here when Sarah swaps the displayed wall.
    */
    'must_have_artists_by_location' => [
        'pico' => [
            'Radiohead', 'The Beatles', 'Kendrick Lamar', 'David Bowie',
            'Stevie Wonder', 'Bob Dylan', 'Kanye West', 'Daft Punk', 'Sade',
            'Miles Davis', 'Fleetwood Mac', 'Mac Miller', 'Lana Del Rey',
            'Taylor Swift', 'Laufey', 'Queen', 'Alice In Chains',
            'Michael Jackson', 'Talking Heads', 'Tame Impala', 'Deftones',
            'SZA', 'Grateful Dead', 'Pink Floyd', 'John Coltrane',
            'Tyler The Creator', 'Tyler, The Creator', 'Elton John',
            'Beyonce', 'The Doors', 'Marvin Gaye', 'Steely Dan', 'Oasis',
            'Green Day', 'Aretha Franklin', 'Post Malone', 'Nirvana', 'Tool',
            'TV Girl', 'The Smiths', 'The Rolling Stones',
            'Sly And The Family Stone', 'The Marias', 'War', 'Gene Harris',
            'Nine Inch Nails', 'The Beach Boys', 'Dua Lipa', 'Arctic Monkeys',
            'Beloyd', 'Blink 182', 'Al Green', 'Big Thief', 'Donna Summer',
            'Doechii', 'Mariah Carey', 'The Police', 'Supertramp',
            'Led Zeppelin', 'Black Sabbath', "D'Angelo", 'Gorillaz',
            'Jimi Hendrix', 'George Harrison', 'MF Doom', 'James Brown',
            'The Cure', 'Fred Again', 'Lady Gaga', 'Playboi Carti',
            'Jeff Buckley', 'Billy Joel', 'Mac Demarco', 'Chalino Sanchez',
            'The Weeknd', 'Frank Sinatra', 'The Strokes', 'Cocteau Twins',
            'Lauryn Hill', 'Sublime', 'Willie Nelson', 'New Order',
            'Roberta Flack', 'Whitney Houston', 'Joni Mitchell', 'Charli XCX',
            'Bob Marley', 'Scarface', 'The Offs', 'Cat Stevens',
            'Depeche Mode', 'Sabrina Carpenter', 'Chappell Roan', 'Drake',
            'Sampha', 'Secret Life Of Us', 'Jerry Garcia', 'Lil Wayne',
            'Duke Pearson', 'ABBA', 'Suicidal Tendencies', 'Eminem',
            'No Doubt', 'Smino', 'Amy Winehouse', 'Childish Gambino',
            'Jungle', '436', 'Bon Iver', 'Nina Simone', 'Duran Duran',
            'Genesis', 'Beastie Boys', 'Glass Animals', 'Santana',
            'My Bloody Valentine', 'Elvis Costello', 'System Of A Down',
            'Back To The Future', 'Def Leppard', 'Joy Division',
            'Bruce Springsteen', 'Mary J Blige', 'Rage Against The Machine',
            'Snoop Dogg', 'George Benson', 'Travis Scott', 'The Clash',
            'Jay Z', 'Jay-Z',
        ],
        'hollywood' => [
            'Kendrick Lamar', 'Kanye West', 'Taylor Swift', 'Mac Miller',
            'Lana Del Rey', 'Michael Jackson', 'The Beatles', 'Deftones',
            'Nirvana', 'The Weeknd', 'Radiohead', 'Tyler The Creator',
            'Tyler, The Creator', 'Fleetwood Mac', 'Drake', 'Billie Eilish',
            'Playboi Carti', 'Daft Punk', 'Sabrina Carpenter', 'SZA',
            'Travis Scott', 'Beyonce', 'Sade', 'Pink Floyd', 'Metallica',
            'Led Zeppelin', 'Eminem', 'Lady Gaga', 'The 1975', 'Ariana Grande',
            'Queen', 'The Smiths', 'Miles Davis', 'Bob Dylan', 'Tame Impala',
            "Guns N' Roses", 'Guns N Roses', '2Pac', 'One Direction',
            'Kali Uchis', 'System Of A Down', 'The Rolling Stones',
            'Marvin Gaye', 'Frank Sinatra', 'Prince', 'Black Sabbath',
            'Green Day', 'Laufey', 'The Doors', 'Amy Winehouse',
            'Arctic Monkeys', 'Chappell Roan', 'Misfits', 'Charli XCX',
            'Outkast', 'Depeche Mode', 'Sublime', 'Coldplay', 'Mac Demarco',
            'Grateful Dead', 'MF Doom', 'Iron Maiden', 'Nine Inch Nails',
            'Adele', 'Korn', 'Post Malone', 'Oasis', 'Kiss', 'The Cure',
            'Harry Styles', 'Stevie Wonder', 'Lauryn Hill', 'Gorillaz',
            'Alice In Chains', 'Bob Marley', 'The Strokes', 'David Bowie',
            'Tool', 'The Marias', 'Lil Uzi Vert', 'Elton John',
            'Twenty One Pilots', 'Slayer', 'A$AP Rocky', 'ASAP Rocky',
            '50 Cent', 'Weezer', 'Elvis Presley', 'U2', 'Suicidal Tendencies',
            'Aphex Twin', 'Gracie Abrams', 'Red Hot Chili Peppers',
            '$uicideboy$', 'Suicideboys', 'Aretha Franklin', 'Lil Peep',
            'Clairo', 'Olivia Rodrigo', 'Dr Dre', 'Dr. Dre', 'Miley Cyrus',
            'Al Green', 'Jimi Hendrix', 'Nas', 'AC/DC', 'ACDC', 'Snoop Dogg',
            'John Coltrane', 'Britney Spears', 'Madonna', 'Linkin Park',
            'Karol G', 'Blink 182', 'No Doubt', 'A Tribe Called Quest',
            'Joy Division', 'Danzig', 'Lil Wayne', 'George Harrison',
            'Jay Z', 'Jay-Z', 'Ice Cube', 'ABBA', 'Slipknot', 'Tracy Chapman',
            'The Police', 'Janet Jackson', 'Kid Cudi', 'Air', 'Elliot Smith',
            'Elliott Smith', 'Santana', 'Chet Baker', 'Fred Again',
        ],
    ],

    'max_candidate_rows' => (int) env('INVENTORY_CHECK_MAX_ROWS', 2000),

    /*
    |--------------------------------------------------------------------------
    | Events — pulled from the Nivessa website's public events API
    |--------------------------------------------------------------------------
    | Set NIVESSA_EVENTS_API_URL in .env to the full URL (e.g.
    | https://api.nivessa.com/api/v1/events/allEvents). If unset, the
    | "Upcoming events" bucket is skipped silently.
    */
    'events_api_url' => env('NIVESSA_EVENTS_API_URL', 'https://server.nivessa.com/api/v1/events/allEvents'),
    // Public site's Ticketmaster LA feed (off-/api/ path because nginx
    // hijacks /api/* on the website host). Returns the same shows that
    // back the /events LA tab — what Sarah wants stocked for.
    'events_ticketmaster_url' => env('NIVESSA_TM_FEED_URL', 'https://nivessa.com/ticketmaster-feed'),
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
