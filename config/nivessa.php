<?php

return [
    /*
    |--------------------------------------------------------------------------
    | nivessa.com website backend (Sales-by-Channel, etc.)
    |--------------------------------------------------------------------------
    |
    | Read via config() — not env() directly in controllers — so values remain
    | available after `php artisan config:cache`. After changing .env on
    | production, run `php artisan config:clear` or rebuild the config cache.
    |
    */
    'website_api_url' => env('NIVESSA_WEBSITE_API_URL', 'https://nivessa.com'),
    'website_api_key' => env('NIVESSA_WEBSITE_API_KEY', ''),
];
