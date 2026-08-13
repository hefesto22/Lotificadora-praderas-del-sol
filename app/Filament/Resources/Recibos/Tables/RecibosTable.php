<?php

declare(strict_types=1);

namespace App\Filament\Resources\Recibos\Tables;

use App\Domain\Enums\ConceptoDeRecibo;
use App\Domain\Enums\FormaDePago;
use App\Domain\Exceptions\GrupoOlympoException;
use App\Domain\Pagos\RegistroDePagos;
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
                TextColumn::make('numero')
                    ->label('Recibo')
                    ->weight('bold')
                    ->searchable()
                    ->sortable()
                    ->formatStateUsing(static fn (Recibo $record): string => $record->folio()),

                /*
                 * Un recibo anulado NO se esconde de la lista: su número sigue
                 * en la serie y el papel sigue en la mano de alguien. Buscar
                 * el 000123 y no encontrarlo sería peor que verlo tachado.
                 */
                TextColumn::make('anulado_el')
                    ->label('Estado')
                    ->badge()
                    ->color('danger')
                    ->state(static fn (Recibo $record): ?string => $record->estaAnulado() ? 'ANULADO' : null)
                    ->tooltip(static function (Recibo $record): ?string {
                        $motivo = $record->getAttribute('motivo_anulacion');

                        return is_string($motivo) ? $motivo : null;
                    })
                    ->placeholder(''),

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
                    ->searchable()
                    ->wrap()
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
                    ->toggleable(),

                TextColumn::make('monto')
                    ->label('Monto')
                    ->alignEnd()
                    ->weight('bold')
                    ->sortable()
                    ->formatStateUsing(static fn (Recibo $record): string => $record->montoTotal()->formateado()),

                /*
                 * Dos papeles con el mismo número no pueden hacerse pasar por
                 * dos cobros. Que la lista diga cuántas veces salió impreso es
                 * lo que permite notarlo antes de que sea un problema.
                 */
                TextColumn::make('impresiones_count')
                    ->label('Impreso')
                    ->badge()
                    ->color(static fn (Recibo $record): string => match (true) {
                        (int) $record->getAttribute('impresiones_count') === 0 => 'warning',
                        (int) $record->getAttribute('impresiones_count') === 1 => 'gray',
                        default                                                => 'danger',
                    })
                    ->formatStateUsing(static fn (Recibo $record): string => match ((int) $record->getAttribute('impresiones_count')) {
                        0       => 'nunca',
                        1       => 'original',
                        2       => '1 copia',
                        default => ((int) $record->getAttribute('impresiones_count') - 1).' copias',
                    })
                    ->toggleable(),
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
                    self::anular(),
                ]),
            ])
            ->defaultSort('numero', 'desc')
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
}
