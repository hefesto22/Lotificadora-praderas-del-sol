<?php

declare(strict_types=1);

namespace App\Filament\Resources\Ventas\Pages;

use App\Filament\Resources\Ventas\VentaResource;
use Filament\Resources\Pages\ViewRecord;
use Override;

/**
 * La ficha del expediente.
 *
 * Sin acciones de encabezado todavía: editar una venta firmada no es una
 * acción genérica (ver el docblock de `VentaResource`). Rescindir, liquidar
 * e imprimir el contrato entran acá cuando se construya cada trámite.
 */
class ViewVenta extends ViewRecord
{
    #[Override]
    protected static string $resource = VentaResource::class;
}
