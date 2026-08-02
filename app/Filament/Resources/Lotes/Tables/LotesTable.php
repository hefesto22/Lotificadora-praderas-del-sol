<?php

declare(strict_types=1);

namespace App\Filament\Resources\Lotes\Tables;

use App\Domain\Enums\EstadoLote;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class LotesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('proyecto.nombre')
                    ->label('Proyecto')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('bloque.nombre')
                    ->label('Bloque')
                    ->badge()
                    ->color('gray')
                    ->sortable(),

                TextColumn::make('numero')
                    ->label('Lote')
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),

                TextColumn::make('area_varas')
                    ->label('Área')
                    ->suffix(' varas²')
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('precio_vara')
                    ->label('Precio/vara²')
                    ->prefix('L ')
                    ->alignEnd()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('valor')
                    ->label('Valor')
                    ->prefix('L ')
                    ->alignEnd()
                    ->sortable()
                    ->weight('medium'),

                TextColumn::make('estado')
                    ->label('Estado')
                    ->badge()
                    ->color(fn (EstadoLote $state): string => $state->color())
                    ->formatStateUsing(fn (EstadoLote $state): string => $state->etiqueta())
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('proyecto_id')
                    ->label('Proyecto')
                    ->relationship('proyecto', 'nombre')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('bloque_id')
                    ->label('Bloque')
                    ->relationship('bloque', 'nombre')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('estado')
                    ->label('Estado')
                    ->options(fn (): array => collect(EstadoLote::cases())
                        ->mapWithKeys(fn (EstadoLote $estado): array => [$estado->value => $estado->etiqueta()])
                        ->all())
                    ->multiple(),
            ])
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make(),
                    EditAction::make(),
                    DeleteAction::make(),
                ]),
            ])
            ->defaultSort('numero')
            ->paginationPageOptions([25, 50, 100])
            ->emptyStateHeading('Todavía no hay lotes')
            ->emptyStateDescription('Cargá el primer lote de un bloque para empezar a vender.')
            ->emptyStateIcon('heroicon-o-squares-2x2');
    }
}
