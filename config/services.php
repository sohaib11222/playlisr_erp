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

];
