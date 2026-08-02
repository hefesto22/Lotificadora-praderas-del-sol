<?php

declare(strict_types=1);
use Spatie\Backup\Notifications\Notifiable;
use Spatie\Backup\Notifications\Notifications\BackupHasFailedNotification;
use Spatie\Backup\Notifications\Notifications\BackupWasSuccessfulNotification;
use Spatie\Backup\Notifications\Notifications\CleanupHasFailedNotification;
use Spatie\Backup\Notifications\Notifications\CleanupWasSuccessfulNotification;
use Spatie\Backup\Notifications\Notifications\HealthyBackupWasFoundNotification;
use Spatie\Backup\Notifications\Notifications\UnhealthyBackupWasFoundNotification;
use Spatie\Backup\Tasks\Cleanup\Strategies\DefaultStrategy;
use Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumAgeInDays;
use Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumStorageInMegabytes;
use Spatie\DbDumper\Compressors\GzipCompressor;

return [

    'backup' => [

        /*
        |----------------------------------------------------------------------
        | Nombre del backup — usa APP_SLUG para que cada proyecto guarde
        | en su propia "carpeta" dentro del bucket compartido.
        |----------------------------------------------------------------------
        */
        'name' => env('APP_SLUG', env('APP_NAME', 'laravel-backup')),

        'source' => [

            'files' => [

                'include' => [
                    base_path(),
                ],

                'exclude' => [
                    base_path('vendor'),
                    base_path('node_modules'),
                    base_path('storage/framework/cache'),
                    base_path('storage/framework/sessions'),
                    base_path('storage/framework/views'),
                    base_path('storage/logs'),
                    base_path('.git'),
                    base_path('.env'),
                    base_path('public/build'),
                    base_path('public/storage'),
                ],

                'follow_links'                  => false,
                'ignore_unreadable_directories' => false,
                'relative_path'                 => null,
            ],

            /*
            |------------------------------------------------------------------
            | Bases de datos a respaldar. Por defecto la conexión principal.
            | Postgres usa pg_dump (instalado dentro del contenedor postgres).
            |------------------------------------------------------------------
            */
            'databases' => [
                config('database.default'),
            ],
        ],

        /*
        |----------------------------------------------------------------------
        | Compresión de los SQL dumps.
        |----------------------------------------------------------------------
        */
        'database_dump_compressor'            => GzipCompressor::class,
        'database_dump_file_timestamp_format' => null,
        'database_dump_filename_base'         => 'database',
        'database_dump_file_extension'        => '',

        'destination' => [
            'compression_method' => ZipArchive::CM_DEFAULT,
            'compression_level'  => 9,
            'filename_prefix'    => '',

            /*
            |------------------------------------------------------------------
            | Discos donde se sube el backup. 'local' para desarrollo,
            | 's3' para producción (configurar AWS_* en .env).
            |------------------------------------------------------------------
            */
            'disks' => [
                'local',
                // 's3', // descomentar cuando esté configurado AWS_BUCKET en .env
            ],
        ],

        'temporary_directory' => storage_path('app/backup-temp'),
        'password'            => env('BACKUP_ARCHIVE_PASSWORD'),
        'encryption'          => 'default',
        'tries'               => 1,
        'retry_delay'         => 0,
    ],

    /*
    |--------------------------------------------------------------------------
    | Notificaciones cuando los backups fallan.
    |--------------------------------------------------------------------------
    */
    'notifications' => [
        'notifications' => [
            BackupHasFailedNotification::class         => ['mail'],
            UnhealthyBackupWasFoundNotification::class => ['mail'],
            CleanupHasFailedNotification::class        => ['mail'],
            BackupWasSuccessfulNotification::class     => [],
            HealthyBackupWasFoundNotification::class   => [],
            CleanupWasSuccessfulNotification::class    => [],
        ],

        'notifiable' => Notifiable::class,

        'mail' => [
            'to'   => env('BACKUP_ALERT_EMAIL', 'admin@grupoolympo.com'),
            'from' => [
                'address' => env('MAIL_FROM_ADDRESS', 'no-reply@grupoolympo.com'),
                'name'    => env('MAIL_FROM_NAME', 'Backups'),
            ],
        ],

        'slack' => [
            'webhook_url' => env('BACKUP_SLACK_WEBHOOK', ''),
            'channel'     => null,
            'username'    => 'Backups Olympo',
            'icon'        => null,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Monitor de backups — verifica que existen y son recientes.
    |--------------------------------------------------------------------------
    */
    'monitor_backups' => [
        [
            'name'          => env('APP_SLUG', env('APP_NAME', 'laravel-backup')),
            'disks'         => ['local'],
            'health_checks' => [
                MaximumAgeInDays::class          => 1,
                MaximumStorageInMegabytes::class => 5000,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Política de retención — cuántos backups guardar.
    |--------------------------------------------------------------------------
    */
    'cleanup' => [
        'strategy' => DefaultStrategy::class,

        'default_strategy' => [
            'keep_all_backups_for_days'                            => 7,
            'keep_daily_backups_for_days'                          => 30,
            'keep_weekly_backups_for_weeks'                        => 8,
            'keep_monthly_backups_for_months'                      => 6,
            'keep_yearly_backups_for_years'                        => 2,
            'delete_oldest_backups_when_using_more_megabytes_than' => 5000,
        ],

        'tries'       => 1,
        'retry_delay' => 0,
    ],
];
