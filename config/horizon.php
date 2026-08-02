<?php

declare(strict_types=1);

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Horizon Domain
    |--------------------------------------------------------------------------
    */
    'domain' => env('HORIZON_DOMAIN'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Path
    |--------------------------------------------------------------------------
    */
    'path' => env('HORIZON_PATH', 'horizon'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Connection
    |--------------------------------------------------------------------------
    */
    'use' => 'default',

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Prefix
    |--------------------------------------------------------------------------
    | Aislamiento entre proyectos cuando comparten Redis (§19.4).
    */
    'prefix' => env('HORIZON_PREFIX', Str::slug((string) env('APP_NAME', 'laravel')).'-horizon:'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Route Middleware
    |--------------------------------------------------------------------------
    | El dashboard /horizon SOLO debe ser accesible por administradores.
    | Ver app/Providers/HorizonServiceProvider.php para gate('viewHorizon').
    */
    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Queue Wait Time Thresholds — segundos
    |--------------------------------------------------------------------------
    */
    'waits' => [
        'redis:default'       => 60,
        'redis:notifications' => 30,
        'redis:pdfs'          => 120,
        'redis:exports'       => 600,
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Trimming Times — minutos
    |--------------------------------------------------------------------------
    */
    'trim' => [
        'recent'        => 60,
        'pending'       => 60,
        'completed'     => 60,
        'recent_failed' => 10080,
        'failed'        => 10080,
        'monitored'     => 10080,
    ],

    /*
    |--------------------------------------------------------------------------
    | Silenced Jobs
    |--------------------------------------------------------------------------
    */
    'silenced' => [],

    /*
    |--------------------------------------------------------------------------
    | Metrics
    |--------------------------------------------------------------------------
    */
    'metrics' => [
        'trim_snapshots' => [
            'job'   => 24,
            'queue' => 24,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fast Termination
    |--------------------------------------------------------------------------
    */
    'fast_termination' => false,

    /*
    |--------------------------------------------------------------------------
    | Memory Limit (MB)
    |--------------------------------------------------------------------------
    */
    'memory_limit' => 256,

    /*
    |--------------------------------------------------------------------------
    | Queue Worker Configuration
    |--------------------------------------------------------------------------
    | Supervisores diferenciados por prioridad y tipo de carga.
    | Ver §16.2 del documento de instrucciones.
    */
    'defaults' => [
        'supervisor-default' => [
            'connection'          => 'redis',
            'queue'               => ['default'],
            'balance'             => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses'        => 1,
            'maxTime'             => 0,
            'maxJobs'             => 0,
            'memory'              => 128,
            'tries'               => 3,
            'timeout'             => 60,
            'nice'                => 0,
        ],
    ],

    'environments' => [
        'production' => [
            'supervisor-default' => [
                'maxProcesses' => 3,
                'memory'       => 256,
                'timeout'      => 60,
            ],
            'supervisor-pdfs' => [
                'connection'   => 'redis',
                'queue'        => ['pdfs'],
                'balance'      => 'auto',
                'maxProcesses' => 2,
                'memory'       => 512,
                'tries'        => 3,
                'timeout'      => 300,
            ],
            'supervisor-exports' => [
                'connection'   => 'redis',
                'queue'        => ['exports'],
                'balance'      => 'simple',
                'maxProcesses' => 1,
                'memory'       => 1024,
                'tries'        => 2,
                'timeout'      => 1800,
            ],
            'supervisor-notifications' => [
                'connection'   => 'redis',
                'queue'        => ['notifications'],
                'balance'      => 'auto',
                'maxProcesses' => 5,
                'memory'       => 128,
                'tries'        => 5,
                'timeout'      => 30,
            ],
        ],

        'staging' => [
            'supervisor-default' => [
                'maxProcesses' => 2,
                'memory'       => 256,
                'timeout'      => 60,
            ],
            'supervisor-pdfs' => [
                'connection'   => 'redis',
                'queue'        => ['pdfs'],
                'maxProcesses' => 1,
                'memory'       => 512,
                'timeout'      => 300,
            ],
            'supervisor-exports' => [
                'connection'   => 'redis',
                'queue'        => ['exports'],
                'maxProcesses' => 1,
                'memory'       => 512,
                'timeout'      => 600,
            ],
        ],

        'local' => [
            'supervisor-default' => [
                'maxProcesses' => 1,
                'memory'       => 128,
                'timeout'      => 60,
            ],
        ],
    ],
];
