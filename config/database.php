<?php

declare(strict_types=1);

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the database connections below you wish
    | to use as your default connection for database operations. This is
    | the connection which will be utilized unless another connection
    | is explicitly specified when you execute a query / statement.
    |
    */

    'default' => env('DB_CONNECTION', 'pgsql'),

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    |
    | Below are all of the database connections defined for your application.
    | An example configuration is provided for each database system which
    | is supported by Laravel. You're free to add / remove connections.
    |
    */

    'connections' => [

        /*
        | Solo PostgreSQL. Los bloques sqlite, mysql, mariadb y sqlsrv que
        | trae el esqueleto de Laravel se eliminaron a proposito:
        |
        |  1. El §7.1 exige paridad de motor entre dev, test, CI y produccion,
        |     y PROHIBE SQLite en tests. Dejar la conexion configurada lo pone
        |     a una variable de entorno de distancia de ocurrir por accidente.
        |  2. Los bloques mysql y mariadb traian un ternario sobre
        |     PHP_VERSION_ID >= 80500 para tolerar PHP 8.4 y 8.5 a la vez.
        |     Con "php": "^8.5" esa rama quedo muerta y PHPStan nivel 7 la
        |     reportaba como comparacion siempre verdadera.
        */

        'pgsql' => [
            'driver'           => 'pgsql',
            'url'              => env('DB_URL'),
            'host'             => env('DB_HOST', '127.0.0.1'),
            'port'             => env('DB_PORT', '5442'),
            'database'         => env('DB_DATABASE', 'praderas_dev'),
            'username'         => env('DB_USERNAME', 'postgres'),
            'password'         => env('DB_PASSWORD', ''),
            'charset'          => env('DB_CHARSET', 'utf8'),
            'prefix'           => '',
            'prefix_indexes'   => true,
            'search_path'      => env('DB_SCHEMA', 'public'),
            'sslmode'          => env('DB_SSLMODE', 'prefer'),
            'application_name' => env('APP_NAME', 'Laravel'),
        ],

        /*
        | La MISMA base, pero sin PgBouncer en el medio.
        |
        | PgBouncer agrupa por TRANSACCION y reusa la conexion entre
        | clientes distintos: lo que vive fuera de una transaccion no
        | sobrevive. Una migracion toma locks y usa sesion; un worker de
        | cola vive horas. Los dos van por aca, al puerto directo de
        | Postgres. Las peticiones web siguen por el pool, que es para lo
        | que sirve.
        |
        | Sin DB_DIRECT_PORT cae al mismo puerto que 'pgsql': en local las
        | dos conexiones son la misma cosa y no hay nada que recordar.
        */
        'pgsql_direct' => [
            'driver'           => 'pgsql',
            'url'              => env('DB_DIRECT_URL'),
            'host'             => env('DB_HOST', '127.0.0.1'),
            'port'             => env('DB_DIRECT_PORT', env('DB_PORT', '5442')),
            'database'         => env('DB_DATABASE', 'praderas_dev'),
            'username'         => env('DB_USERNAME', 'postgres'),
            'password'         => env('DB_PASSWORD', ''),
            'charset'          => env('DB_CHARSET', 'utf8'),
            'prefix'           => '',
            'prefix_indexes'   => true,
            'search_path'      => env('DB_SCHEMA', 'public'),
            'sslmode'          => env('DB_SSLMODE', 'prefer'),
            'application_name' => env('APP_NAME', 'Laravel'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Repository Table
    |--------------------------------------------------------------------------
    |
    | This table keeps track of all the migrations that have already run for
    | your application. Using this information, we can determine which of
    | the migrations on disk haven't actually been run on the database.
    |
    */

    'migrations' => [
        'table'                  => 'migrations',
        'update_date_on_publish' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis Databases
    |--------------------------------------------------------------------------
    |
    | Redis is an open source, fast, and advanced key-value store that also
    | provides a richer body of commands than a typical key-value system
    | such as Memcached. You may define your connection settings here.
    |
    */

    'redis' => [

        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster'    => env('REDIS_CLUSTER', 'redis'),
            'prefix'     => env('REDIS_PREFIX', Str::slug((string) env('APP_NAME', 'laravel')).'-database-'),
            'persistent' => env('REDIS_PERSISTENT', false),
        ],

        'default' => [
            'url'               => env('REDIS_URL'),
            'host'              => env('REDIS_HOST', '127.0.0.1'),
            'username'          => env('REDIS_USERNAME'),
            'password'          => env('REDIS_PASSWORD'),
            'port'              => env('REDIS_PORT', '6389'),
            'database'          => env('REDIS_DB', '0'),
            'max_retries'       => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base'      => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap'       => env('REDIS_BACKOFF_CAP', 1000),
        ],

        'cache' => [
            'url'               => env('REDIS_URL'),
            'host'              => env('REDIS_HOST', '127.0.0.1'),
            'username'          => env('REDIS_USERNAME'),
            'password'          => env('REDIS_PASSWORD'),
            'port'              => env('REDIS_PORT', '6389'),
            'database'          => env('REDIS_CACHE_DB', '1'),
            'max_retries'       => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base'      => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap'       => env('REDIS_BACKOFF_CAP', 1000),
        ],

        /*
        | Las colas NO viven donde vive la cache.
        |
        | Un Redis de cache se configura para DESALOJAR cuando se llena
        | (allkeys-lru). Un trabajo encolado ahi se puede evaporar sin
        | error y sin registro: el cobro que iba a facturar simplemente no
        | ocurre. Por eso hay un Redis aparte para las colas.
        |
        | Sin las REDIS_QUEUE_* cae en el mismo Redis que todo lo demas:
        | en local sigue siendo una sola instancia.
        */
        'queue' => [
            'url'               => env('REDIS_QUEUE_URL'),
            'host'              => env('REDIS_QUEUE_HOST', env('REDIS_HOST', '127.0.0.1')),
            'username'          => env('REDIS_QUEUE_USERNAME', env('REDIS_USERNAME')),
            'password'          => env('REDIS_QUEUE_PASSWORD', env('REDIS_PASSWORD')),
            'port'              => env('REDIS_QUEUE_PORT', env('REDIS_PORT', '6389')),
            'database'          => env('REDIS_QUEUE_DB', '2'),
            'max_retries'       => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base'      => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap'       => env('REDIS_BACKOFF_CAP', 1000),
        ],

    ],

];
