<?php

/*
|--------------------------------------------------------------------------
| POS "Artists You May Also Like" — curated similar-artist map
|--------------------------------------------------------------------------
| Record-store baskets are mostly single-item, so internal "customers also
| bought" data is too sparse for similar-artist recs. This curated map drives
| the POS checkout suggestions: when an artist is in the cart, we look it up
| here and suggest IN-STOCK titles by the listed artists.
|
| Rules:
|   - Keys and values are artist names, matched case-insensitively. Drop any
|     Discogs "(2)" disambiguator — "Green Day", not "Green Day (2)".
|   - Only artists actually in stock will surface; listing extras is harmless.
|   - Add/edit freely. Changes go live on the next deploy.
*/

return [
    'green day' => [
        'blink-182', 'the offspring', 'rancid', 'nofx', 'bad religion',
        'sum 41', 'pennywise', 'social distortion', 'weezer', 'jimmy eat world',
        'descendents', 'anti-flag', 'alkaline trio', 'the clash',
    ],
    'james taylor' => [
        'carole king', 'jackson browne', 'cat stevens', 'carly simon',
        'jim croce', 'paul simon', 'crosby, stills & nash', 'neil young',
        'bonnie raitt', 'van morrison', 'joni mitchell', 'america',
    ],
    'the beatles' => [
        'the rolling stones', 'the kinks', 'the who', 'the beach boys',
        'bob dylan', 'the byrds', 'paul mccartney', 'john lennon', 'george harrison',
    ],
    'pink floyd' => [
        'led zeppelin', 'the doors', 'genesis', 'yes', 'king crimson',
        'david bowie', 'jethro tull', 'rush',
    ],
    'nirvana' => [
        'pearl jam', 'soundgarden', 'alice in chains', 'foo fighters',
        'stone temple pilots', 'the smashing pumpkins', 'mudhoney', 'hole',
    ],
    'fleetwood mac' => [
        'eagles', 'stevie nicks', 'tom petty', 'the doobie brothers',
        'steely dan', 'crosby, stills & nash', 'heart',
    ],
    'miles davis' => [
        'john coltrane', 'charles mingus', 'thelonious monk', 'bill evans',
        'herbie hancock', 'dave brubeck', 'art blakey', 'cannonball adderley',
    ],
    'bob marley' => [
        'peter tosh', 'toots and the maytals', 'jimmy cliff', 'burning spear',
        'steel pulse', 'black uhuru', 'the wailers',
    ],
    'kendrick lamar' => [
        'j. cole', 'drake', 'travis scott', 'tyler, the creator',
        'asap rocky', 'schoolboy q', 'isaiah rashad', 'vince staples',
    ],
    'taylor swift' => [
        'olivia rodrigo', 'lorde', 'lana del rey', 'gracie abrams',
        'phoebe bridgers', 'maggie rogers', 'haim',
    ],
];
