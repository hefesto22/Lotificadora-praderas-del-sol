<?php

declare(strict_types=1);

namespace App\Filament\Resources\Ventas\Tables;

use App\Domain\Enums\EstadoVenta;
use App\Domain\ValueObjects\Monto;
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
                    /*
                     * 🔴 `facturacion_id` NO es de adorno. Sin ella, cobrar
                     * desde esta tabla emitia recibo interno en un desarrollo
                     * que factura con CAI — ver ConsumoDeFacturas::facturacionDe().
                     * El dominio ya no depende de esto, pero traerla ahorra una
                     * consulta por cobro y deja el modelo entero.
                     */
                    'proyecto:id,nombre,codigo,facturacion_id',
                    // Solo el titular: es el único cliente que la tabla muestra.
                    'clientes' => fn ($relacion) => $relacion->wherePivot('titular', true),
                ])
                ->withCount('compromisos')
                // `deLotesVivos()`: un lote rescindido puede dejar una cuota
                // con saldo —la pagada a medias no se borra— y sin esto la
                // lista mostraria un saldo distinto al del expediente.
                ->addSelect(['saldo_pendiente' => Cuota::query()
                    ->deLotesVivos()
                    ->selectRaw('COALESCE(SUM(monto - monto_pagado), 0)')
                    ->whereColumn('cuotas.venta_id', 'ventas.id'),
                ])
                /*
                 * Cuántas cuotas vencidas tiene cada expediente, por el mismo
                 * camino que el saldo: una subconsulta correlacionada, no un
                 * método por fila.
                 *
                 * Los filtros son los de `ComoVaElNegocio::vencidoAHoy()` y
                 * los del contador del menú, y tienen que seguir siéndolo:
                 * tres pantallas contando lo mismo con criterios distintos es
                 * peor que no tener ninguna.
                 */
                ->addSelect(['cuotas_vencidas' => Cuota::query()
                    ->vencidas()
                    ->deLotesVivos()
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('cuotas.venta_id', 'ventas.id'),
                ]))
            ->columns([
                /*
                 * ═══ ACÁ ADENTRO YA VIENE EL EXPEDIENTE ═══
                 *
                 * `RPS-2026-0116` **es** el expediente 0116: `numero_contrato`
                 * se arma como código + año + el mismo secuencial que se
                 * guarda en `numero_expediente`, con el mismo relleno de
                 * ceros (ver `ConsumoDeCorrelativos::numeroDeContrato`, que
                 * lo dice con todas las letras: «son el mismo numero»).
                 *
                 * Por eso la columna «Expediente» se fue el 22-ago: repetía
                 * cuatro dígitos que ya estaban tres centímetros a la
                 * izquierda. No se pierde nada — buscar «0116» acá encuentra
                 * el contrato igual, porque la búsqueda es por coincidencia
                 * parcial.
                 */
                TextColumn::make('numero_contrato')
                    ->label('Contrato')
                    ->searchable()
                    ->sortable()
                    ->weight('medium')
                    ->copyable(),

                /*
                 * Sin `wrap()` desde el 22-ago. Un nombre como «MAURICIO
                 * CRUZ» partía en dos renglones y la fila entera pasaba de
                 * ~55 px a 94: con 116 ventas, el triple de scroll para
                 * mostrar lo mismo. Se corta con «…» y el completo sale en el
                 * tooltip, que es lo que hace falta para reconocerlo.
                 */
                TextColumn::make('titular')
                    ->label('Titular')
                    ->getStateUsing(static fn (Venta $record): string => (string) ($record->clientes->first()?->getAttribute('nombre') ?? '—'))
                    ->limit(24)
                    ->tooltip(static fn (Venta $record): ?string => $record->clientes->first()?->getAttribute('nombre')),

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

                /*
                 * 🔴 `state()` y NO `formatStateUsing()`. Con el formatter,
                 * el `?? 'Contado'` era código MUERTO: Filament ni siquiera
                 * llama al callback cuando el valor de la columna es null, así
                 * que las ventas de contado salían con la celda **vacía** y se
                 * leían como un dato que falta. Encontrado el 22-ago mirando
                 * la pantalla, no el código. `state()` corre siempre.
                 */
                TextColumn::make('cuota_mensual')
                    ->label('Cuota')
                    ->state(static fn (Venta $record): string => $record->montoCuotaMensual()?->formateado() ?? 'De contado')
                    ->color(static fn (Venta $record): ?string => $record->montoCuotaMensual() instanceof Monto ? null : 'gray')
                    ->alignEnd()
                    ->toggleable(),

                TextColumn::make('saldo_pendiente')
                    ->label('Saldo')
                    ->formatStateUsing(static fn (mixed $state): string => moneda(is_string($state) || is_int($state) ? $state : '0'))
                    ->alignEnd()
                    ->sortable(),

                /*
                 * ═══ LA COLUMNA QUE CONTESTA «¿A QUIEN HAY QUE LLAMAR?» ═══
                 *
                 * Hasta el 22-ago acá decía «Vigente» en verde, para los 116.
                 * El menú avisaba de 68 expedientes vencidos y esta pantalla
                 * —la que se abre para trabajarlos— no decía CUALES: el que
                 * pagó todo y el que debe cinco cuotas se veían igual.
                 *
                 * Y el estado ya estaba dicho dos veces: las pestañas de
                 * arriba filtran por Vigente / Liquidada / Rescindida /
                 * Anulada, con su conteo al lado.
                 *
                 * Así que la columna cambió de pregunta. Sobre un expediente
                 * VIGENTE dice cómo va el cobro; sobre uno cerrado sigue
                 * diciendo su estado, porque ahí «al día» no significa nada.
                 */
                TextColumn::make('cuotas_vencidas')
                    ->label('Cobro')
                    ->badge()
                    ->state(static function (Venta $record): string {
                        $estado = $record->getAttribute('estado');

                        if ($estado !== EstadoVenta::Vigente) {
                            return $estado instanceof EstadoVenta ? $estado->etiqueta() : '—';
                        }

                        $vencidas = (int) $record->getAttribute('cuotas_vencidas');

                        return match (true) {
                            $vencidas === 0 => 'Al día',
                            $vencidas === 1 => '1 vencida',
                            default         => "{$vencidas} vencidas",
                        };
                    })
                    ->color(static function (Venta $record): string {
                        $estado = $record->getAttribute('estado');

                        if ($estado !== EstadoVenta::Vigente) {
                            return $estado instanceof EstadoVenta ? $estado->color() : 'gray';
                        }

                        return (int) $record->getAttribute('cuotas_vencidas') === 0 ? 'success' : 'danger';
                    })
                    ->tooltip(static function (Venta $record): ?string {
                        $vencidas = (int) $record->getAttribute('cuotas_vencidas');

                        return $record->getAttribute('estado') === EstadoVenta::Vigente && $vencidas > 0
                            ? 'Cuotas con fecha pasada que todavía deben algo. R2: el atraso no genera cargo.'
                            : null;
                    })
                    /*
                     * Ordena por la subconsulta, no por el texto: `state()`
                     * arma la etiqueta en PHP y Postgres no la conoce. Lo más
                     * atrasado primero, que es para lo que se ordena esto.
                     *
                     * 🔴 `$direction` EN INGLES, y no es un descuido. Filament
                     * inyecta los argumentos de sus closures POR NOMBRE, no por
                     * posición: con `$direccion` el panel tira un 500 —
                     * «[$direccion] was unresolvable»— apenas alguien hace clic
                     * en el encabezado. Los nombres de estos parámetros son API,
                     * no se traducen.
                     *
                     * Y el ternario tampoco sobra: `orderBy()` de Laravel 13
                     * pide un `'asc'|'desc'` literal, mientras que Filament
                     * tipa esto como `string` a secas. Sin normalizar, el
                     * análisis estático se planta acá.
                     */
                    ->sortable(query: static fn (Builder $query, string $direction): Builder => $query
                        ->orderBy('cuotas_vencidas', $direction === 'desc' ? 'desc' : 'asc')),

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
                /*
                 * `iconButton()` solo ACA: el mismo `CobrarUnPago::accion()`
                 * lo usan el expediente y el plano, y ahí va con su texto. En
                 * una tabla de 25 filas, «Registrar un pago» escrito 25 veces
                 * gasta un cuarto del ancho para decir siempre lo mismo — el
                 * texto pasa al tooltip y el clic es el mismo.
                 */
                CobrarUnPago::accion()->iconButton(),

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
