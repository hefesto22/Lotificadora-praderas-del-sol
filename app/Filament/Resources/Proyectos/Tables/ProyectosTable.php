<?php

declare(strict_types=1);

namespace App\Filament\Resources\Proyectos\Tables;

use App\Filament\Resources\Proyectos\ProyectoResource;
use App\Models\Proyecto;
use App\Support\ProyectoActivo;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * §10.7: columnas explícitas, filtros con la misma fuente que el scoping,
 * defaultSort y paginación 25/50/100.
 *
 * Los conteos usan ->counts(), que agrega el withCount a la query: hacerlo
 * con $record->bloques()->count() por fila sería un N+1 de manual.
 */
class ProyectosTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // Solo el proyecto elegido en el interruptor de la barra; en
            // «Todos», la lista completa (18-sep-2026, traído de Maya).
            //
            // 🔴 Va en la TABLA y no en `getEloquentQuery()`: el resource
            // resuelve con esa consulta el record de TODAS sus páginas, y
            // recortarla ahí hace que el plano o la ficha de un proyecto den
            // 404 en cuanto el elegido es otro. En Maya pasó exactamente eso.
            ->modifyQueryUsing(static fn (Builder $query): Builder => app(ProyectoActivo::class)->recortar($query, 'id'))
            ->columns([
                TextColumn::make('nombre')
                    ->label('Proyecto')
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),

                TextColumn::make('codigo')
                    ->label('Código')
                    ->badge()
                    ->color('gray')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('municipio')
                    ->label('Municipio')
                    ->searchable()
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('bloques_count')
                    ->label('Bloques')
                    ->counts('bloques')
                    ->badge()
                    ->color('info')
                    ->alignCenter()
                    ->sortable(),

                TextColumn::make('lotes_count')
                    ->label('Lotes')
                    ->counts('lotes')
                    ->badge()
                    ->color('success')
                    ->alignCenter()
                    ->sortable(),

                IconColumn::make('activo')
                    ->label('Activo')
                    ->boolean()
                    ->alignCenter()
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Creado')
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
            ])
            ->recordActions([
                /*
                | Fuera del menú de tres puntos a propósito.
                |
                | El plano es a lo que se entra todos los días —es la
                | pantalla donde se aparta y se vende— así que no debería
                | costar dos clics y adivinar qué hay detrás del menú. Ver,
                | editar y borrar sí van adentro: son ocasionales.
                */
                Action::make('plano')
                    ->label('Ver plano')
                    ->icon(Heroicon::OutlinedMap)
                    ->button()
                    ->color('warning')
                    ->url(fn (Proyecto $record): string => ProyectoResource::getUrl('plano', ['record' => $record])),

                ActionGroup::make([
                    ViewAction::make(),
                    EditAction::make(),
                    // Borrar un proyecto arrastra bloques, lotes y calles
                    // (ver Proyecto::booted). Que el modal lo diga.
                    DeleteAction::make()
                        ->modalDescription('Se borran también sus bloques, sus lotes y sus calles. Si algún lote está apartado o vendido, no se borra nada.'),
                ]),
            ])
            ->defaultSort('nombre')
            ->paginationPageOptions([25, 50, 100])
            ->emptyStateHeading('Todavía no hay proyectos')
            ->emptyStateDescription('Un proyecto agrupa bloques y lotes. Creá el primero para empezar a cargar el residencial.')
            ->emptyStateIcon('heroicon-o-building-office-2');
    }
}
