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

    /*
    |--------------------------------------------------------------------------
    | Database backup settings (ERP local + optional Google Drive upload)
    |--------------------------------------------------------------------------
    */
    'backup_google_drive' => [
        // Set to true to POST each backup file to a Google Drive webhook.
        // Keep false to store local backups only.
        'enabled' => env('DB_BACKUP_GOOGLE_DRIVE_ENABLED', false),
        // Your webhook endpoint (Apps Script, Cloud Run, etc.) that accepts
        // multipart/form-data with: file, business_id, filename, token, folder_id.
        'webhook_url' => env('DB_BACKUP_GOOGLE_DRIVE_WEBHOOK_URL', ''),
        // Optional shared secret checked by your webhook.
        'token' => env('DB_BACKUP_GOOGLE_DRIVE_TOKEN', ''),
        // Optional target Drive folder ID (handled by your webhook).
        'folder_id' => env('DB_BACKUP_GOOGLE_DRIVE_FOLDER_ID', ''),
        'timeout_seconds' => env('DB_BACKUP_GOOGLE_DRIVE_TIMEOUT', 90),
    ],
];
