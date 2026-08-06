<?php

declare(strict_types=1);

namespace App\Filament\Resources\Ventas\RelationManagers;

use App\Models\Cuota;
use BackedEnum;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Override;

/**
 * El plan de cuotas del expediente, cuota por cuota.
 *
 * ═══ POR QUE SE AGRUPA POR LOTE ═══
 *
 * Desde el 5-ago-2026 cada lote del contrato tiene su propio plazo, así que
 * el plan dejó de ser uno solo: el lote a 12 meses termina de pagarse mientras
 * el de 48 sigue vivo. Cada cuota apunta a su compromiso, y la pregunta que se
 * hace en ventanilla —«¿cuánto le falta a ESE lote?»— solo se puede contestar
 * mirándolas separadas.
 *
 * La suma de lo que se paga cada mes está arriba, en la ficha: es la escalera.
 *
 * ═══ SOLO SE MIRA ═══
 *
 * Sin botones de crear, editar ni borrar. El plan es un snapshot inmutable
 * (§9.D6) y lo mueve el Service de pagos, no un formulario. Que no aparezcan
 * no depende de esta clase: lo decide `CuotaPolicy`, que devuelve `false` a
 * todas las escrituras.
 */
class CuotasRelationManager extends RelationManager
{
    #[Override]
    protected static string $relationship = 'cuotas';

    #[Override]
    protected static ?string $title = 'Plan de cuotas';

    #[Override]
    protected static string|BackedEnum|null $icon = 'heroicon-o-calendar-days';

    #[Override]
    public function form(Schema $schema): Schema
    {
        // No se edita ninguna, pero el contrato del padre pide el método.
        return $schema->components([]);
    }

    #[Override]
    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('numero')
            ->modifyQueryUsing(static fn (Builder $query): Builder => $query->with('compromiso.lote'))
            ->columns([
                TextColumn::make('compromiso.lote.codigo')
                    ->label('Lote')
                    ->badge()
                    ->color('gray')
                    ->sortable()
                    ->placeholder('—'),

                TextColumn::make('numero')
                    ->label('Cuota')
                    ->formatStateUsing(static fn (Cuota $record): string => sprintf(
                        '%d de %d',
                        (int) $record->getAttribute('numero'),
                        (int) ($record->compromiso?->getAttribute('plazo_meses') ?? 0),
                    ))
                    ->sortable(),

                TextColumn::make('fecha_vencimiento')
                    ->label('Vence')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('monto')
                    ->label('Monto')
                    ->alignEnd()
                    ->formatStateUsing(static fn (Cuota $record): string => $record->montoTotal()->formateado()),

                TextColumn::make('monto_pagado')
                    ->label('Pagado')
                    ->alignEnd()
                    ->color(static fn (Cuota $record): string => $record->montoPagado()->esCero() ? 'gray' : 'success')
                    ->formatStateUsing(static fn (Cuota $record): string => $record->montoPagado()->esCero()
                        ? '—'
                        : $record->montoPagado()->formateado()),

                TextColumn::make('saldo')
                    ->label('Falta')
                    ->alignEnd()
                    ->weight('bold')
                    ->state(static fn (Cuota $record): string => $record->saldo()->formateado()),

                /*
                 * `pagada` y `vencida` NO son columnas: se calculan de
                 * `monto_pagado` y de la fecha (§9.D5). Guardarlas obligaría a
                 * una tarea nocturna que las recalcule, y esa tarea falla justo
                 * el día que el cliente llega a pagar.
                 */
                TextColumn::make('estado')
                    ->label('Estado')
                    ->badge()
                    ->state(static fn (Cuota $record): string => match (true) {
                        $record->estaPagada()  => 'Pagada',
                        $record->estaVencida() => sprintf('Vencida (%d días)', $record->diasDeAtraso()),
                        default                => 'Pendiente',
                    })
                    ->color(static fn (Cuota $record): string => match (true) {
                        $record->estaPagada()  => 'success',
                        $record->estaVencida() => 'danger',
                        default                => 'gray',
                    }),
            ])
            ->filters([
                Filter::make('pendientes')
                    ->label('Solo lo que falta')
                    ->query(static fn (Builder $query): Builder => $query->whereColumn('monto_pagado', '<', 'monto')),

                Filter::make('vencidas')
                    ->label('Solo las vencidas')
                    ->query(static fn (Builder $query): Builder => $query
                        ->whereColumn('monto_pagado', '<', 'monto')
                        ->whereDate('fecha_vencimiento', '<', today())),
            ])
            ->defaultSort('fecha_vencimiento')
            ->paginationPageOptions([12, 24, 48, 100])
            ->emptyStateHeading('Esta venta no tiene cuotas')
            ->emptyStateDescription('Se pagó de contado: la prima cubrió el valor completo.')
            ->emptyStateIcon('heroicon-o-banknotes');
    }

    /**
     * Nunca de solo lectura por ser una página de vista: acá no hay nada que
     * escribir de todos modos, pero el default de Filament escondería hasta
     * los filtros.
     */
    #[Override]
    public function isReadOnly(): bool
    {
        return false;
    }
}
