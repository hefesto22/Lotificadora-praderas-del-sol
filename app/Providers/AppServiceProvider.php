<?php

declare(strict_types=1);

namespace App\Providers;

use App\Listeners\RecordUserLogin;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentView;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

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

        // ─── Eventos ────────────────────────────────────────────────────
        Event::listen(Login::class, RecordUserLogin::class);
    }
}
