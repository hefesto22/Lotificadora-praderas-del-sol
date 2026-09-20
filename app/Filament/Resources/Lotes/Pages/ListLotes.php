<?php

declare(strict_types=1);

namespace App\Filament\Resources\Lotes\Pages;

use App\Domain\Enums\EstadoLote;
use App\Filament\Resources\Lotes\LoteResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Override;

class ListLotes extends ListRecords
{
    #[Override]
    protected static string $resource = LoteResource::class;

    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    /**
     * Pestañas por estado, con "Disponibles" de entrada.
     *
     * La pregunta que se hace todos los días es "¿qué hay libre?", no
     * "mostrame los 2,000 lotes". Arrancar en Disponibles ahorra un filtro
     * en cada visita, y los contadores dicen de un vistazo cómo va el
     * proyecto sin abrir un reporte.
     *
     * §9.E.6: los contadores usan la MISMA query que la pestaña. Un badge
     * sin el mismo filtro que la tabla informa de más — el día que exista
     * el rol receptor con visibilidad recortada, un contador sin scope le
     * estaría filtrando lo que no le toca.
     *
     * 🔴 Y «la misma» es `LoteResource::getEloquentQuery()`, no
     * `Lote::query()` (18-sep-2026). Este docblock ya lo decía y el código
     * no lo cumplía: desde que el interruptor de la barra recorta el listado,
     * las pestañas seguían contando los lotes de TODOS los proyectos encima
     * de una tabla que mostraba los de uno.
     *
     * @return array<string, Tab>
     */
    #[Override]
    public function getTabs(): array
    {
        $pestanas = ['todos' => Tab::make('Todos')->badge(fn (): int => LoteResource::getEloquentQuery()->count())];

        foreach (EstadoLote::cases() as $estado) {
            $pestanas[$estado->value] = Tab::make($estado->etiquetaInterna())
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('estado', $estado->value))
                ->badge(fn (): int => LoteResource::getEloquentQuery()->where('estado', $estado->value)->count())
                ->badgeColor($estado->color());
        }

        // Disponibles primero: es la pregunta del 90% de las visitas.
        return [
            'disponible' => $pestanas['disponible'],
            'apartado'   => $pestanas['apartado'],
            'vendido'    => $pestanas['vendido'],
            'cancelado'  => $pestanas['cancelado'],
            'todos'      => $pestanas['todos'],
        ];
    }

    #[Override]
    public function getDefaultActiveTab(): string|int|null
    {
        return 'disponible';
    }
}
