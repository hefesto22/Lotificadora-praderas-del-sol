<?php

declare(strict_types=1);

namespace App\Providers;

use App\Listeners\RecordUserLogin;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
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

        // ─── Macro ->mayusculas() para texto del dominio (§10.4) ────────
        // Triple defensa: el estilo inline muestra el texto en mayúsculas
        // mientras se escribe, dehydrateStateUsing lo normaliza al guardar
        // desde el panel, y cada modelo tiene además un mutator para que
        // un seeder o un import tampoco puedan meter minúsculas.
        //
        // Estilo inline y no la clase `uppercase` de Tailwind: el CSS de
        // Filament está precompilado y una clase que el panel no incluya
        // simplemente no existe ahí (§9.A7).
        //
        // NO aplicar a nombres de personas, correos, contraseñas ni a
        // símbolos con casing significativo como m² o vara².
        TextInput::macro('mayusculas', function (): TextInput {
            // En runtime $this ES el TextInput: así funcionan los macros de
            // Laravel. PHPStan analiza el closure en el contexto léxico donde
            // está escrito —este provider— y no puede saberlo. Es un falso
            // positivo real, así que se ignora acá, inline y con su razón, en
            // vez de engordar phpstan.neon (§9.B.6).
            /** @var TextInput $componente */
            /** @phpstan-ignore varTag.nativeType */
            $componente = $this;

            return $componente
                ->extraInputAttributes(['style' => 'text-transform: uppercase;'])
                ->dehydrateStateUsing(fn (?string $state): ?string => filled($state)
                    ? mb_strtoupper($state, 'UTF-8')
                    : null);
        });

        // ─── Eventos ────────────────────────────────────────────────────
        Event::listen(Login::class, RecordUserLogin::class);
    }
}
