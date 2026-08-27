<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Infraestructura;
use Illuminate\Support\ServiceProvider;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Checks\CacheCheck;
use Spatie\Health\Checks\Checks\DatabaseCheck;
use Spatie\Health\Checks\Checks\DatabaseConnectionCountCheck;
use Spatie\Health\Checks\Checks\DebugModeCheck;
use Spatie\Health\Checks\Checks\EnvironmentCheck;
use Spatie\Health\Checks\Checks\OptimizedAppCheck;
use Spatie\Health\Checks\Checks\QueueCheck;
use Spatie\Health\Checks\Checks\RedisCheck;
use Spatie\Health\Checks\Checks\ScheduleCheck;
use Spatie\Health\Checks\Checks\UsedDiskSpaceCheck;
use Spatie\Health\Facades\Health;

/**
 * Service Provider para spatie/laravel-health.
 *
 * Registra los checks que el endpoint /health ejecutará. Cada check
 * verifica un componente crítico del sistema y reporta OK/WARN/FAIL.
 *
 * Útil para integrar con UptimeRobot, Pingdom, Better Uptime, etc.
 *
 * Endpoints expuestos por el paquete:
 *   GET /health → JSON con estado de cada check (200 OK / 500 si algún FAIL)
 *
 * En producción se recomienda restringir el endpoint por IP a tu servicio
 * de monitoreo (vía middleware en routes/web.php o en config/health.php).
 */
class HealthServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        /** @var list<Check> $checks */
        $checks = [
            // ── Entorno ─────────────────────────────────────────────
            EnvironmentCheck::new()
                ->expectEnvironment((string) config('app.env', 'production')),

            // En producción, debug mode = false
            DebugModeCheck::new()
                ->expectedToBe(! app()->environment('production')),

            OptimizedAppCheck::new(),

            // ── Base de datos ───────────────────────────────────────
            DatabaseCheck::new()
                ->connectionName(config('database.default')),

            DatabaseConnectionCountCheck::new()
                ->failWhenMoreConnectionsThan(80)
                ->warnWhenMoreConnectionsThan(60),

            // ── Cache (verifica que el store funciona escribiendo y leyendo) ──
            CacheCheck::new()
                ->driver(config('cache.default')),

            // ── Queue (workers procesando jobs) ─────────────────────
            QueueCheck::new()
                ->onQueue('default'),

            /*
             * ── El cron del servidor ────────────────────────────────
             *
             * `health:schedule-check-heartbeat` ya escribía el latido cada
             * minuto, pero NADIE lo miraba: el latido se guardaba y ahí
             * quedaba. Este check es el que lo lee.
             *
             * Sin él, un cron que nunca se instaló se ve exactamente igual
             * que uno sano —en silencio— y con el cron caído no hay
             * respaldo, ni limpieza, ni monitoreo. Con él, /health lo grita
             * y `olympo:verificar-produccion` no deja entregar el servidor.
             *
             * Cinco minutos y no uno: un servidor cargado puede atrasar un
             * `schedule:run` sin estar roto.
             */
            ScheduleCheck::new()
                ->heartbeatMaxAgeInMinutes(5),

            // ── Disco ───────────────────────────────────────────────
            UsedDiskSpaceCheck::new()
                ->warnWhenUsedSpaceIsAbovePercentage(70)
                ->failWhenUsedSpaceIsAbovePercentage(85),
        ];

        /*
         * ═══ 🔴 REDIS, SOLO SI ESTA INSTALACION LO USA (27-ago-2026) ═══
         *
         * Este check estaba SIEMPRE, y en Praderas del Sol —que corre a
         * propósito sin Redis— reventaba cada minuto con
         * `Class "Redis" not found`. Escribió 8 MB de log en un día, y el
         * 26-ago un 500 de verdad quedó sepultado ahí adentro: un `tail -80`
         * solo alcanzaba para ver dos minutos de basura.
         *
         * Un check que falla siempre no avisa de nada; solo tapa a los que sí
         * tienen algo que decir. La pregunta se la hace `Infraestructura` a
         * los drivers del `.env`, que es lo único que cambia de una
         * lotificadora a otra.
         */
        if (Infraestructura::usaRedis()) {
            $checks[] = RedisCheck::new();
        }

        Health::checks($checks);
    }
}
