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
    | Auto shift notes at register close
    |--------------------------------------------------------------------------
    |
    | When true, the register-close modal shows an auto-built shift summary
    | (sales, items mass-added, items purchased, labels printed + value +
    | categories) and the close writes a shift-note JSON to
    | storage/app/shift-notes/ — the eventual replacement for the manual
    | #shift-notes Slack posts.
    |
    | Kept FALSE so it stays dark for cashiers until the feature is fully
    | functional. Admins/owners always see it (preview) regardless of this
    | flag, so the feature can be verified before flipping it on for
    | everyone. To go live for all staff, change this default to true and
    | push (no .env / SSH change needed).
    */
    'shift_notes_enabled' => env('SHIFT_NOTES_ENABLED', true),

    // Slack incoming-webhook URL for the #shift-notes channel. When set,
    // each register close auto-posts the shift note there. Leave blank to
    // keep capturing JSON only (no posting). Webhook is channel-bound, so
    // no channel name is needed here.
    'shift_notes_slack_webhook' => env('SHIFT_NOTES_SLACK_WEBHOOK', ''),

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
