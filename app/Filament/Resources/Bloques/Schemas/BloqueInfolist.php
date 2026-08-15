<?php

declare(strict_types=1);

namespace App\Filament\Resources\Bloques\Schemas;

use App\Filament\Support\Unidades;
use App\Models\Bloque;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

class BloqueInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Identificación')
                    ->icon('heroicon-o-identification')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('proyecto.nombre')->label('Proyecto'),
                        TextEntry::make('nombre')->label('Bloque')->badge(),
                        TextEntry::make('orden')->label('Orden'),
                    ]),

                Section::make('Plano contra lo cargado')
                    ->description('El plano declara; el sistema cuenta. Si difieren, faltan lotes por cargar.')
                    ->icon('heroicon-o-map')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('area_total_varas')->label('Área del plano')->suffix(static fn (?Model $record): string => ' '.Unidades::de($record)->plural())->placeholder('—'),
                        TextEntry::make('lotes_planificados')->label('Lotes del plano')->placeholder('—'),
                        TextEntry::make('lotes_cargados')
                            ->label('Lotes cargados')
                            ->state(fn (Bloque $record): int => $record->lotesRegistrados())
                            ->badge()
                            ->color('success'),
                    ]),

                Section::make('Registro')
                    ->icon('heroicon-o-information-circle')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('created_at')->label('Creado')->dateTime('d/m/Y H:i'),
                        TextEntry::make('updated_at')->label('Última modificación')->dateTime('d/m/Y H:i'),
                        TextEntry::make('observaciones')->label('Observaciones')->placeholder('—')->columnSpanFull(),
                    ]),
            ]);
    }
}
