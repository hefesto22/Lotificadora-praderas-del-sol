<?php

declare(strict_types=1);

namespace App\Filament\Resources\Clientes\Tables;

use App\Domain\ValueObjects\DNI;
use App\Models\Cliente;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * §10.7: columnas explícitas, filtros con la misma fuente que el scoping,
 * defaultSort y paginación 25/50/100.
 */
class ClientesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('nombre')
                    ->label('Cliente')
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),

                /*
                | El DNI se guarda en dígitos limpios pero la gente lo teclea
                | como lo lee del carnet, con guiones. Sin este query el
                | buscador no encuentra nada al pegar "0801-1985-01234" y
                | parece que el cliente no existe.
                */
                TextColumn::make('dni')
                    ->label('DNI')
                    ->formatStateUsing(fn (?string $state): string => is_string($state) && $state !== ''
                        ? DNI::formatearCrudo($state)
                        : '—')
                    ->placeholder('—')
                    ->searchable(query: static function (Builder $query, string $search): Builder {
                        $digitos = preg_replace('/\D/', '', $search) ?? '';

                        return $digitos === '' ? $query : $query->where('dni', 'like', $digitos.'%');
                    })
                    ->copyable()
                    ->toggleable(),

                TextColumn::make('telefono')
                    ->label('Teléfono')
                    ->formatStateUsing(fn (?string $state, Cliente $record): string => $record->telefonoFormateado() ?? '—')
                    ->placeholder('—')
                    ->searchable()
                    ->icon('heroicon-o-phone')
                    ->toggleable(),

                TextColumn::make('correo')
                    ->label('Correo')
                    ->searchable()
                    ->placeholder('—')
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),

                IconColumn::make('activo')
                    ->label('Activo')
                    ->boolean()
                    ->alignCenter()
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Registrado')
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('activo')
                    ->label('Estado')
                    ->placeholder('Todos')
                    ->trueLabel('Solo activos')
                    ->falseLabel('Solo inactivos'),

                TrashedFilter::make()
                    ->label('Archivados'),
            ])
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make(),
                    EditAction::make(),
                    DeleteAction::make(),
                    RestoreAction::make(),
                    ForceDeleteAction::make(),
                ]),
            ])
            ->defaultSort('nombre')
            ->paginationPageOptions([25, 50, 100])
            ->emptyStateHeading('Todavía no hay clientes')
            ->emptyStateDescription('Registrá al primer cliente para poder apartar o vender un lote.')
            ->emptyStateIcon('heroicon-o-users');
    }
}
