<?php

declare(strict_types=1);

return [

    'dsn' => env('SENTRY_LARAVEL_DSN'),

    'release' => env('SENTRY_RELEASE'),

    'environment' => env('SENTRY_ENVIRONMENT', env('APP_ENV', 'production')),

    /*
    |--------------------------------------------------------------------------
    | Sample rates
    |--------------------------------------------------------------------------
    | En producción usar tasas bajas (0.05 - 0.20) para no exceder cuota.
    | Los errores siempre se reportan al 100% — esto es para tracing/perfilado.
    */
    'traces_sample_rate'   => (float) env('SENTRY_TRACES_SAMPLE_RATE', 0.1),
    'profiles_sample_rate' => (float) env('SENTRY_PROFILES_SAMPLE_RATE', 0.1),

    /*
    |--------------------------------------------------------------------------
    | PII
    |--------------------------------------------------------------------------
    | NUNCA enviar PII a Sentry sin justificación expresa (§15).
    */
    'send_default_pii' => env('SENTRY_SEND_DEFAULT_PII', false),

    /*
    |--------------------------------------------------------------------------
    | Breadcrumbs
    |--------------------------------------------------------------------------
    */
    'breadcrumbs' => [
        'logs'                 => true,
        'cache'                => false,
        'livewire'             => true,
        'sql_queries'          => true,
        'sql_bindings'         => false,
        'queue_info'           => true,
        'command_info'         => true,
        'http_client_requests' => true,
        'notifications'        => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Tracing
    |--------------------------------------------------------------------------
    */
    'tracing' => [
        'queue_job_transactions'  => true,
        'queue_jobs'              => true,
        'sql_queries'             => true,
        'sql_origin'              => true,
        'views'                   => true,
        'livewire'                => true,
        'http_client_requests'    => true,
        'redis_commands'          => false,
        'notifications'           => true,
        'continue_after_response' => true,
        'default_integrations'    => true,
    ],
];
