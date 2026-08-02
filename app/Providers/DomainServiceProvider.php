<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Service Provider del dominio Grupo Olympo.
 *
 * Único lugar donde se resuelven las interfaces del Domain a sus
 * implementaciones concretas (§5.5 Dependency Inversion).
 *
 * En la plantilla viene vacío. Cada proyecto que la consuma agregará
 * sus bindings aquí. Ejemplo:
 *
 *   $this->app->bind(
 *       \App\Domain\Facturacion\Contracts\FacturaRepository::class,
 *       \App\Infrastructure\Persistence\Eloquent\Repositories\EloquentFacturaRepository::class
 *   );
 *
 *   $this->app->bind(
 *       \App\Domain\Facturacion\Contracts\GeneradorPdf::class,
 *       \App\Infrastructure\Pdf\BrowsershotPdfGenerator::class
 *   );
 */
final class DomainServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Ejemplo (descomentar cuando exista el binding):
        // $this->app->bind(
        //     \App\Domain\Contracts\GeneradorPdf::class,
        //     \App\Infrastructure\Pdf\BrowsershotPdfGenerator::class
        // );
    }

    public function boot(): void
    {
        //
    }
}
