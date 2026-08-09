<?php

declare(strict_types=1);

namespace App\Filament\Resources\Prospectos\Pages;

use App\Filament\Resources\Prospectos\ProspectoResource;
use Filament\Resources\Pages\ListRecords;
use Override;

/**
 * Sin botón de crear: un prospecto nace en el plano público.
 *
 * Cargarlos a mano sería inventar la traza de por dónde llegó un cliente,
 * que es justamente el número que esta pantalla existe para medir. Si alguien
 * llama por teléfono y hay que anotarlo, eso es un cliente o una nota — no un
 * prospecto del plano.
 */
class ListProspectos extends ListRecords
{
    #[Override]
    protected static string $resource = ProspectoResource::class;

    /**
     * @return array<int, mixed>
     */
    #[Override]
    protected function getHeaderActions(): array
    {
        return [];
    }
}
