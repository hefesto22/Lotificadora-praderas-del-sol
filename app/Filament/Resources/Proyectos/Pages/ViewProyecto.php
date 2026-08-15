<?php

declare(strict_types=1);

namespace App\Filament\Resources\Proyectos\Pages;

use App\Domain\Lotes\FijacionDePrecios;
use App\Domain\ValueObjects\Monto;
use App\Filament\Resources\Proyectos\ProyectoResource;
use App\Filament\Schemas\Components\PrecioPorAreaField;
use App\Models\Bloque;
use App\Models\Proyecto;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Override;

/**
 * La ficha del proyecto, con sus bloques y sus lotes adentro.
 *
 * Bloques y Lotes salieron del menú principal (5-ago-2026) y viven acá como
 * pestañas: no son entidades sueltas, no existe un bloque sin proyecto, y
 * darlos de alta desde afuera obligaba a elegir el proyecto otra vez.
 *
 * El encabezado quedó en tres botones y no en cinco. Los enlaces a Lotes y
 * Bloques desaparecieron porque ya no hacen falta: las pestañas de abajo
 * hacen lo mismo sin sacarte del proyecto.
 */
class ViewProyecto extends ViewRecord
{
    #[Override]
    protected static string $resource = ProyectoResource::class;

    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            Action::make('plano')
                ->label('Ver plano')
                ->icon(Heroicon::OutlinedMap)
                ->url(fn (): string => ProyectoResource::getUrl('plano', ['record' => $this->getRecord()])),

            $this->fijarPrecio(),

            EditAction::make(),
        ];
    }

    /**
     * La carga rápida que pide la R15.
     *
     * El importador de DXF deja los 301 lotes dibujados, con su área y su
     * número, pero **sin precio**. Cargarlo lote por lote son 301
     * formularios; en la práctica el precio es uno por bloque, o uno para
     * todo el proyecto. Esto lo convierte en un formulario de dos campos.
     *
     * Los lotes vendidos no se tocan —el §8.2 los congela— y la
     * notificación dice exactamente cuáles quedaron afuera.
     */
    private function fijarPrecio(): Action
    {
        return Action::make('fijar_precio')
            ->label(fn (Proyecto $record): string => 'Fijar precio '.$record->unidadDeArea()->porUnidad())
            ->icon(Heroicon::OutlinedBanknotes)
            ->color('warning')
            ->modalHeading(fn (Proyecto $record): string => 'Fijar el precio '.$record->unidadDeArea()->porUnidad())
            ->modalDescription('Se aplica a todos los lotes elegidos y recalcula su valor. Los lotes vendidos no se tocan.')
            ->modalSubmitActionLabel('Aplicar')
            ->schema([
                Select::make('bloque_id')
                    ->label('Alcance')
                    ->placeholder('Todo el proyecto')
                    ->options(fn (Proyecto $record): array => Bloque::query()
                        ->where('proyecto_id', $record->getKey())
                        ->orderBy('orden')
                        ->orderBy('nombre')
                        ->pluck('nombre', 'id')
                        ->all())
                    ->helperText('Dejalo vacío para aplicar a todos los bloques.'),

                PrecioPorAreaField::make('precio_vara')
                    ->label(fn (Proyecto $record): string => 'Precio '.$record->unidadDeArea()->porUnidad())
                    ->required(),
            ])
            ->action(function (array $data, Proyecto $record): void {
                $bloque = filled($data['bloque_id'] ?? null)
                    ? Bloque::query()->find($data['bloque_id'])
                    : null;

                $resultado = app(FijacionDePrecios::class)->fijar(
                    $record,
                    $bloque instanceof Bloque ? $bloque : null,
                    new Monto((string) $data['precio_vara']),
                );

                $omitidos = $resultado['omitidos'];

                Notification::make()
                    ->title($resultado['aplicados'].' lote(s) actualizados')
                    ->body($omitidos === []
                        ? 'Todos los lotes del alcance elegido quedaron con el precio nuevo.'
                        : 'Quedaron sin cambiar por estar vendidos: '.implode(', ', $omitidos))
                    ->success()
                    ->send();
            });
    }
}
