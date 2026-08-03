<?php

declare(strict_types=1);

namespace App\Filament\Resources\Clientes\Schemas;

use App\Filament\Schemas\Components\DNIField;
use App\Filament\Schemas\Components\RTNField;
use App\Filament\Schemas\Components\TelefonoHondurasField;
use App\Models\Cliente;
use Carbon\CarbonInterface;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;

/**
 * Patrón aprobado del §10.
 *
 * OJO con el unique() del DNI y del RTN: la tabla tiene soft deletes y sus
 * índices únicos son PARCIALES (`WHERE ... AND deleted_at IS NULL`). La
 * regla `unique` de Laravel no sabe nada de eso y sí mira las filas
 * borradas, así que sin el `whereNull('deleted_at')` el formulario diría
 * "ya existe" por un cliente archivado que la persona no puede ver, mientras
 * la base lo habría aceptado sin chistar. Validación y base tienen que decir
 * lo mismo.
 */
class ClienteForm
{
    public static function configure(Schema $schema): Schema
    {
        $soloVivos = static fn (Unique $rule): Unique => $rule->whereNull('deleted_at');

        return $schema
            ->components([
                Tabs::make('Cliente')
                    ->persistTabInQueryString()
                    ->columnSpanFull()
                    ->tabs([

                        Tab::make('Identificación')
                            ->icon('heroicon-o-identification')
                            ->schema([
                                // §10.4: el auto-mayúsculas NO aplica a nombres de
                                // personas. "María de los Ángeles" no es un código
                                // de catálogo.
                                TextInput::make('nombre')
                                    ->label('Nombre completo')
                                    ->required()
                                    ->maxLength(150)
                                    ->prefixIcon('heroicon-o-user')
                                    ->placeholder('Ej: María de los Ángeles Rodríguez')
                                    ->columnSpanFull(),

                                DNIField::make()
                                    ->unique(ignoreRecord: true, modifyRuleUsing: $soloVivos),

                                RTNField::make()
                                    ->unique(ignoreRecord: true, modifyRuleUsing: $soloVivos)
                                    ->helperText('Opcional. 14 dígitos, solo si el cliente lo tiene.'),
                            ])
                            ->columns(2),

                        Tab::make('Contacto')
                            ->icon('heroicon-o-phone')
                            ->schema([
                                TelefonoHondurasField::make('telefono', 'Teléfono'),

                                TextInput::make('correo')
                                    ->label('Correo electrónico')
                                    ->email()
                                    ->maxLength(150)
                                    ->prefixIcon('heroicon-o-envelope')
                                    ->placeholder('nombre@ejemplo.com')
                                    ->helperText('Opcional. Se guarda en minúsculas.'),

                                Textarea::make('direccion')
                                    ->label('Dirección')
                                    ->rows(3)
                                    ->maxLength(500)
                                    ->columnSpanFull(),

                                Textarea::make('observaciones')
                                    ->label('Observaciones')
                                    ->rows(3)
                                    ->columnSpanFull(),
                            ])
                            ->columns(2),

                        Tab::make('Estado')
                            ->icon('heroicon-o-power')
                            ->schema([
                                Toggle::make('activo')
                                    ->label('Cliente activo')
                                    ->default(true)
                                    ->onColor('success')
                                    ->offColor('danger')
                                    ->helperText(
                                        'Un cliente inactivo deja de ofrecerse al crear ventas '.
                                        'o apartados nuevos, pero conserva intacto todo su '.
                                        'historial de pagos y documentos.'
                                    ),

                                Section::make('Información del registro')
                                    ->description('Datos de auditoría que mantiene el sistema.')
                                    ->icon('heroicon-o-information-circle')
                                    ->visibleOn('edit')
                                    ->columns(2)
                                    ->schema([
                                        Placeholder::make('creado_en')
                                            ->label('Creado')
                                            ->content(static function (?Cliente $record): string {
                                                $fecha = $record?->getAttribute('created_at');

                                                return $fecha instanceof CarbonInterface ? fechaLarga($fecha) : '—';
                                            }),

                                        Placeholder::make('actualizado_en')
                                            ->label('Última modificación')
                                            ->content(static function (?Cliente $record): string {
                                                $fecha = $record?->getAttribute('updated_at');

                                                return $fecha instanceof CarbonInterface ? haceCuanto($fecha) : '—';
                                            }),

                                        Placeholder::make('archivado_en')
                                            ->label('Archivado')
                                            ->content(static function (?Cliente $record): string {
                                                $fecha = $record?->getAttribute('deleted_at');

                                                return $fecha instanceof CarbonInterface ? fechaLarga($fecha) : 'No';
                                            }),

                                        Placeholder::make('identificacion_resumen')
                                            ->label('Identificación')
                                            ->content(static fn (?Cliente $record): string => $record?->dniFormateado() ?? 'Sin DNI cargado'),
                                    ]),
                            ]),
                    ]),
            ]);
    }
}
