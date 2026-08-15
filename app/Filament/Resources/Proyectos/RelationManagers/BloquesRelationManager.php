<?php

declare(strict_types=1);

namespace App\Filament\Resources\Proyectos\RelationManagers;

use App\Filament\Schemas\Components\AreaField;
use App\Filament\Schemas\Components\MayusculasField;
use App\Filament\Support\Unidades;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * Los bloques, adentro del proyecto.
 *
 * Antes esto vivía en el menú principal como una entidad suelta, y para
 * agregar un bloque había que salir del proyecto, entrar a Bloques, y elegir
 * de nuevo a qué proyecto pertenecía. Acá el proyecto ya está decidido: es
 * el de la ficha que estás mirando.
 *
 * El formulario es más corto que el del Resource a propósito — no pregunta
 * el proyecto, porque la relación lo pone sola.
 */
class BloquesRelationManager extends RelationManager
{
    #[Override]
    protected static string $relationship = 'bloques';

    #[Override]
    protected static ?string $title = 'Bloques';

    /**
     * El tipo tiene que ser IDÉNTICO al del padre —`string|BackedEnum|null`,
     * no `?string`—: PHP exige la firma exacta al redeclarar una propiedad
     * tipada, y con una estática eso revienta al cargar la clase, no al
     * usarla.
     */
    #[Override]
    protected static string|BackedEnum|null $icon = 'heroicon-o-rectangle-group';

    #[Override]
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                MayusculasField::make('nombre')
                    ->label('Nombre del bloque')
                    ->required()
                    ->maxLength(10)
                    ->placeholder('A')
                    ->helperText('Único dentro del proyecto. Va en el código del lote: RPS-A-001.'),

                TextInput::make('orden')
                    ->label('Orden')
                    ->numeric()
                    ->minValue(1)
                    ->default(1)
                    ->helperText('En qué posición se lista respecto de los demás bloques.'),

                AreaField::make('area_total_varas', 'Área total según el plano')
                    ->helperText('Lo que dice el plano del topógrafo, no la suma de los lotes cargados.'),

                TextInput::make('lotes_planificados')
                    ->label('Lotes planificados')
                    ->numeric()
                    ->minValue(0)
                    ->helperText('Cuántos lotes dibuja el plano. El conteo real sale de los lotes cargados.'),
            ]);
    }

    #[Override]
    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('nombre')
            /*
            | with('proyecto'): la unidad del área se resuelve por fila y
            | sale del proyecto. Sin esto son 25 consultas por página
            | (§4.L4). Ver App\Filament\Support\Unidades.
            */
            ->modifyQueryUsing(static fn (Builder $query): Builder => $query->with('proyecto'))
            ->columns([
                TextColumn::make('nombre')
                    ->label('Bloque')
                    ->badge()
                    ->color('gray')
                    ->sortable(),

                TextColumn::make('lotes_count')
                    ->label('Lotes cargados')
                    ->counts('lotes')
                    ->alignCenter()
                    ->sortable(),

                TextColumn::make('lotes_planificados')
                    ->label('Según el plano')
                    ->alignCenter()
                    ->placeholder('—'),

                TextColumn::make('area_total_varas')
                    ->label('Área del plano')
                    ->suffix(static fn (?Model $record): string => ' '.Unidades::de($record)->plural())
                    ->alignEnd()
                    ->placeholder('—'),
            ])
            ->headerActions([
                CreateAction::make()->label('Nuevo bloque'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->defaultSort('orden')
            ->paginationPageOptions([25, 50, 100])
            ->emptyStateHeading('Este proyecto todavía no tiene bloques')
            ->emptyStateDescription('Importá el plano DXF para cargarlos todos de una vez, o agregá uno a mano.');
    }
}
