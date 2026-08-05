<?php

declare(strict_types=1);

namespace App\Filament\Resources\Ventas\Pages;

use App\Domain\Enums\EstadoVenta;
use App\Filament\Resources\Ventas\VentaResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Override;

class ListVentas extends ListRecords
{
    #[Override]
    protected static string $resource = VentaResource::class;

    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Nueva venta'),
        ];
    }

    /**
     * Pestañas por estado, con su contador.
     *
     * §9.E6: el badge usa EXACTAMENTE la misma condición que la pestaña. Un
     * contador que no comparte el scoping de su listado filtra información
     * —dice cuántas hay de algo que el usuario no puede ver—.
     *
     * ⚠️ El `Tab` de las pestañas de listado es
     * `Filament\Schemas\Components\Tabs\Tab`, **el mismo de los tabs del
     * formulario**. En Filament v3 vivía en
     * `Filament\Resources\Pages\ListRecords\Tab`, y esa ruta ya no existe:
     * el trait `HasTabs` del propio Filament importa la de Schemas.
     *
     * @return array<string, Tab>
     */
    #[Override]
    public function getTabs(): array
    {
        $tabs = [
            'todas' => Tab::make('Todas'),
        ];

        foreach (EstadoVenta::cases() as $estado) {
            // Borrador no se muestra: hoy nada lo produce (R5, la venta nace
            // vigente cuando la prima está pagada). El día que exista una
            // cotización, se saca esta línea.
            if ($estado === EstadoVenta::Borrador) {
                continue;
            }

            $tabs[$estado->value] = Tab::make($estado->etiqueta())
                ->modifyQueryUsing(static fn (Builder $query): Builder => $query->where('estado', $estado->value))
                ->badge(fn (): int => VentaResource::getEloquentQuery()->where('estado', $estado->value)->count())
                ->badgeColor($estado->color());
        }

        return $tabs;
    }
}
