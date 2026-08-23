<?php

declare(strict_types=1);

namespace App\Filament\Resources\Recibos\Pages;

use App\Filament\Resources\Recibos\ReciboResource;
use Filament\Resources\Pages\ListRecords;
use Override;

/**
 * Sin botón de crear: un recibo nace cobrando, no llenando un formulario.
 */
class ListRecibos extends ListRecords
{
    #[Override]
    protected static string $resource = ReciboResource::class;

    /**
     * Sin migas de pan, como el plano (23-ago-2026).
     *
     * «Recibos › Listado» arriba del título «Recibos» es la misma palabra dos
     * veces y una ruta de un solo salto. Para volver está el menú de la
     * izquierda, que además dice dónde estás parado sin gastar un renglón.
     *
     * @return array<string>
     */
    #[Override]
    public function getBreadcrumbs(): array
    {
        return [];
    }

    /**
     * @return array<int, mixed>
     */
    #[Override]
    protected function getHeaderActions(): array
    {
        return [];
    }
}
