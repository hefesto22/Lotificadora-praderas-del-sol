<?php

declare(strict_types=1);

namespace App\Filament\Resources\Clientes\Schemas;

use App\Models\Cliente;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * El DNI, el RTN y el teléfono se muestran formateados con los métodos del
 * modelo, no con notación de punto ni con formatStateUsing sobre el crudo
 * (§9.A.14).
 */
class ClienteInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Identificación')
                    ->icon('heroicon-o-identification')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('nombre')
                            ->label('Nombre completo')
                            ->columnSpanFull(),

                        TextEntry::make('dni')
                            ->label('DNI')
                            ->state(fn (Cliente $record): ?string => $record->dniFormateado())
                            ->placeholder('Sin DNI cargado')
                            ->copyable(),

                        TextEntry::make('rtn')
                            ->label('RTN')
                            ->state(fn (Cliente $record): ?string => $record->rtnFormateado())
                            ->placeholder('Sin RTN')
                            ->copyable(),
                    ]),

                Section::make('Contacto')
                    ->icon('heroicon-o-phone')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('telefono')
                            ->label('Teléfono')
                            ->state(fn (Cliente $record): ?string => $record->telefonoFormateado())
                            ->placeholder('—'),

                        TextEntry::make('correo')
                            ->label('Correo')
                            ->placeholder('—'),

                        TextEntry::make('direccion')
                            ->label('Dirección')
                            ->placeholder('—')
                            ->columnSpanFull(),

                        TextEntry::make('observaciones')
                            ->label('Observaciones')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ]),

                Section::make('Estado')
                    ->icon('heroicon-o-power')
                    ->columns(2)
                    ->schema([
                        IconEntry::make('activo')
                            ->label('Activo')
                            ->boolean(),

                        TextEntry::make('created_at')
                            ->label('Registrado')
                            ->dateTime('d/m/Y H:i'),

                        TextEntry::make('deleted_at')
                            ->label('Archivado')
                            ->dateTime('d/m/Y H:i')
                            ->placeholder('No'),
                    ]),
            ]);
    }
}
