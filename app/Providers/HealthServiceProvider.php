<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Spatie\Health\Checks\Checks\CacheCheck;
use Spatie\Health\Checks\Checks\DatabaseCheck;
use Spatie\Health\Checks\Checks\DatabaseConnectionCountCheck;
use Spatie\Health\Checks\Checks\DebugModeCheck;
use Spatie\Health\Checks\Checks\EnvironmentCheck;
use Spatie\Health\Checks\Checks\OptimizedAppCheck;
use Spatie\Health\Checks\Checks\QueueCheck;
use Spatie\Health\Checks\Checks\RedisCheck;
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
        Health::checks([
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

            // ── Redis ───────────────────────────────────────────────
            RedisCheck::new(),

            // ── Cache (verifica que el store funciona escribiendo y leyendo) ──
            CacheCheck::new()
                ->driver(config('cache.default')),

            // ── Queue (workers procesando jobs) ─────────────────────
            QueueCheck::new()
                ->onQueue('default'),

            // ── Disco ───────────────────────────────────────────────
            UsedDiskSpaceCheck::new()
                ->warnWhenUsedSpaceIsAbovePercentage(70)
                ->failWhenUsedSpaceIsAbovePercentage(85),
        ]);
    }
}
