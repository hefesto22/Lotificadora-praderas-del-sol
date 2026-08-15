<?php

declare(strict_types=1);

namespace App\Filament\Resources\Facturaciones\RelationManagers;

use App\Models\AutorizacionDeImpresion;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Override;

/**
 * Las autorizaciones del SAR de esta facturación, la de hoy y las de antes.
 *
 * ⚠️ Las viejas NO se borran ni se pisan. Con qué CAI se emitió cada
 * factura es lo primero que pregunta una fiscalización, y una reimpresión
 * tiene que salir con la que llevaba impresa. Renovar es AGREGAR una.
 */
class AutorizacionesRelationManager extends RelationManager
{
    #[Override]
    protected static string $relationship = 'autorizaciones';

    #[Override]
    protected static ?string $title = 'Autorizaciones del SAR';

    #[Override]
    protected static ?string $modelLabel = 'autorización';

    #[Override]
    protected static ?string $pluralModelLabel = 'autorizaciones';

    #[Override]
    public function form(Schema $schema): Schema
    {
        return $schema->components([
            /*
             * 🔴 Sin `rule('regex:...')`. El formato de la CAI NO está
             * publicado por el SAR —ver App\Domain\ValueObjects\CAI—, y una
             * validación inventada rechazaría una autorización de verdad
             * sin que quien la carga pueda saber que el equivocado es el
             * sistema.
             */
            TextInput::make('cai')
                ->label('CAI')
                ->required()
                ->maxLength(100)
                ->helperText('Copiala tal como sale en la autorización. No se valida el formato a propósito: el SAR no lo publica.')
                ->columnSpanFull(),

            TextInput::make('correlativo_desde')
                ->label('Desde el número')
                ->numeric()
                ->required()
                ->minValue(1)
                ->maxValue(99999999),

            TextInput::make('correlativo_hasta')
                ->label('Hasta el número')
                ->numeric()
                ->required()
                ->minValue(1)
                ->maxValue(99999999)
                ->helperText('Ocho dígitos como máximo: el correlativo llega a 99999999 y ahí reinicia.'),

            TextInput::make('proximo_correlativo')
                ->label('El próximo que se va a usar')
                ->numeric()
                ->required()
                ->minValue(1)
                ->maxValue(99999999)
                ->helperText('Al cargar una autorización nueva es el mismo que «desde». Si el talonario ya venía usado, poné el que sigue.'),

            DatePicker::make('autorizada_el')
                ->label('Autorizada el')
                ->required()
                ->default(today()),

            DatePicker::make('fecha_limite_emision')
                ->label('Fecha límite de emisión')
                ->required()
                ->helperText('La que dice la autorización. Dura un año como máximo, y lo que sobre del rango se pierde al vencer.'),

            Textarea::make('observaciones')
                ->label('Notas')
                ->rows(2)
                ->columnSpanFull(),
        ])->columns(2);
    }

    #[Override]
    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('cai')
                    ->label('CAI')
                    ->fontFamily('mono')
                    ->wrap()
                    ->copyable(),

                TextColumn::make('correlativo_desde')
                    ->label('Rango')
                    ->state(static fn (AutorizacionDeImpresion $record): string => sprintf(
                        '%08d — %08d',
                        (int) $record->getAttribute('correlativo_desde'),
                        (int) $record->getAttribute('correlativo_hasta'),
                    ))
                    ->fontFamily('mono'),

                TextColumn::make('proximo_correlativo')
                    ->label('Va por')
                    ->formatStateUsing(static fn (int $state): string => sprintf('%08d', $state))
                    ->fontFamily('mono'),

                TextColumn::make('fecha_limite_emision')
                    ->label('Vence')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('id')
                    ->label('Estado')
                    ->badge()
                    ->state(static function (AutorizacionDeImpresion $record): string {
                        if ($record->estaVencida()) {
                            return 'Vencida';
                        }

                        if ($record->quedanDocumentos() === 0) {
                            return 'Rango agotado';
                        }

                        return $record->convieneRenovar()
                            ? sprintf('Quedan %d — conviene renovar', $record->quedanDocumentos())
                            : sprintf('Quedan %d', $record->quedanDocumentos());
                    })
                    ->color(static function (AutorizacionDeImpresion $record): string {
                        if ($record->estaVencida() || $record->quedanDocumentos() === 0) {
                            return 'danger';
                        }

                        return $record->convieneRenovar() ? 'warning' : 'success';
                    }),
            ])
            ->headerActions([
                CreateAction::make()->label('Cargar una autorización'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->defaultSort('fecha_limite_emision', 'desc');
    }
}
