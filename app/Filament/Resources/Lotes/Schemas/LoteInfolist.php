<?php

declare(strict_types=1);

namespace App\Filament\Resources\Lotes\Schemas;

use App\Domain\Enums\EstadoLote;
use App\Models\Lote;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class LoteInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Ubicación')
                    ->icon('heroicon-o-map-pin')
                    ->columns(4)
                    ->schema([
                        // El código va primero y copiable: es lo que se dicta
                        // por teléfono y lo que va impreso en el contrato.
                        TextEntry::make('codigo')
                            ->label('Código')
                            ->weight('bold')
                            ->copyable()
                            ->copyMessage('Código copiado'),
                        TextEntry::make('proyecto.nombre')->label('Proyecto'),
                        TextEntry::make('bloque.nombre')->label('Bloque')->badge()->color('gray'),
                        TextEntry::make('numero')->label('Lote'),
                    ]),

                Section::make('Medidas y precio')
                    ->icon('heroicon-o-calculator')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('area_varas')->label('Área')->suffix(' varas²'),
                        TextEntry::make('precio_vara')->label('Precio por vara²')->prefix('L '),
                        TextEntry::make('valor_formateado')
                            ->label('Valor')
                            ->state(fn (Lote $record): string => $record->montoValor()->formateado())
                            ->weight('bold'),
                    ]),

                Section::make('Estado')
                    ->icon('heroicon-o-flag')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('estado')
                            ->label('Estado')
                            ->badge()
                            ->color(fn (EstadoLote $state): string => $state->color())
                            ->formatStateUsing(fn (EstadoLote $state): string => $state->etiqueta()),

                        TextEntry::make('editabilidad')
                            ->label('Área y precio')
                            ->state(fn (Lote $record): string => $record->getRawOriginal('estado') === EstadoLote::Vendido->value
                                ? 'Congelados por la venta'
                                : 'Editables'),

                        TextEntry::make('observaciones')->label('Observaciones')->placeholder('—')->columnSpanFull(),
                        TextEntry::make('created_at')->label('Creado')->dateTime('d/m/Y H:i'),
                        TextEntry::make('updated_at')->label('Última modificación')->dateTime('d/m/Y H:i'),
                    ]),
            ]);
    }
}
