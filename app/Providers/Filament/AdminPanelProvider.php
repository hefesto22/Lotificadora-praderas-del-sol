<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Http\Middleware\SuspensionPorMora;
use App\Models\BrandingSetting;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Throwable;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            // Path '/' = panel en la raíz. URLs limpias: /, /login, /dashboard, /users.
            // El sistema es 100% panel admin, sin frontend público (decisión de la plantilla).
            ->path('/')
            ->login()
            ->profile()
            // ── Branding dinámico desde BrandingSetting ─────────────────────
            // Cada proyecto que herede la plantilla configura su logo,
            // favicon y color desde el panel sin tocar código.
            ->brandName(fn (): string => env('APP_BRAND_NAME', config('app.name', 'Olympo')))
            ->brandLogo(fn (): ?string => $this->brandingValue('logoUrl'))
            ->darkModeBrandLogo(fn (): ?string => $this->brandingValue('logoUrl'))
            ->brandLogoHeight('2.5rem')
            ->favicon(fn (): ?string => $this->brandingValue('faviconUrl'))
            ->colors([
                'primary' => $this->primaryColorPalette(),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')

            /*
             * Los relation managers de una pagina de VISTA vienen de solo
             * lectura en Filament: se muestran, pero sin crear, editar ni
             * borrar. Es un default razonable para un panel donde "ver" y
             * "editar" son dos pantallas distintas.
             *
             * Aca no lo son. Bloques, Lotes y Planes de pago se administran
             * DENTRO de la ficha del proyecto (5-ago-2026) y no existe otra
             * pantalla donde hacerlo, asi que con el default puesto la
             * pestana se abria sin un solo boton: ni "Nuevo bloque", ni
             * "Nuevo plan", nada. Se veia bien y no dejaba hacer nada.
             *
             * Los permisos siguen mandando: esto solo devuelve las acciones,
             * cada una sigue pasando por su policy.
             */
            ->readOnlyRelationManagersOnResourceViewPagesByDefault(false)
            /*
             * Los estilos de los cuadros que arma PHP —la tabla de lotes, la
             * escalera de cuotas—. Van acá y no en cada Blade porque las mismas
             * dos piezas se ven en el modal del plano y en la ficha del
             * expediente: teniéndolas en una sola pantalla, la otra las mostraba
             * sin un solo margen. Tailwind no las genera, ver el partial.
             */
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                static fn (): string => view('filament.estilos-olympo')->render(),
            )
            /*
             * El chasis visual: neutros frios, una linea de un pixel en vez
             * de sombra, versalitas en las etiquetas y numeros tabulares.
             *
             * Va por renderHook y NO por un tema de Vite a proposito: un tema
             * compilado obliga a que el asset exista, y si falta, Filament
             * revienta con una excepcion del manifiesto y el panel entero
             * deja de abrir. Esto se borra sacando estas cuatro lineas.
             *
             * NO toca el color primario: ese lo elige cada lotificadora desde
             * Configuracion (Ley L0). Solo se corrieron los neutros.
             */
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                static fn (): string => view('filament.tema-olympo')->render(),
            )
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                AccountWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                /*
                 * Clausula Septima. Va DESPUES de Authenticate y no en
                 * `middleware()`: asi la pantalla de login sigue viva y el
                 * super-admin puede entrar a levantar la suspension. Si
                 * corriera sobre todas las rutas del panel, la palanca se
                 * tragaria al que la maneja.
                 */
                SuspensionPorMora::class,
            ])
            ->plugins([
                FilamentShieldPlugin::make(),
            ])
            ->databaseNotifications()
            ->databaseNotificationsPolling('30s')
            ->globalSearchKeyBindings(['command+k', 'ctrl+k'])
            ->sidebarCollapsibleOnDesktop();
    }

    /**
     * Lee un atributo del singleton BrandingSetting con tolerancia a errores.
     *
     * Si la migración aún no se ha corrido (por ejemplo, durante el primer
     * `migrate` del setup), evitamos que Filament muera intentando leer
     * la tabla. En ese caso retornamos null y Filament usa su default.
     */
    private function brandingValue(string $atributo): ?string
    {
        try {
            $valor = BrandingSetting::current()->{$atributo} ?? null;

            return is_string($valor) && $valor !== '' ? $valor : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Genera la paleta de colores para Filament a partir del color
     * primario configurado en BrandingSetting. Si falla, usa Amber.
     *
     * @return array<int|string, string>
     */
    private function primaryColorPalette(): array
    {
        try {
            $hex = BrandingSetting::current()->primary_color;

            if (is_string($hex) && preg_match('/^#[0-9a-f]{6}$/i', $hex) === 1) {
                return Color::hex($hex);
            }
        } catch (Throwable) {
            // Tabla aún no migrada; usamos default.
        }

        return Color::Amber;
    }
}
