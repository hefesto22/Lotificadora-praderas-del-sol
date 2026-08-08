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
use Spatie\Health\Notifications\CheckFailedNotification;

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
        /*
         * Encendidas por defecto desde el 8-ago-2026. Estaban en `false` y
         * eso hacía que /health pudiera estar en rojo por semanas —base
         * caída, disco lleno, cron muerto— sin que nadie recibiera nada.
         * Una alerta apagada es peor que no tenerla: da la sensación de que
         * alguien está mirando.
         */
        'enabled'       => (bool) env('HEALTH_NOTIFICATIONS', true),
        'notifications' => [
            CheckFailedNotification::class => ['mail'],
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
