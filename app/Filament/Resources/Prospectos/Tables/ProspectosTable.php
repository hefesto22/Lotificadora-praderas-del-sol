<?php

declare(strict_types=1);

namespace App\Filament\Resources\Prospectos\Tables;

use App\Filament\Support\BuscarNombre;
use App\Models\LoteConsultado;
use App\Models\Prospecto;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * La lista de a quién llamar.
 *
 * ═══ EL TELEFONO ES LA COLUMNA, NO UN DETALLE ═══
 *
 * Es lo único que hay que hacer con esta pantalla: marcar el número. Va
 * grande, copiable de un toque, y con enlace `tel:` para que desde una
 * tablet o un teléfono se llame sin transcribirlo — que es donde se
 * equivoca un dígito y se pierde el contacto.
 */
final class ProspectosTable
{
    public static function configure(Table $table): Table
    {
        return $table
            /*
             * Las consultas y sus lotes, de una vez. Sin esto, una lista de
             * treinta prospectos con tres lotes cada uno son noventa
             * consultas para dibujar una sola columna.
             */
            ->modifyQueryUsing(static fn (Builder $query): Builder => $query->with(['consultas.lote']))
            ->columns([
                TextColumn::make('created_at')
                    ->label('Escribió')
                    ->dateTime('d/m/Y H:i')
                    ->description(fn (Prospecto $record): string => $record->estaAtendido() ? 'Atendido' : 'Sin atender')
                    ->sortable(),

                TextColumn::make('nombre')
                    ->label('Nombre')
                    // Sin acentos: ver BuscarNombre.
                    ->searchable(query: BuscarNombre::propio())
                    ->weight('bold')
                    ->wrap(),

                TextColumn::make('telefono')
                    ->label('Teléfono')
                    ->searchable()
                    ->copyable()
                    ->copyMessage('Teléfono copiado')
                    ->url(fn (Prospecto $record): string => 'tel:'.$record->getAttribute('telefono')),

                /*
                 * TODOS los lotes por los que preguntó, no el primero.
                 *
                 * Desde el 23-ago el prospecto es la persona: preguntar por
                 * tres lotes es una fila con tres insignias, no tres filas
                 * con el mismo teléfono. La que lleva un «×2» es alguien que
                 * volvió a preguntar por el mismo lote — está decidido, y esa
                 * es la llamada que conviene hacer primero.
                 */
                TextColumn::make('lotes')
                    ->label('Preguntó por')
                    ->badge()
                    ->color('info')
                    ->placeholder('Sin lote')
                    ->searchable(query: static fn (Builder $query, string $search): Builder => $query
                        ->whereHas('consultas.lote', static fn (Builder $lote): Builder => $lote
                            ->where('codigo', 'ilike', "%{$search}%")))
                    ->getStateUsing(static fn (Prospecto $record): array => $record->consultas
                        ->map(static function (LoteConsultado $consulta): ?string {
                            $codigo = $consulta->lote?->getAttribute('codigo');

                            if (! is_string($codigo) || $codigo === '') {
                                return null;
                            }

                            $veces = $consulta->getAttribute('veces');

                            return is_int($veces) && $veces > 1 ? $codigo.' ×'.$veces : $codigo;
                        })
                        ->filter(static fn (?string $codigo): bool => $codigo !== null)
                        ->values()
                        ->all()),

                TextColumn::make('miraba')
                    ->label('Miraba')
                    ->color('gray')
                    ->placeholder('—')
                    // El plazo de la ÚLTIMA consulta: `consultas` viene
                    // ordenada por `ultima_vez` descendente.
                    ->getStateUsing(static fn (Prospecto $record): ?string => $record->consultas
                        ->first()?->plazoEnPalabras()),

                TextColumn::make('proyecto.nombre')
                    ->label('Proyecto')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('atendidoPor.name')
                    ->label('Lo llamó')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('nota')
                    ->label('Nota')
                    ->wrap()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('sin_atender')
                    ->label('Solo los que esperan llamada')
                    ->query(fn (Builder $query): Builder => $query->whereNull('atendido_el'))
                    ->default(),

                SelectFilter::make('proyecto_id')
                    ->label('Proyecto')
                    ->relationship('proyecto', 'nombre'),
            ])
            ->recordActions([
                Action::make('atender')
                    ->label('Ya lo llamé')
                    ->icon('heroicon-o-phone')
                    ->color('success')
                    ->visible(fn (Prospecto $record): bool => ! $record->estaAtendido())
                    ->schema(fn (Schema $schema): Schema => $schema->components([
                        Textarea::make('nota')
                            ->label('¿Qué dijo?')
                            ->rows(3)
                            ->maxLength(500)
                            ->helperText('Opcional, pero es lo que va a leer quien lo atienda la próxima vez.'),
                    ]))
                    ->action(function (Prospecto $record, array $data): void {
                        /*
                         * Los dos campos van juntos: el CHECK
                         * `prospectos_atencion_completa_chk` no admite una
                         * fila marcada como atendida sin decir quién.
                         */
                        $record->update([
                            'atendido_el'  => now(),
                            'atendido_por' => auth()->id(),
                            'nota'         => is_string($data['nota'] ?? null) && trim($data['nota']) !== ''
                                ? trim($data['nota'])
                                : null,
                        ]);
                    }),
            ])
            /*
             * Los que esperan primero, y entre ellos el más viejo arriba: un
             * contacto de hace tres días se enfría más rápido que el de hace
             * tres minutos.
             */
            ->defaultSort('created_at', 'desc')
            ->paginationPageOptions([25, 50, 100])
            ->emptyStateHeading('Todavía nadie escribió')
            ->emptyStateDescription('Acá van a aparecer las personas que dejen su teléfono en el plano público. Si el plano está apagado, nadie puede escribir.')
            ->emptyStateIcon('heroicon-o-user-plus');
    }
}
