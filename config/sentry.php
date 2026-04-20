<?php

return [
    // Docs: https://docs.sentry.io/platforms/php/guides/laravel/
    'dsn' => env('SENTRY_LARAVEL_DSN', env('SENTRY_DSN')),

    // release / environment — Sentry groups errors by these so deploys are separable
    'release' => env('SENTRY_RELEASE'),
    'environment' => env('SENTRY_ENVIRONMENT', env('APP_ENV', 'production')),

    // Breadcrumbs help reconstruct what the user did before the crash
    'breadcrumbs' => [
        'logs' => true,
        'sql_queries' => true,
        'sql_bindings' => false,  // keep bindings off — may contain PII / card data
        'queue_info' => true,
        'command_info' => true,
    ],

    // Performance tracing off by default (keep noise + cost low). Turn on later
    // via SENTRY_TRACES_SAMPLE_RATE=0.1 in .env when you want request profiling.
    'traces_sample_rate' => env('SENTRY_TRACES_SAMPLE_RATE', 0.0),

    // Don't fire Sentry in local dev — noise not worth it
    'send_default_pii' => false,
];
