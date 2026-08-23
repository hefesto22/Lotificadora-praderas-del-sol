<?php

declare(strict_types=1);

namespace App\Filament\Resources\Ventas\RelationManagers;

use App\Domain\Enums\ConceptoDeRecibo;
use App\Filament\Support\ImprimirRecibo;
use App\Models\Recibo;
use BackedEnum;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Override;

/**
 * Lo que este expediente ha pagado, con su papel.
 *
 * La lista general de recibos sirve para cuando alguien llega con el número;
 * esta pestaña sirve para la pregunta de todos los días —«¿qué ha pagado este
 * cliente?»— y para reimprimir sin salir del expediente.
 *
 * ═══ SOLO SE MIRA Y SE IMPRIME ═══
 *
 * Sin crear, editar ni borrar: un recibo nace en la transacción que cobra y no
 * se corrige (R12). Lo impone `ReciboPolicy`, no esta clase.
 */
class RecibosRelationManager extends RelationManager
{
    #[Override]
    protected static string $relationship = 'recibos';

    #[Override]
    protected static ?string $title = 'Recibos';

    #[Override]
    protected static string|BackedEnum|null $icon = 'heroicon-o-receipt-percent';

    #[Override]
    public function form(Schema $schema): Schema
    {
        // No se edita ninguno, pero el contrato del padre pide el método.
        return $schema->components([]);
    }

    #[Override]
    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('numero')
            ->modifyQueryUsing(static fn (Builder $query): Builder => $query
                ->with('compromiso.lote')
                ->withCount('impresiones'))
            ->columns([
                /*
                 * 🔴 La descripcion con el numero de FACTURA no es adorno.
                 *
                 * El listado general de Recibos ya la traia; esta tabla —la
                 * que se mira parado en el expediente— no, y el 14-ago-2026
                 * eso hizo que Mauricio preguntara donde estaba el boton para
                 * emitir la factura de una venta de contado. Ya estaba
                 * emitida: la 000-001-01-00000003, en este mismo renglon. Lo
                 * que faltaba era que el renglon lo dijera.
                 *
                 * Dos numeros en una fila no confunden si uno esta arriba y
                 * grande —el interno, el que cuadra la caja— y el otro debajo
                 * y chico, con su nombre.
                 */
                TextColumn::make('numero')
                    ->label('Recibo')
                    ->weight('bold')
                    ->sortable()
                    ->formatStateUsing(static fn (Recibo $record): string => $record->folio())
                    ->description(static fn (Recibo $record): ?string => $record->esFactura()
                        ? 'Factura '.$record->numeroDelPapel()
                        : null),

                TextColumn::make('fecha')
                    ->label('Fecha')
                    ->date('d/m/Y')
                    ->sortable(),

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
                        : '—')
                    ->color(static fn (Recibo $record): string => $record->getAttribute('concepto') instanceof ConceptoDeRecibo
                        ? $record->getAttribute('concepto')->color()
                        : 'gray'),

                TextColumn::make('monto')
                    ->label('Monto')
                    ->alignEnd()
                    ->weight('bold')
                    ->formatStateUsing(static fn (Recibo $record): string => $record->montoTotal()->formateado()),

                TextColumn::make('impresiones_count')
                    ->label('Impreso')
                    ->badge()
                    ->color(static fn (Recibo $record): string => (int) $record->getAttribute('impresiones_count') > 1 ? 'danger' : 'gray')
                    ->formatStateUsing(static fn (Recibo $record): string => match ((int) $record->getAttribute('impresiones_count')) {
                        0       => 'nunca',
                        1       => 'original',
                        2       => '1 copia',
                        default => ((int) $record->getAttribute('impresiones_count') - 1).' copias',
                    }),
            ])
            ->recordActions([
                ImprimirRecibo::accion(),
            ])
            ->defaultSort('numero', 'desc')
            ->emptyStateHeading('Este expediente no ha pagado nada todavía')
            ->emptyStateDescription('El primer recibo sale al registrar un pago.')
            ->emptyStateIcon('heroicon-o-receipt-percent');
    }

    /**
     * Nunca de solo lectura por ser una página de vista: el default de
     * Filament escondería hasta el botón de imprimir.
     */
    #[Override]
    public function isReadOnly(): bool
    {
        return false;
    }
}
