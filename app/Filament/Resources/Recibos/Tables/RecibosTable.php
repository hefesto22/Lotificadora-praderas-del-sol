<?php

declare(strict_types=1);

namespace App\Filament\Resources\Recibos\Tables;

use App\Domain\Enums\ConceptoDeRecibo;
use App\Domain\Enums\FormaDePago;
use App\Domain\Exceptions\GrupoOlympoException;
use App\Domain\Pagos\RegistroDePagos;
use App\Filament\Support\BuscarNombre;
use App\Filament\Support\CorregirRecibo;
use App\Filament\Support\ImprimirRecibo;
use App\Models\Recibo;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * Lo cobrado, del más reciente al más viejo.
 *
 * La búsqueda es por NÚMERO porque es lo único que trae quien llega con el
 * papel en la mano. Por eso el folio es la primera columna y va en negrita:
 * es el dato con el que se compara.
 */
class RecibosTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(static fn (Builder $query): Builder => $query->with([
                'cliente', 'venta', 'compromiso.lote', 'aplicaciones.cuota.compromiso.lote',
            ])->withCount('impresiones'))
            ->columns([
                /*
                 * El folio interno, que es el numero de la caja (R12) y el
                 * que trae quien llega con el papel de siempre.
                 *
                 * Desde el 14-ago-2026 un documento puede llevar DOS numeros
                 * —este y el de la factura del SAR— y por eso el de la factura
                 * baja como descripcion en vez de ocupar otra columna: quien
                 * mira esta lista busca por el folio, y ver dieciseis digitos
                 * arriba en la primera columna le cambiaria el punto de
                 * referencia a todo el mundo.
                 */
                /*
                 * 🔴 «ANULADO» vive ACA desde el 23-ago-2026, y antes era una
                 * columna «Estado» propia.
                 *
                 * Un recibo anulado NO se esconde de la lista: su número sigue
                 * en la serie y el papel sigue en la mano de alguien. Buscar el
                 * 000123 y no encontrarlo sería peor que verlo tachado. Eso no
                 * cambió — cambió DONDE se dice.
                 *
                 * La columna estaba VACIA en casi todas las filas: hoy hay 215
                 * recibos y cero anulados, así que era un encabezado y una
                 * franja de ancho diciendo nada 215 veces para decir algo una.
                 * Acá ocupa cero cuando no aplica, y cuando aplica se lee
                 * mejor: el folio en rojo con la palabra pegada abajo, que es
                 * como se mira un talonario.
                 *
                 * El motivo va en la misma línea porque es lo único que
                 * contesta la pregunta que sigue —«¿y por qué?»— sin abrir la
                 * ficha.
                 */
                TextColumn::make('numero')
                    ->label('Recibo')
                    ->weight('bold')
                    ->searchable()
                    ->sortable()
                    ->formatStateUsing(static fn (Recibo $record): string => $record->folio())
                    ->color(static fn (Recibo $record): ?string => $record->estaAnulado() ? 'danger' : null)
                    ->description(static fn (Recibo $record): ?string => self::debajoDelFolio($record)),

                /*
                 * Buscable aparte y no adentro de la columna de arriba: quien
                 * llega con una factura en la mano trae los dieciseis digitos,
                 * y `numero` es un entero — buscar «000-001-01-00000004» ahi
                 * no encuentra nada.
                 */
                TextColumn::make('numero_factura')
                    ->label('Factura')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('—'),

                TextColumn::make('fecha')
                    ->label('Fecha')
                    ->date('d/m/Y')
                    ->sortable(),

                /*
                 * Dice lo mismo que el papel, no lo que dice la FK.
                 *
                 * Desde el 12-ago-2026 un recibo puede salir a nombre de un
                 * representado, y en ese caso `cliente.nombre` —el titular del
                 * expediente— ya no es lo que está impreso. La segunda línea
                 * conserva de qué contrato salió: sin ella queda un cobro a
                 * nombre de alguien que no aparece en ningún expediente.
                 */
                TextColumn::make('cliente.nombre')
                    ->label('Recibí de')
                    ->state(static fn (Recibo $record): string => $record->nombreDelPapel())
                    ->description(static fn (Recibo $record): ?string => $record->esANombreDeOtro()
                        ? 'por cuenta de '.($record->cliente?->getAttribute('nombre') ?? '—')
                        : null)
                    // Sin acentos: ver BuscarNombre.
                    ->searchable(query: BuscarNombre::delCliente())
                    /*
                     * 🔴 SIN `wrap()` desde el 23-ago-2026. Con él, «ELVA MARINA
                     * ORTIS SANTAMARIA» se partía en CUATRO renglones y esa fila
                     * sola medía lo que tres: en la pantalla entraban ocho
                     * recibos. Los nombres de esta cartera son de cuatro
                     * palabras casi siempre, así que no era el caso raro.
                     *
                     * `limit()` y no un ancho fijo: el nombre completo sigue
                     * estando —el tooltip lo muestra entero— y la lista vuelve a
                     * ser una lista.
                     */
                    ->limit(26)
                    ->tooltip(static fn (Recibo $record): ?string => mb_strlen($record->nombreDelPapel()) > 26
                        ? $record->nombreDelPapel()
                        : null)
                    ->placeholder('—'),

                TextColumn::make('venta.numero_contrato')
                    ->label('Contrato')
                    ->searchable()
                    ->placeholder('—'),

                /*
                 * Un cobro de varios lotes deja `compromiso_id` en NULL —ese
                 * recibo no es de un lote—, así que la columna sale de las
                 * cuotas que tocó: un badge por lote.
                 */
                TextColumn::make('lotes')
                    ->label('Lote')
                    ->badge()
                    ->color('gray')
                    ->state(static fn (Recibo $record): array => $record->codigosDeLotes())
                    ->placeholder('—'),

                TextColumn::make('concepto')
                    ->label('Concepto')
                    ->badge()
                    ->formatStateUsing(static fn (Recibo $record): string => $record->getAttribute('concepto') instanceof ConceptoDeRecibo
                        ? $record->getAttribute('concepto')->etiqueta()
                        : '—'),

                TextColumn::make('forma_pago')
                    ->label('Forma')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(static fn (Recibo $record): string => $record->getAttribute('forma_pago') instanceof FormaDePago
                        ? $record->getAttribute('forma_pago')->etiqueta()
                        : '—')
                    ->description(static fn (Recibo $record): ?string => is_string($record->getAttribute('referencia'))
                        ? 'ref. '.$record->getAttribute('referencia')
                        : null)
                    /*
                     * 🔴 OCULTA POR DEFECTO desde el 27-ago-2026.
                     *
                     * Con nueve columnas el MONTO —que es a lo que se entra a
                     * esta pantalla— quedaba cortado contra el borde derecho.
                     * Esta es la mas ancha de las prescindibles: un badge mas
                     * un `ref.` que en esta cartera suele traer el nombre
                     * completo de quien recibio la plata.
                     *
                     * No se pierde nada: vuelve con un clic en el selector de
                     * columnas —y ahi se queda, porque Filament lo recuerda— y
                     * el recibo abierto la muestra siempre.
                     */
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('monto')
                    ->label('Monto')
                    ->alignEnd()
                    ->weight('bold')
                    ->sortable()
                    ->formatStateUsing(static fn (Recibo $record): string => $record->montoTotal()->formateado()),

            ])
            ->filters([
                SelectFilter::make('concepto')
                    ->label('Concepto')
                    ->options(static fn (): array => self::opciones(ConceptoDeRecibo::cases())),

                SelectFilter::make('forma_pago')
                    ->label('Forma de pago')
                    ->options(static fn (): array => self::opciones(FormaDePago::cases())),

                // Sin filtro se ven TODOS, anulados incluidos: la búsqueda es
                // por número y quien llega con el papel tiene que encontrarlo.
                TernaryFilter::make('anulado_el')
                    ->label('Anulados')
                    ->placeholder('Todos')
                    ->trueLabel('Solo los anulados')
                    ->falseLabel('Sin los anulados')
                    ->queries(
                        true: static fn (Builder $query): Builder => $query->whereNotNull('anulado_el'),
                        false: static fn (Builder $query): Builder => $query->whereNull('anulado_el'),
                        blank: static fn (Builder $query): Builder => $query,
                    ),

                /*
                 * El destino del link que sale de la ficha del cliente. El
                 * nombre `cliente` es el contrato con `ListadoDelCliente`, y
                 * el `withoutGlobalScopes` está para que un cliente archivado
                 * no abra una pantalla vacía — la razón larga está escrita en
                 * `VentasTable`.
                 */
                SelectFilter::make('cliente')
                    ->label('Cliente')
                    ->relationship(
                        'cliente',
                        'nombre',
                        static fn (Builder $query): Builder => $query->withoutGlobalScopes([SoftDeletingScope::class]),
                    )
                    ->searchable(),
            ])
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make(),
                    ImprimirRecibo::accion(),
                    CorregirRecibo::accion(),
                    self::anular(),
                ]),
            ])
            /*
             * ═══ 🔴 POR FECHA, NO POR NUMERO (27-ago-2026) ═══
             *
             * Ordenar por `numero` fue correcto mientras hubo UNA serie. Desde
             * el 23-ago hay dos —el talonario anterior al sistema, sin serie, y
             * la del proyecto, `RPS-…`— y las DOS empiezan en 1: el numero dejo
             * de ser cronologico.
             *
             * Lo que eso hacia en pantalla, y lo agarro Mauricio mirando la
             * lista: con 257 historicos y la serie nueva en 10, los recibos que
             * la recepcion emitio HOY caian en la ULTIMA pagina —la 27 de 27—
             * revueltos con pagos de junio, y la primera pagina mostraba el
             * talonario viejo.
             *
             * `fecha` sola no desempata —hay varios recibos del mismo dia— pero
             * no hay que escribirlo: cuando el orden por defecto no incluye la
             * llave, Filament le agrega `id` en la MISMA direccion
             * (`hasDefaultKeySort`, true de fabrica; el cuerpo esta en
             * `CanSortRecords::applySortingToTableQuery()`). El orden real es
             * `fecha desc, id desc`: lo ultimo cobrado, arriba.
             */
            ->defaultSort('fecha', 'desc')
            ->emptyStateHeading('Todavía no se ha cobrado nada')
            ->emptyStateDescription('Los recibos nacen al registrar un pago desde el expediente.')
            ->emptyStateIcon('heroicon-o-receipt-percent');
    }

    /**
     * Anular un recibo mal emitido (R12).
     *
     * ═══ POR QUE ES UNA ACCION Y NO UN BOTON DE BORRAR ═══
     *
     * El número no se libera y la fila no se borra: una serie con huecos deja
     * de servir para decir «entre el 000120 y el 000130 no falta ninguno», que
     * es lo único que hace serio a un recibo interno. Se marca, y lo que ese
     * recibo aplicaba vuelve a deberse.
     *
     * El motivo es obligatorio en los tres lados —acá, en el Service y en un
     * CHECK de la base—, porque un recibo anulado sin motivo es dinero que
     * desapareció del estado de cuenta sin que nadie tenga que explicarlo.
     *
     * Solo la administradora: `Anular:Recibo` no se le da al receptor. Quien
     * cobra no debería poder borrar su propio cobro.
     */
    private static function anular(): Action
    {
        return Action::make('anular')
            ->label('Anular')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->visible(static fn (Recibo $record): bool => auth()->user()?->can('anular', $record) === true)
            ->modalHeading(static fn (Recibo $record): string => "Anular el recibo {$record->folio()}")
            ->modalDescription('El número se queda en la serie y la fila no se borra: se marca. '
                .'Lo que este recibo aplicó vuelve a deberse. No devuelve dinero.')
            ->modalSubmitActionLabel('Anular el recibo')
            ->modalWidth('lg')
            ->schema([
                Textarea::make('motivo')
                    ->label('¿Por qué?')
                    ->required()
                    ->rows(3)
                    ->maxLength(500)
                    ->placeholder('Se tecleó L 5,000.00 en vez de L 500.00')
                    ->helperText('Queda con tu usuario y la fecha. Dentro de seis meses alguien va a '
                        .'preguntar qué pasó con este número.'),
            ])
            ->action(function (Recibo $record, array $data): void {
                try {
                    app(RegistroDePagos::class)->anular($record, (string) ($data['motivo'] ?? ''));
                } catch (GrupoOlympoException $error) {
                    // El mensaje del dominio ya está escrito para quien atiende.
                    Notification::make()
                        ->title('No se anuló')
                        ->body($error->getMessage())
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title("Recibo {$record->folio()} anulado")
                    ->body('Lo que aplicaba volvió a deberse y el número queda en la serie, marcado.')
                    ->success()
                    ->send();
            });
    }

    /**
     * ⚠️ `Select::options()` exige `array<string>`: un arreglo de enteros no
     * pasa PHPStan nivel 7, y con enums hay que sacar el `value` a mano.
     *
     * @param array<int, ConceptoDeRecibo|FormaDePago> $casos
     *
     * @return array<string, string>
     */
    private static function opciones(array $casos): array
    {
        $opciones = [];

        foreach ($casos as $caso) {
            $opciones[$caso->value] = $caso->etiqueta();
        }

        return $opciones;
    }

    /**
     * La segunda línea del folio: si el papel vale, con qué número salió, y
     * cuántas veces se imprimió.
     *
     * Las tres pueden estar juntas —un recibo anulado que había salido con CAI
     * y del que circulan copias— y en ese orden: lo que cambia si el papel vale
     * o no vale se lee antes que su numeración.
     *
     * ═══ 🔴 «IMPRESO» DEJO DE SER COLUMNA (27-ago-2026) ═══
     *
     * Era una columna entera —encabezado, ancho y badge— para decir «original»
     * en casi todas las filas. Se fue por lo mismo que se fue «Estado» el
     * 23-ago y con el mismo criterio: acá ocupa cero cuando no aplica, y ese
     * ancho es el que le faltaba al monto.
     *
     * Lo que NO se fue es la señal, porque aplica en dos casos y los dos
     * importan:
     *
     * - **sin imprimir**: el pago se registró y el cliente se fue sin papel.
     * - **copias**: dos papeles con el mismo número no pueden hacerse pasar por
     *   dos cobros, y notarlo antes de que sea un problema es justo para lo que
     *   está este renglón.
     *
     * El caso normal —salió una vez, el original— no dice nada. Es el 99 % de
     * las filas.
     */
    private static function debajoDelFolio(Recibo $record): ?string
    {
        $renglones = [];

        if ($record->estaAnulado()) {
            $motivo = $record->getAttribute('motivo_anulacion');

            $renglones[] = 'ANULADO'.(is_string($motivo) && $motivo !== '' ? ' — '.$motivo : '');
        }

        if ($record->esFactura()) {
            $renglones[] = 'Factura '.$record->numeroDelPapel();
        }

        $papel = self::comoSalioElPapel($record);

        if ($papel !== null) {
            $renglones[] = $papel;
        }

        return $renglones === [] ? null : implode(' · ', $renglones);
    }

    /**
     * Cuántas veces salió impreso, y solo cuando eso dice algo.
     *
     * ⚠️ Depende del `withCount('impresiones')` de `modifyQueryUsing()`: sin él
     * la columna no existe y esto diría «sin imprimir» en todas las filas.
     */
    private static function comoSalioElPapel(Recibo $record): ?string
    {
        $veces = (int) $record->getAttribute('impresiones_count');

        return match (true) {
            $veces === 0 => 'sin imprimir',
            $veces === 1 => null,
            $veces === 2 => '1 copia',
            default      => ($veces - 1).' copias',
        };
    }
}
