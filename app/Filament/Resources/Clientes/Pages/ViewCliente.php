<?php

declare(strict_types=1);

namespace App\Filament\Resources\Clientes\Pages;

use App\Filament\Resources\Clientes\ClienteResource;
use App\Filament\Support\ListadoDelCliente;
use App\Models\Cliente;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Override;

/**
 * ═══ LOS TRES BOTONES DE ARRIBA ═══
 *
 * Abren la pantalla grande —Ventas, Apartados o Recibos— ya filtrada por este
 * cliente. Sirven cuando alguien tiene diez lotes y las pestañas de abajo se
 * quedan cortas: allá está el listado entero, con sus solapas por estado y
 * todo el ancho de la pantalla.
 *
 * ═══ NO LLEVAN EL NUMERO, Y ES A PROPOSITO ═══
 *
 * El número vive en la pestaña de cada tabla, tres centímetros más abajo.
 * Decirlo dos veces en la misma pantalla es una de las dos copias que algún
 * día no van a coincidir.
 *
 * Van en gris: el botón de color es «Editar», que es la acción de ESTA
 * pantalla; estos tres llevan a otro lado.
 */
class ViewCliente extends ViewRecord
{
    #[Override]
    protected static string $resource = ClienteResource::class;

    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            Action::make('ventas')
                ->label('Ventas')
                ->icon(Heroicon::OutlinedDocumentText)
                ->color('gray')
                ->url(fn (): string => ListadoDelCliente::ventas($this->cliente()))
                ->visible(fn (): bool => ListadoDelCliente::puedeVerVentas()),

            Action::make('apartados')
                ->label('Apartados')
                ->icon(Heroicon::OutlinedBookmark)
                ->color('gray')
                ->url(fn (): string => ListadoDelCliente::apartados($this->cliente()))
                ->visible(fn (): bool => ListadoDelCliente::puedeVerApartados()),

            Action::make('recibos')
                ->label('Recibos')
                ->icon(Heroicon::OutlinedReceiptPercent)
                ->color('gray')
                ->url(fn (): string => ListadoDelCliente::recibos($this->cliente()))
                ->visible(fn (): bool => ListadoDelCliente::puedeVerRecibos()),

            EditAction::make(),
        ];
    }

    private function cliente(): Cliente
    {
        /** @var Cliente $cliente */
        $cliente = $this->getRecord();

        return $cliente;
    }
}
