<?php

declare(strict_types=1);

namespace App\Filament\Resources\Ventas\RelationManagers;

use App\Models\Reprogramacion;
use BackedEnum;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Override;

/**
 * Por qué el plan de un lote cambió (R21).
 *
 * ═══ POR QUE ESTA PESTAÑA EXISTE ═══
 *
 * Un abono a capital borra las cuotas pendientes y escribe otras. Sin esto,
 * el mes siguiente el cliente pregunta «¿por qué mi cuota bajó?» y quien
 * atiende no tiene dónde mirar: las filas viejas ya no existen. Registrar la
 * reprogramación y no mostrarla en ningún lado sería tener la respuesta
 * guardada donde nadie la puede leer.
 *
 * ═══ SOLO SE MIRA ═══
 *
 * Sin botones de crear, editar ni borrar. Una reprogramación nace dentro de
 * la transacción del abono —junto al recibo y al plan nuevo— y es historia:
 * si una se hizo mal, la corrección es OTRA reprogramación con su motivo. Que
 * no aparezcan los botones no depende de esta clase: lo decide
 * `ReprogramacionPolicy`, que devuelve `false` a todas las escrituras.
 */
class ReprogramacionesRelationManager extends RelationManager
{
    #[Override]
    protected static string $relationship = 'reprogramaciones';

    #[Override]
    protected static ?string $title = 'Reprogramaciones';

    #[Override]
    protected static string|BackedEnum|null $icon = 'heroicon-o-arrow-trending-down';

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
            ->recordTitleAttribute('motivo')
            ->modifyQueryUsing(static fn (Builder $query): Builder => $query->with(['compromiso.lote', 'createdBy']))
            ->columns([
                TextColumn::make('created_at')
                    ->label('Cuándo')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('compromiso.lote.codigo')
                    ->label('Lote')
                    ->badge()
                    ->color('gray')
                    ->placeholder('—'),

                TextColumn::make('modalidad')
                    ->label('Qué se hizo')
                    ->badge()
                    ->formatStateUsing(static fn (Reprogramacion $record): string => $record->etiquetaDeModalidad())
                    ->color(static fn (Reprogramacion $record): string => $record->colorDeModalidad()),

                TextColumn::make('abono_capital')
                    ->label('Bajó el capital')
                    ->alignEnd()
                    ->weight('bold')
                    ->formatStateUsing(static fn (Reprogramacion $record): string => $record->montoAbonado()->formateado()),

                /*
                 * El antes y el después en una sola columna: es la pregunta que
                 * se hace en ventanilla —«¿de cuánto a cuánto?»— y separarla en
                 * dos obliga a leer la fila dos veces.
                 */
                TextColumn::make('cuota_nueva')
                    ->label('Cuota')
                    ->alignEnd()
                    ->state(static fn (Reprogramacion $record): string => sprintf(
                        '%s → %s',
                        $record->montoCuotaAnterior()?->formateado() ?? '—',
                        $record->montoCuotaNueva()?->formateado() ?? 'sin cuotas',
                    )),

                TextColumn::make('cuotas_despues')
                    ->label('Meses')
                    ->alignEnd()
                    ->state(static fn (Reprogramacion $record): string => sprintf(
                        '%d → %d',
                        (int) $record->getAttribute('cuotas_antes'),
                        (int) $record->getAttribute('cuotas_despues'),
                    ))
                    ->description(static fn (Reprogramacion $record): ?string => $record->mesesAhorrados() > 0
                        ? sprintf('%d menos', $record->mesesAhorrados())
                        : null),

                TextColumn::make('motivo')
                    ->label('Por qué')
                    ->wrap()
                    ->limit(80),

                TextColumn::make('createdBy.name')
                    ->label('Quién')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('recibo.numero')
                    ->label('Recibo')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('El plan nunca se reprogramó')
            ->emptyStateDescription('Las cuotas de este expediente son las que se firmaron. Un abono a capital las reescribiría, y quedaría acá.')
            ->emptyStateIcon('heroicon-o-arrow-trending-down');
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
