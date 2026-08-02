<?php

declare(strict_types=1);

use Spatie\Health\Checks\Checks\CacheCheck;
use Spatie\Health\Checks\Checks\DatabaseCheck;
use Spatie\Health\Checks\Checks\DatabaseConnectionCountCheck;
use Spatie\Health\Checks\Checks\DebugModeCheck;
use Spatie\Health\Checks\Checks\EnvironmentCheck;
use Spatie\Health\Checks\Checks\OptimizedAppCheck;
use Spatie\Health\Checks\Checks\QueueCheck;
use Spatie\Health\Checks\Checks\RedisCheck;
use Spatie\Health\Checks\Checks\UsedDiskSpaceCheck;

return [

    /*
    |--------------------------------------------------------------------------
    | Result Stores
    |--------------------------------------------------------------------------
    | Dónde se persiste el resultado del check (para historial). En la
    | plantilla usamos solo el endpoint en vivo, no historial — los
    | result stores se activan por proyecto si hace falta.
    */
    'result_stores' => [
        // Spatie\Health\ResultStores\EloquentHealthResultStore::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    | A quién avisar cuando un check falla. La plantilla deja Sentry como
    | canal por defecto (vía LOG_STACK=daily,sentry).
    */
    'notifications' => [
        'enabled'       => false,
        'notifications' => [
            // Spatie\Health\Notifications\CheckFailedNotification::class => ['mail'],
        ],
        'mail' => [
            'to' => env('HEALTH_ALERT_EMAIL', 'admin@grupoolympo.com'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Checks
    |--------------------------------------------------------------------------
    | Los checks que el endpoint /health ejecuta. Puedes agregar/quitar
    | desde el HealthServiceProvider, o sobreescribir aquí.
    */
    'checks' => [
        DebugModeCheck::class,
        EnvironmentCheck::class,
        DatabaseCheck::class,
        DatabaseConnectionCountCheck::class,
        RedisCheck::class,
        CacheCheck::class,
        QueueCheck::class,
        UsedDiskSpaceCheck::class,
        OptimizedAppCheck::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Theme
    |--------------------------------------------------------------------------
    */
    'theme' => 'tailwind',
];
