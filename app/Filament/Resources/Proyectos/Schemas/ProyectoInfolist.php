<?php

declare(strict_types=1);

namespace App\Filament\Resources\Proyectos\Schemas;

use App\Models\Proyecto;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProyectoInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Identificación')
                    ->icon('heroicon-o-identification')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('nombre')->label('Nombre del proyecto'),
                        TextEntry::make('codigo')->label('Código')->badge(),
                    ]),

                Section::make('Ubicación')
                    ->icon('heroicon-o-map-pin')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('municipio')->label('Municipio')->placeholder('—'),
                        TextEntry::make('departamento')->label('Departamento')->placeholder('—'),
                        TextEntry::make('direccion')->label('Dirección')->placeholder('—')->columnSpanFull(),
                    ]),

                Section::make('Estado')
                    ->icon('heroicon-o-power')
                    ->columns(2)
                    ->schema([
                        IconEntry::make('activo')->label('Activo')->boolean(),
                        TextEntry::make('bloques_count')->label('Bloques')->state(fn (Proyecto $record): int => $record->bloques()->count()),
                        TextEntry::make('lotes_count')->label('Lotes')->state(fn (Proyecto $record): int => $record->lotes()->count()),
                        TextEntry::make('created_at')->label('Creado')->dateTime('d/m/Y H:i'),
                    ]),
            ]);
    }
}
