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
     * @return array<int, mixed>
     */
    #[Override]
    protected function getHeaderActions(): array
    {
        return [];
    }
}
