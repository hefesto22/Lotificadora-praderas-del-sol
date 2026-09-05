<?php

declare(strict_types=1);

namespace App\Filament\Resources\Recibos\Pages;

use App\Filament\Resources\Recibos\ReciboResource;
use App\Filament\Support\CorregirRecibo;
use App\Filament\Support\ImprimirRecibo;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Override;

/**
 * La ficha del recibo. Mirarla NO imprime nada.
 *
 * Es la diferencia con la vista imprimible: acá se consulta a qué cuotas fue
 * el dinero y cuántas veces salió el papel, sin que la consulta misma cuente
 * como una impresión. Para el papel está el botón.
 */
class ViewRecibo extends ViewRecord
{
    #[Override]
    protected static string $resource = ReciboResource::class;

    /**
     * @return array<int, Action>
     */
    #[Override]
    protected function getHeaderActions(): array
    {
        return [CorregirRecibo::accion(), ImprimirRecibo::accion()];
    }
}
