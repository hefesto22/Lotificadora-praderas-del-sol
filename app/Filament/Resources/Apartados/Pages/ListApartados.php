<?php

declare(strict_types=1);

namespace App\Filament\Resources\Apartados\Pages;

use App\Filament\Resources\Apartados\ApartadoResource;
use Filament\Resources\Pages\ListRecords;
use Override;

/**
 * Sin botón de crear: un apartado nace en el plano.
 *
 * Apartar necesita un lote disponible, un cliente y la seña con su forma de
 * pago — y ese trámite ya existe, con el plano enfrente para elegir cuál.
 * Un formulario genérico acá dejaría apartar un lote vendido.
 */
class ListApartados extends ListRecords
{
    #[Override]
    protected static string $resource = ApartadoResource::class;

    /**
     * @return array<int, mixed>
     */
    #[Override]
    protected function getHeaderActions(): array
    {
        return [];
    }
}
