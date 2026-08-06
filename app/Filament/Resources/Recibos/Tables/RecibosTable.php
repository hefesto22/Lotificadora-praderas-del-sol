<?php

declare(strict_types=1);

namespace App\Filament\Resources\Recibos\Tables;

use App\Domain\Enums\ConceptoDeRecibo;
use App\Domain\Enums\FormaDePago;
use App\Filament\Support\ImprimirRecibo;
use App\Models\Recibo;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

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
                'cliente', 'venta', 'compromiso.lote',
            ])->withCount('impresiones'))
            ->columns([
                TextColumn::make('numero')
                    ->label('Recibo')
                    ->weight('bold')
                    ->searchable()
                    ->sortable()
                    ->formatStateUsing(static fn (Recibo $record): string => $record->folio()),

                TextColumn::make('fecha')
                    ->label('Fecha')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('cliente.nombre')
                    ->label('Recibí de')
                    ->searchable()
                    ->wrap()
                    ->placeholder('—'),

                TextColumn::make('venta.numero_contrato')
                    ->label('Contrato')
                    ->searchable()
                    ->placeholder('—'),

                TextColumn::make('compromiso.lote.codigo')
                    ->label('Lote')
                    ->badge()
                    ->color('gray')
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
            ])
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make(),
                    ImprimirRecibo::accion(),
                ]),
            ])
            ->defaultSort('numero', 'desc')
            ->emptyStateHeading('Todavía no se ha cobrado nada')
            ->emptyStateDescription('Los recibos nacen al registrar un pago desde el expediente.')
            ->emptyStateIcon('heroicon-o-receipt-percent');
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
