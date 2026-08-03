<?php

declare(strict_types=1);

namespace App\Providers;

use App\Listeners\RecordUserLogin;
use App\Policies\ActivityPolicy;
use App\Policies\RolePolicy;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentView;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // ─── Localización global ────────────────────────────────────────
        // Carbon usa el locale para diffForHumans, translatedFormat, etc.
        // Sin esto, las fechas mostrarán "Monday April 26 2026" en vez
        // de "lunes 26 de abril de 2026".
        $locale = (string) config('app.locale', 'es');
        Carbon::setLocale($locale);
        CarbonImmutable::setLocale($locale);

        // setlocale() afecta a strftime() y formatos del sistema PHP.
        // Útil cuando código legacy usa estas funciones.
        @setlocale(LC_TIME, 'es_HN.UTF-8', 'es_ES.UTF-8', 'es_ES', 'es');
        @setlocale(LC_MONETARY, 'es_HN.UTF-8', 'es_ES.UTF-8', 'es_ES', 'es');

        // ─── Filament: forzar locale español al renderizar el panel ─────
        // Garantiza que mensajes, validaciones y acciones de Filament
        // siempre estén en español, sin importar el header Accept-Language
        // del browser del usuario.
        FilamentView::registerRenderHook(
            'panels::body.start',
            fn (): string => '',
        );
        // El locale se setea automáticamente al servir Filament.
        Filament::serving(function (): void {
            app()->setLocale((string) config('app.locale', 'es'));
        });

        // ─── Policies de modelos que viven en vendor ────────────────────
        //
        // ⚠️ AGUJERO DE SEGURIDAD si esto se borra.
        //
        // El descubrimiento automático de Laravel mapea App\Models\X a
        // App\Policies\XPolicy. `Activity` es de spatie/laravel-activitylog
        // y `Role` es de spatie/laravel-permission: viven fuera de
        // App\Models, así que sus policies NUNCA se descubren solas.
        //
        // Y Filament, cuando no encuentra policy para un modelo, NO deniega:
        // get_authorization_response() (vendor/filament/filament/src/helpers.php)
        // corre los before callbacks del Gate y termina en Response::allow().
        // O sea que sin estas dos líneas, cualquier usuario con acceso al
        // panel ve la bitácora completa —quién tocó qué en todo el sistema—
        // y la pantalla de Roles, que es donde se otorgan privilegios.
        //
        // `shield:generate` lo avisa marcando esas policies como
        // "requires registration". Ese aviso pasa fácil entre 78 permisos
        // generados. Los tests de ClienteResourceTest y ActivityLogResourceTest
        // que exigen 403 para un panel_user son el guardián real.
        Gate::policy(Activity::class, ActivityPolicy::class);
        Gate::policy(Role::class, RolePolicy::class);

        // ─── Eventos ────────────────────────────────────────────────────
        Event::listen(Login::class, RecordUserLogin::class);
    }
}
