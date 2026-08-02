<?php

declare(strict_types=1);

use Spatie\Activitylog\Actions\CleanActivityLogAction;
use Spatie\Activitylog\Actions\LogActivityAction;
use Spatie\Activitylog\Models\Activity;

return [

    /*
     * Si es false no se guarda ninguna actividad.
     *
     * OJO: la variable se llama ACTIVITYLOG_ENABLED desde la v5.
     * En la v4 era ACTIVITY_LOGGER_ENABLED — si quedara la vieja en algun
     * .env el paquete la ignora en silencio y el log sigue encendido.
     */
    'enabled' => env('ACTIVITYLOG_ENABLED', true),

    /*
     * activitylog:clean borra las actividades mas viejas que estos dias.
     *
     * §13: la bitacora financiera es append-only y NO vive aca. Esta tabla
     * es la de auditoria tecnica; un ano es suficiente.
     */
    'clean_after_days' => 365,

    /*
     * Nombre de log por defecto cuando no se pasa uno al helper activity().
     */
    'default_log_name' => 'default',

    /*
     * Driver de auth para resolver el causer. null = el de Laravel.
     */
    'default_auth_driver' => null,

    /*
     * Si es true, la relacion subject devuelve tambien modelos borrados
     * con soft delete.
     */
    'include_soft_deleted_subjects' => false,

    /*
     * Modelo que representa una actividad.
     */
    'activity_model' => Activity::class,

    /*
     * Atributos excluidos del log en TODOS los modelos.
     *
     * §13: nada de secretos ni de hashes en la bitacora. logOnly() de cada
     * modelo ya es una lista blanca, pero esto cierra la puerta por si
     * alguien agrega un modelo con logAll().
     */
    'default_except_attributes' => [
        'password',
        'remember_token',
    ],

    /*
     * Bufferea las actividades en memoria y las inserta en un solo query
     * despues de responder. Solo tiene sentido con mucho volumen por
     * request; apagado, cada actividad se escribe al momento y tiene id.
     */
    'buffer' => [
        'enabled' => env('ACTIVITYLOG_BUFFER_ENABLED', false),
    ],

    /*
     * Clases de accion sobreescribibles (v5). Deben extender las originales.
     */
    'actions' => [
        'log_activity' => LogActivityAction::class,
        'clean_log'    => CleanActivityLogAction::class,
    ],

    /*
     |--------------------------------------------------------------------
     | Claves heredadas de la v4 — el paquete YA NO las lee
     |--------------------------------------------------------------------
     |
     | activitylog v5 elimino 'table_name' y 'database_connection': el modelo
     | Activity fija la tabla activity_log y usa la conexion por defecto.
     |
     | Las conservamos porque las TRES migraciones que el paquete publico en
     | la v4 (2026_02_20_054229/30/31) las leen, y el §12 dice que una
     | migracion aplicada es inmutable. Sin estas claves un migrate:fresh
     | —que es lo que corre en cada test— revienta en la primera de ellas
     | con Schema::create(null).
     |
     | Son literales a proposito, no env(): si vinieran del entorno alguien
     | podria cambiar el nombre de la tabla y la migracion crearia una tabla
     | que el modelo nunca va a leer.
     */
    'table_name'          => 'activity_log',
    'database_connection' => null,
];
