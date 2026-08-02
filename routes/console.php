<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Console Commands
|--------------------------------------------------------------------------
*/

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Tareas Programadas
|--------------------------------------------------------------------------
| Para que estas tareas corran en producción, agrega al crontab del servidor:
|
|   * * * * * cd /var/www/proyectos/<nombre> && php artisan schedule:run >> /dev/null 2>&1
|
| (En Herd local NO necesita cron — el built-in scheduler de Herd
| corre `schedule:run` cada minuto automáticamente.)
*/

// ─── Backups ───────────────────────────────────────────────────────────
// Backup completo (DB + files) diario a las 02:00 a.m. hora Honduras.
Schedule::command('backup:run')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground()
    ->name('backup-diario');

// Limpieza de backups antiguos según política de retención (config/backup.php).
Schedule::command('backup:clean')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->name('backup-cleanup');

// Monitor de salud de backups — verifica que existen y son recientes.
Schedule::command('backup:monitor')
    ->dailyAt('04:00')
    ->onOneServer()
    ->name('backup-monitor');

// ─── Health checks ─────────────────────────────────────────────────────
// Ejecuta los checks definidos en HealthServiceProvider y los persiste
// si tienes result_stores activados en config/health.php.
Schedule::command('health:check')
    ->everyMinute()
    ->onOneServer()
    ->name('health-check');

// Notifica si algún check falló (envía mail/slack según config/health.php).
Schedule::command('health:schedule-check-heartbeat')
    ->everyMinute()
    ->onOneServer()
    ->name('health-heartbeat');

// ─── Horizon ───────────────────────────────────────────────────────────
// Snapshot de métricas para el dashboard de Horizon.
Schedule::command('horizon:snapshot')
    ->everyFiveMinutes()
    ->onOneServer()
    ->name('horizon-snapshot');

// ─── Mantenimiento de modelos ──────────────────────────────────────────
// Borra registros soft-deleted que cumplan la política de retención
// (cada modelo define su prunable() — opcional).
Schedule::command('model:prune')
    ->dailyAt('05:00')
    ->onOneServer()
    ->name('model-prune');

// ─── Logs ──────────────────────────────────────────────────────────────
// Limpieza de jobs fallidos de hace más de 7 días.
Schedule::command('queue:prune-failed --hours=168')
    ->dailyAt('05:30')
    ->onOneServer()
    ->name('queue-prune-failed');

// Limpieza de batches viejos.
Schedule::command('queue:prune-batches --hours=168')
    ->dailyAt('05:45')
    ->onOneServer()
    ->name('queue-prune-batches');

// ─── Telescope / Activity Log (si se activan) ──────────────────────────
// Schedule::command('activitylog:clean')->daily();
// Schedule::command('telescope:prune --hours=48')->daily();

// ─── Sesiones expiradas ────────────────────────────────────────────────
// Solo si SESSION_DRIVER=database. En Redis no hace falta (TTL automático).
// Schedule::command('session:prune-expired')->daily();
