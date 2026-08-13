<?php

declare(strict_types=1);

namespace App\Filament\Resources\Ventas\Tables;

use App\Domain\Enums\EstadoVenta;
use App\Filament\Support\CobrarUnPago;
use App\Models\Cuota;
use App\Models\Venta;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

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

                /*
                 * Oculta por defecto: la mayoría de las ventas las cierra la
                 * lotificadora y una columna casi vacía solo roba ancho. Quien
                 * necesite ver quién vendió la prende, y ahí sí ordena y filtra.
                 */
                TextColumn::make('vendedor.nombre')
                    ->label('Vendido por')
                    ->placeholder('La lotificadora')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->wrap(),

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

                /*
                 * ═══ EL FILTRO QUE HACE POSIBLE EL LINK DESDE EL CLIENTE ═══
                 *
                 * `ClientesTable` abre esta pantalla con
                 * `?filters[cliente][value]=…`. El nombre `cliente` es el
                 * contrato entre las dos pantallas y está escrito una sola
                 * vez, en `ListadoDelCliente::FILTRO`.
                 *
                 * Filtra por CUALQUIERA de los que firman, no solo por el
                 * titular: la columna «Titular» muestra a uno, pero un lote
                 * comprado entre marido y mujer es de los dos (R8).
                 *
                 * El `withoutGlobalScopes` tampoco es decorativo. Un cliente
                 * archivado sigue teniendo sus ventas: sin esa línea el
                 * contador de su ficha diría «1» y esta pantalla mostraría
                 * cero — que es exactamente el contador mentiroso del §9.E6.
                 *
                 * ⚠️ Se borró por accidente el 10-ago-2026 al agregar el botón
                 * de cobrar, y con él se cayó en silencio el atajo desde la
                 * ficha del cliente: la pantalla se abría ENTERA sin avisar de
                 * nada. Lo agarró `QueTieneElClienteTest`, que abre este
                 * listado con el filtro puesto y cuenta las filas.
                 */
                SelectFilter::make('cliente')
                    ->label('Cliente')
                    ->relationship(
                        'clientes',
                        'nombre',
                        static fn (Builder $query): Builder => $query->withoutGlobalScopes([SoftDeletingScope::class]),
                    )
                    ->searchable(),
            ])
            ->recordActions([
                /*
                 * ═══ EL MODAL SE ABRE ACA, SIN SACAR A NADIE DE LA PANTALLA ═══
                 *
                 * Hasta el 10-ago-2026 esto era un link a
                 * `…/ventas/7?action=cobrar`: el modal existía solo en el
                 * expediente, así que cobrar desde la lista era navegar. Se
                 * probó y Mauricio lo bajó en el acto — «acá no debe de
                 * redirigirme a la vista de ventas, siempre en la vista de
                 * cliente ahí debe de abrirse el modal».
                 *
                 * Tenía razón, y no era una preferencia visual: esta misma
                 * tabla es la que muestra la pestaña Ventas de la ficha del
                 * cliente —el relation manager la reusa vía
                 * `$relatedResource`— así que el link sacaba a quien atiende
                 * del cliente que tenía enfrente para llevarlo a otra pantalla.
                 *
                 * Ahora el modal se define una sola vez, en `CobrarUnPago`, y
                 * las tres pantallas abren el MISMO. Cero código de dinero
                 * duplicado, y quien cobra se queda donde estaba.
                 */
                CobrarUnPago::accion(),

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
