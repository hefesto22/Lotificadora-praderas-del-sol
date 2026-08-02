<?php

declare(strict_types=1);

namespace App\Filament\Resources\Bloques\Tables;

use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class BloquesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('proyecto.nombre')
                    ->label('Proyecto')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('nombre')
                    ->label('Bloque')
                    ->badge()
                    ->color('gray')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('area_total_varas')
                    ->label('Área del plano')
                    ->suffix(' varas²')
                    ->placeholder('—')
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('lotes_count')
                    ->label('Lotes cargados')
                    ->counts('lotes')
                    ->badge()
                    ->color('success')
                    ->alignCenter()
                    ->sortable(),

                TextColumn::make('lotes_planificados')
                    ->label('Según el plano')
                    ->placeholder('—')
                    ->alignCenter()
                    ->sortable(),

                TextColumn::make('orden')
                    ->label('Orden')
                    ->alignCenter()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('proyecto_id')
                    ->label('Proyecto')
                    ->relationship('proyecto', 'nombre')
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make(),
                    EditAction::make(),
                    DeleteAction::make(),
                ]),
            ])
            ->defaultSort('orden')
            ->paginationPageOptions([25, 50, 100])
            ->emptyStateHeading('Todavía no hay bloques')
            ->emptyStateDescription('Un bloque agrupa los lotes de un proyecto. Creá el primero para empezar a cargar el plano.')
            ->emptyStateIcon('heroicon-o-rectangle-group');
    }
}
