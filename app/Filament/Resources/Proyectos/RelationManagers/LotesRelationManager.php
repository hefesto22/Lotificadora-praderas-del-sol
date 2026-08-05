<?php

declare(strict_types=1);

namespace App\Filament\Resources\Proyectos\RelationManagers;

use App\Domain\Enums\EstadoLote;
use App\Filament\Schemas\Components\AreaField;
use App\Filament\Schemas\Components\MayusculasField;
use App\Filament\Schemas\Components\MontoField;
use App\Models\Bloque;
use App\Models\Lote;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Override;

/**
 * Los lotes del proyecto, adentro del proyecto.
 *
 * ═══ ESTA NO ES LA PANTALLA DE CARGA MASIVA ═══
 *
 * Los 301 lotes de Praderas del Sol entraron con el importador de DXF, y el
 * precio se fija de a bloques enteros con la acción del encabezado. Esta
 * tabla es para **el caso suelto**: corregir un número mal leído del plano,
 * cambiarle el precio a un lote de esquina, cancelar uno.
 *
 * Por eso el botón de crear existe pero no es el protagonista: agregar 301
 * lotes desde acá sería un error de método, no de paciencia.
 *
 * El selector de bloque solo ofrece los del proyecto de la ficha. No es
 * cortesía: la FK compuesta `(bloque_id, proyecto_id)` de la base rechaza un
 * bloque de otro proyecto, y es mejor no ofrecerlo que explicar el error
 * después de apretar guardar.
 */
class LotesRelationManager extends RelationManager
{
    #[Override]
    protected static string $relationship = 'lotes';

    #[Override]
    protected static ?string $title = 'Lotes';

    /** Mismo tipo exacto que el padre; ver la nota en BloquesRelationManager. */
    #[Override]
    protected static string|BackedEnum|null $icon = 'heroicon-o-squares-2x2';

    #[Override]
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('bloque_id')
                    ->label('Bloque')
                    ->options(fn (): array => Bloque::query()
                        ->where('proyecto_id', $this->getOwnerRecord()->getKey())
                        ->orderBy('orden')
                        ->orderBy('nombre')
                        ->pluck('nombre', 'id')
                        ->all())
                    ->searchable()
                    ->required()
                    ->helperText('Solo los bloques de este proyecto.'),

                MayusculasField::make('numero')
                    ->label('Número de lote')
                    ->required()
                    ->maxLength(20)
                    ->placeholder('12 o 12-A')
                    ->helperText('Único dentro del bloque.'),

                AreaField::make('area_varas', 'Área')
                    ->required()
                    ->disabled(fn (?Lote $record): bool => $this->estaVendido($record)),

                MontoField::make('precio_vara', 'Precio por vara²')
                    ->disabled(fn (?Lote $record): bool => $this->estaVendido($record))
                    ->helperText('El valor se calcula solo: área × precio.'),
            ]);
    }

    #[Override]
    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('codigo')
            ->columns([
                TextColumn::make('codigo')
                    ->label('Código')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->weight('medium'),

                TextColumn::make('bloque.nombre')
                    ->label('Bloque')
                    ->badge()
                    ->color('gray')
                    ->sortable(),

                TextColumn::make('area_varas')
                    ->label('Área')
                    ->suffix(' varas²')
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('precio_vara')
                    ->label('Precio/vara²')
                    ->prefix('L ')
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),

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
                SelectFilter::make('bloque_id')
                    ->label('Bloque')
                    ->options(fn (): array => Bloque::query()
                        ->where('proyecto_id', $this->getOwnerRecord()->getKey())
                        ->orderBy('orden')
                        ->orderBy('nombre')
                        ->pluck('nombre', 'id')
                        ->all()),

                SelectFilter::make('estado')
                    ->label('Estado')
                    ->options(fn (): array => collect(EstadoLote::cases())
                        ->mapWithKeys(fn (EstadoLote $estado): array => [$estado->value => $estado->etiqueta()])
                        ->all())
                    ->multiple(),
            ])
            ->headerActions([
                CreateAction::make()->label('Nuevo lote'),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            /*
            | Por `codigo` y no por `numero`: `numero` es texto y ordenaba
            | 1, 10, 11, 12… dejando el lote 2 después del 19. El código
            | lleva el número con relleno a 3 dígitos, así que su orden
            | alfabético ES el orden correcto.
            */
            ->defaultSort('codigo')
            ->paginationPageOptions([25, 50, 100])
            ->emptyStateHeading('Este proyecto todavía no tiene lotes')
            ->emptyStateDescription('Importá el plano DXF desde "Ver plano": carga los lotes con su área y su polígono.');
    }

    /**
     * §9.A2: en CREATE el schema recibe un modelo VACÍO, no null. Por eso se
     * lee el estado crudo contra el value del enum.
     */
    private function estaVendido(?Lote $record): bool
    {
        return $record?->getRawOriginal('estado') === EstadoLote::Vendido->value;
    }
}
