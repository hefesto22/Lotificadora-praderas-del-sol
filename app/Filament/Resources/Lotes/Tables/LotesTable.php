<?php

declare(strict_types=1);

namespace App\Filament\Resources\Lotes\Tables;

use App\Domain\Enums\EstadoLote;
use App\Models\Bloque;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * §10.7: columnas explícitas, filtros con la misma fuente que el scoping,
 * defaultSort y paginación 25/50/100.
 *
 * Pensada para diez proyectos de ~200 lotes, no para uno de 54. Con ese
 * volumen la tabla plana solo funciona si el orden es el que la persona
 * espera y si los filtros dicen de qué proyecto es cada bloque.
 */
class LotesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('codigo')
                    ->label('Código')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->copyMessage('Código copiado')
                    ->weight('medium'),

                /*
                | Visible por defecto, no escondida detrás del selector de
                | columnas: con diez proyectos, una fila sin proyecto no
                | dice nada. Se puede ocultar a mano cuando se filtra por
                | un solo proyecto.
                */
                TextColumn::make('proyecto.nombre')
                    ->label('Proyecto')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('bloque.nombre')
                    ->label('Bloque')
                    ->badge()
                    ->color('gray')
                    ->sortable(),

                TextColumn::make('numero')
                    ->label('Lote')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('area_varas')
                    ->label('Área')
                    ->suffix(' varas²')
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('precio_vara')
                    ->label('Precio/vara²')
                    ->prefix('L ')
                    ->alignEnd()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('valor')
                    ->label('Valor')
                    ->prefix('L ')
                    ->alignEnd()
                    ->sortable()
                    ->weight('medium'),

                TextColumn::make('estado')
                    ->label('Estado')
                    ->badge()
                    ->color(fn (EstadoLote $state): string => $state->color())
                    ->formatStateUsing(fn (EstadoLote $state): string => $state->etiqueta())
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('proyecto_id')
                    ->label('Proyecto')
                    ->relationship('proyecto', 'nombre')
                    ->searchable()
                    ->preload(),

                /*
                | La etiqueta lleva el código del proyecto adelante.
                |
                | Con diez proyectos hay diez bloques llamados "A" y el
                | desplegable serían diez opciones idénticas: elegir a
                | ciegas. Con el prefijo se lee "RPS — A" y "LMC — A".
                |
                | Se resuelve así y no encadenando el filtro al de Proyecto
                | porque leer el estado de otro filtro depende de API interna
                | de Filament, y esto funciona igual aunque no se haya
                | elegido proyecto todavía.
                */
                SelectFilter::make('bloque_id')
                    ->label('Bloque')
                    ->relationship(
                        'bloque',
                        'nombre',
                        fn (Builder $query): Builder => $query
                            ->with('proyecto:id,codigo')
                            ->orderBy('proyecto_id')
                            ->orderBy('nombre')
                    )
                    ->getOptionLabelFromRecordUsing(
                        fn (Bloque $record): string => $record->proyecto?->getAttribute('codigo').' — '.$record->getAttribute('nombre')
                    )
                    ->searchable()
                    ->preload(),

                SelectFilter::make('estado')
                    ->label('Estado')
                    ->options(fn (): array => collect(EstadoLote::cases())
                        ->mapWithKeys(fn (EstadoLote $estado): array => [$estado->value => $estado->etiqueta()])
                        ->all())
                    ->multiple(),
            ])
            /*
            | Los filtros sobreviven a salir de la pantalla y volver. Quien
            | trabaja un bloque entero no tiene que re-filtrar en cada visita.
            */
            ->persistFiltersInSession()
            ->persistSortInSession()
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make(),
                    EditAction::make(),
                    DeleteAction::make(),
                ]),
            ])
            /*
            | Ordenar por `codigo` y no por `numero`.
            |
            | `numero` es texto: ordenaba 1, 10, 11, 12… y el lote 2 caía
            | después del 19. El código lleva el número con relleno a 3
            | dígitos (RPS-B-002), así que su orden alfabético ES el orden
            | correcto —proyecto, bloque, número— con una sola columna
            | indexada y sin expresiones en el ORDER BY.
            */
            ->defaultSort('codigo')
            ->paginationPageOptions([25, 50, 100])
            ->emptyStateHeading('Todavía no hay lotes')
            ->emptyStateDescription('Cargá el primer lote de un bloque para empezar a vender.')
            ->emptyStateIcon('heroicon-o-squares-2x2');
    }
}
