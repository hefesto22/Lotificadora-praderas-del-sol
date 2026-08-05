<?php

declare(strict_types=1);

namespace App\Filament\Resources\Proyectos\RelationManagers;

use App\Domain\ValueObjects\Monto;
use App\Filament\Schemas\Components\MontoField;
use App\Models\PlanDePago;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Override;

/**
 * El precio de la vara² a cada plazo.
 *
 * Vive acá, en el proyecto, y no en un archivo de configuración: quien
 * decide estos números es la administración, y tiene que poder cambiarlos
 * sin tocar código ni esperar un despliegue.
 *
 * ⚠️ No confundir con interés. El saldo financiado no devenga nada (R1): la
 * cuota sigue siendo (valor − prima) ÷ meses. Lo que cambia con el plazo es
 * el precio de lista de la vara, y una vez elegido el plazo queda fijo.
 *
 * El plazo 0 es contado. Si no se carga, el plano cotiza el contado al
 * precio propio de cada lote.
 */
class PlanesDePagoRelationManager extends RelationManager
{
    #[Override]
    protected static string $relationship = 'planesDePago';

    #[Override]
    protected static ?string $title = 'Planes de pago';

    /**
     * El tipo tiene que ser IDÉNTICO al del padre —`string|BackedEnum|null`,
     * no `?string`—: PHP exige la firma exacta al redeclarar una propiedad
     * tipada, y con una estática eso revienta al cargar la clase.
     */
    #[Override]
    protected static string|BackedEnum|null $icon = 'heroicon-o-calculator';

    #[Override]
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('meses')
                    ->label('Plazo en meses')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(600)
                    ->required()
                    ->helperText('0 es contado. No puede repetirse dentro del proyecto.'),

                MontoField::make('precio_vara', 'Precio por vara²')
                    ->required()
                    ->helperText('Lo que cuesta la vara² si el cliente elige este plazo.'),

                TextInput::make('etiqueta')
                    ->label('Nombre en pantalla')
                    ->maxLength(60)
                    ->placeholder('12 meses')
                    ->helperText('Opcional. Para los casos que el número no explica: «12 meses (feria)».'),

                Toggle::make('activo')
                    ->label('Se ofrece')
                    ->default(true)
                    ->helperText('Un plan que se deja de ofrecer se apaga, no se borra: las ventas firmadas con él siguen existiendo.'),
            ]);
    }

    #[Override]
    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('meses')
            ->columns([
                TextColumn::make('meses')
                    ->label('Plazo')
                    ->badge()
                    ->color(fn (PlanDePago $record): string => $record->esDeContado() ? 'success' : 'info')
                    ->formatStateUsing(fn (PlanDePago $record): string => $record->nombre())
                    ->sortable(),

                TextColumn::make('precio_vara')
                    ->label('Precio por vara²')
                    ->formatStateUsing(fn (PlanDePago $record): string => $record->montoPrecioVara()->formateado())
                    ->alignEnd()
                    ->sortable(),

                /*
                | El lote tipo del plano, para que el número se pueda leer
                | sin sacar la calculadora. 250 vr² es la medida de 233 de
                | los 301 lotes de Praderas.
                */
                TextColumn::make('referencia')
                    ->label('Un lote de 250 vr²')
                    ->state(fn (PlanDePago $record): string => new Monto(
                        $record->montoPrecioVara()->multiplicarPor('250')->redondeado()
                    )->formateado())
                    ->alignEnd()
                    ->color('gray'),

                IconColumn::make('activo')
                    ->label('Se ofrece')
                    ->boolean()
                    ->alignCenter()
                    ->sortable(),
            ])
            ->headerActions([
                CreateAction::make()->label('Nuevo plan'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->modalDescription('Si este plan ya se usó en una venta, mejor apagalo en vez de borrarlo: la venta conserva su precio congelado, pero el plan deja de aparecer en el historial.'),
            ])
            ->defaultSort('meses')
            ->paginationPageOptions([25, 50, 100])
            ->emptyStateHeading('Todavía no hay planes de pago')
            ->emptyStateDescription('Cargá el precio de la vara² a cada plazo. Mientras esté vacío, el plano cotiza cada lote a su propio precio y no muestra cuotas.')
            ->emptyStateIcon('heroicon-o-calculator');
    }
}
