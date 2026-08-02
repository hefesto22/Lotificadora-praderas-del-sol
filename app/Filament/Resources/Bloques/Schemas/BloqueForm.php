<?php

declare(strict_types=1);

namespace App\Filament\Resources\Bloques\Schemas;

use App\Filament\Schemas\Components\AreaField;
use App\Filament\Schemas\Components\MayusculasField;
use App\Models\Bloque;
use Carbon\CarbonInterface;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;

class BloqueForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('Bloque')
                    ->persistTabInQueryString()
                    ->columnSpanFull()
                    ->tabs([

                        Tab::make('Identificación')
                            ->icon('heroicon-o-identification')
                            ->columns(2)
                            ->schema([
                                Select::make('proyecto_id')
                                    ->label('Proyecto')
                                    ->relationship('proyecto', 'nombre')
                                    ->searchable()
                                    ->preload()
                                    ->required()
                                    // El proyecto define la FK compuesta con los lotes:
                                    // moverlo dejaría lotes apuntando a otro desarrollo.
                                    ->disabledOn('edit')
                                    ->helperText('No se puede mover un bloque de proyecto después de crearlo.'),

                                MayusculasField::make('nombre')
                                    ->label('Nombre del bloque')
                                    ->required()
                                    ->maxLength(30)
                                    ->prefixIcon('heroicon-o-rectangle-group')
                                    ->placeholder('A')
                                    ->helperText('Como figura en el plano: A, B, C…'),

                                TextInput::make('orden')
                                    ->label('Orden de presentación')
                                    ->numeric()
                                    ->default(0)
                                    ->minValue(0)
                                    ->helperText('Controla cómo se listan los bloques. Menor primero.'),
                            ]),

                        Tab::make('Datos del plano')
                            ->icon('heroicon-o-map')
                            ->columns(2)
                            ->schema([
                                AreaField::make('area_total_varas', 'Área total')
                                    // Se guarda como string: el §8.3.1 prohíbe float en
                                    // el camino de áreas y montos, y ->numeric() de
                                    // Filament castearía el estado.
                                    ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? $state : null)
                                    ->helperText('Lo que declara el plano, no la suma de los lotes cargados.'),

                                TextInput::make('lotes_planificados')
                                    ->label('Lotes según el plano')
                                    ->numeric()
                                    ->minValue(0)
                                    ->dehydrateStateUsing(fn (?string $state): ?int => filled($state) ? (int) $state : null)
                                    ->helperText('Sirve para saber cuántos faltan por cargar.'),

                                Textarea::make('observaciones')
                                    ->label('Observaciones')
                                    ->rows(3)
                                    ->columnSpanFull(),
                            ]),

                        Tab::make('Registro')
                            ->icon('heroicon-o-information-circle')
                            ->visibleOn('edit')
                            ->schema([
                                Section::make('Información del registro')
                                    ->description('Datos de auditoría y avance de carga.')
                                    ->icon('heroicon-o-information-circle')
                                    ->columns(2)
                                    ->schema([
                                        Placeholder::make('lotes_registrados')
                                            ->label('Lotes cargados')
                                            ->content(fn (?Bloque $record): string => (string) ($record?->lotesRegistrados() ?? 0)),

                                        Placeholder::make('pendientes')
                                            ->label('Avance según el plano')
                                            ->content(static function (?Bloque $record): string {
                                                if (! $record instanceof Bloque) {
                                                    return '—';
                                                }

                                                $planificados = $record->getAttribute('lotes_planificados');

                                                if (! is_int($planificados)) {
                                                    return 'El plano no declara cuántos lotes tiene este bloque.';
                                                }

                                                $cargados = $record->lotesRegistrados();

                                                return $cargados >= $planificados
                                                    ? "Completo: {$cargados} de {$planificados}."
                                                    : 'Faltan '.($planificados - $cargados)." lotes por cargar ({$cargados} de {$planificados}).";
                                            })
                                            ->columnSpanFull(),

                                        Placeholder::make('creado_en')
                                            ->label('Creado')
                                            ->content(static function (?Bloque $record): string {
                                                $fecha = $record?->getAttribute('created_at');

                                                return $fecha instanceof CarbonInterface ? fechaLarga($fecha) : '—';
                                            }),

                                        Placeholder::make('actualizado_en')
                                            ->label('Última modificación')
                                            ->content(static function (?Bloque $record): string {
                                                $fecha = $record?->getAttribute('updated_at');

                                                return $fecha instanceof CarbonInterface ? haceCuanto($fecha) : '—';
                                            }),
                                    ]),
                            ]),
                    ]),
            ]);
    }
}
