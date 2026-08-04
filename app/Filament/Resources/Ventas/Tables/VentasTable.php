<?php

declare(strict_types=1);

namespace App\Filament\Resources\Ventas\Tables;

use App\Domain\Enums\EstadoVenta;
use App\Models\Cuota;
use App\Models\Venta;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * §10.7: columnas explícitas, eager loading, filtros con la misma fuente
 * que el scoping, defaultSort y paginación.
 *
 * ═══ EL SALDO SE TRAE CON UNA SUBCONSULTA, NO CON withSum ═══
 *
 * El titular y el conteo de lotes se resuelven con eager loading; el saldo
 * pendiente, con una subconsulta escrita a mano contra `cuotas`. Llamar a
 * `$venta->saldoPendiente()` por fila sería un N+1 en la pantalla que más
 * se consulta (§9.D — prioridad 🔴 del §4.L4).
 */
class VentasTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with([
                    'proyecto:id,nombre,codigo',
                    // Solo el titular: es el único cliente que la tabla muestra.
                    'clientes' => fn ($relacion) => $relacion->wherePivot('titular', true),
                ])
                ->withCount('compromisos')
                ->addSelect(['saldo_pendiente' => Cuota::query()
                    ->selectRaw('COALESCE(SUM(monto - monto_pagado), 0)')
                    ->whereColumn('cuotas.venta_id', 'ventas.id'),
                ]))
            ->columns([
                TextColumn::make('numero_contrato')
                    ->label('Contrato')
                    ->searchable()
                    ->sortable()
                    ->weight('medium')
                    ->copyable(),

                TextColumn::make('numero_expediente')
                    ->label('Expediente')
                    ->formatStateUsing(fn (?int $state): string => $state === null ? '—' : str_pad((string) $state, 4, '0', STR_PAD_LEFT))
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('titular')
                    ->label('Titular')
                    ->getStateUsing(static fn (Venta $record): string => (string) ($record->clientes->first()?->getAttribute('nombre') ?? '—'))
                    ->wrap(),

                TextColumn::make('compromisos_count')
                    ->label('Lotes')
                    ->alignCenter()
                    ->sortable(),

                TextColumn::make('valor_total')
                    ->label('Valor')
                    ->formatStateUsing(static fn (Venta $record): string => $record->montoValorTotal()->formateado())
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('cuota_mensual')
                    ->label('Cuota')
                    ->formatStateUsing(static fn (Venta $record): string => $record->montoCuotaMensual()?->formateado() ?? 'Contado')
                    ->alignEnd()
                    ->toggleable(),

                TextColumn::make('saldo_pendiente')
                    ->label('Saldo')
                    ->formatStateUsing(static fn (mixed $state): string => moneda(is_string($state) || is_int($state) ? $state : '0'))
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('estado')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(static fn (EstadoVenta $state): string => $state->etiqueta())
                    ->color(static fn (EstadoVenta $state): string => $state->color())
                    ->sortable(),

                TextColumn::make('fecha_contrato')
                    ->label('Firmado')
                    ->date('d/m/Y')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('estado')
                    ->label('Estado')
                    ->options(static fn (): array => collect(EstadoVenta::cases())
                        ->mapWithKeys(static fn (EstadoVenta $estado): array => [$estado->value => $estado->etiqueta()])
                        ->all()),

                SelectFilter::make('proyecto')
                    ->label('Proyecto')
                    ->relationship('proyecto', 'nombre')
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make(),
                ]),
            ])
            // Lo último firmado primero: es lo que la administradora busca.
            ->defaultSort('fecha_contrato', 'desc')
            ->paginationPageOptions([25, 50, 100])
            ->emptyStateHeading('Todavía no hay ventas')
            ->emptyStateDescription('Registrá la primera venta cuando el cliente pague la prima completa.')
            ->emptyStateIcon('heroicon-o-document-text');
    }
}
