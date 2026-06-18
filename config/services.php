<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, SparkPost and others. This file provides a sane default
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'sparkpost' => [
        'secret' => env('SPARKPOST_SECRET'),
    ],

    // OpenPhone — used to SMS customers when their wanted records come in.
    // Key lives in .env as OPENPHONE_API_KEY + OPENPHONE_FROM_NUMBER so it
    // never accidentally lands in the repo. from_number must be an E.164
    // number you own on OpenPhone (example: +13235551234).
    'openphone' => [
        'api_key' => env('OPENPHONE_API_KEY'),
        'from_number' => env('OPENPHONE_FROM_NUMBER'),
        'enabled' => env('OPENPHONE_ENABLED', true),
    ],

    // Nivessa website (jonhedvat/server) → ERP bridge.
    // The website API calls ERP endpoints under /api/v1/nivessa-web/* with
    // `Authorization: Bearer <token>`. business_id scopes all lookups/issues
    // so one ERP can serve multiple shops safely.
    'nivessa_web' => [
        'api_token'   => env('NIVESSA_WEB_API_TOKEN'),
        'business_id' => env('NIVESSA_WEB_BUSINESS_ID'),
        'backend_sync_url' => env('NIVESSA_BACKEND_SYNC_URL'),
    ],

    // Anthropic Claude — powers the in-ERP "Ask the ERP" help assistant
    // (HelpAssistantController). Key lives in .env as ANTHROPIC_API_KEY so it
    // never lands in the repo. If the key is absent the widget still loads and
    // falls back to a "ask a manager" message, so nothing breaks without it.
    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model'   => env('ANTHROPIC_HELP_MODEL', 'claude-haiku-4-5'),
    ],

    // eBay Marketplace Account Deletion webhook (Developer → Alerts & Notifications).
    // Endpoint URL in the portal must match marketplace_deletion_endpoint_url byte-for-byte.
    'ebay' => [
        'ru_name' => env('EBAY_RU_NAME', ''),
        'marketplace_deletion_verification_token' => env('EBAY_MARKETPLACE_DELETION_VERIFICATION_TOKEN', ''),
        'marketplace_deletion_endpoint_url' => env('EBAY_MARKETPLACE_DELETION_ENDPOINT_URL', ''),
    ],

];
