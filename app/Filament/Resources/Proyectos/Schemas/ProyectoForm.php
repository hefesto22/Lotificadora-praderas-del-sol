<?php

declare(strict_types=1);

namespace App\Filament\Resources\Proyectos\Schemas;

use App\Domain\Enums\ServicioDelProyecto;
use App\Filament\Schemas\Components\MayusculasField;
use App\Models\Proyecto;
use Carbon\CarbonInterface;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;

/**
 * Patrón aprobado del §10: tabs persistentes en el query string, y un tab
 * "Estado" enriquecido con la información del registro — nunca un tab con
 * un solo toggle adentro.
 */
class ProyectoForm
{
    public static function configure(Schema $schema): Schema
    {
        /** @var array<string, string> $departamentos */
        $departamentos = config('honduras.localizacion.departamentos', []);

        return $schema
            ->components([
                Tabs::make('Proyecto')
                    ->persistTabInQueryString()
                    ->columnSpanFull()
                    ->tabs([

                        Tab::make('Identificación')
                            ->icon('heroicon-o-identification')
                            ->schema([
                                MayusculasField::make('nombre')
                                    ->label('Nombre del proyecto')
                                    ->required()
                                    ->maxLength(150)
                                    ->unique(ignoreRecord: true)
                                    ->prefixIcon('heroicon-o-building-office-2')
                                    ->placeholder('Ej: Residencial Praderas del Sol')
                                    ->columnSpanFull(),

                                MayusculasField::make('codigo')
                                    ->label('Código')
                                    ->required()
                                    ->maxLength(10)
                                    ->unique(ignoreRecord: true)
                                    ->prefixIcon('heroicon-o-hashtag')
                                    ->placeholder('RPS')
                                    // §10.3: los campos que componen un correlativo se
                                    // congelan en edición. Cambiar el código partiría la
                                    // serie de contratos en dos.
                                    ->disabledOn('edit')
                                    ->helperText(
                                        'Prefijo de los números de contrato: RPS-2026-0065. '.
                                        'No se puede cambiar después de crear el proyecto, '.
                                        'porque partiría la numeración en dos series.'
                                    ),
                            ])
                            ->columns(2),

                        Tab::make('Ubicación')
                            ->icon('heroicon-o-map-pin')
                            ->schema([
                                MayusculasField::make('municipio')
                                    ->label('Municipio')
                                    ->maxLength(100)
                                    ->prefixIcon('heroicon-o-map')
                                    ->placeholder('Ej: Cucuyagua'),

                                Select::make('departamento')
                                    ->label('Departamento')
                                    ->options($departamentos)
                                    ->searchable()
                                    ->placeholder('Seleccionar departamento'),

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
                                    ->label('Proyecto activo')
                                    ->default(true)
                                    ->onColor('success')
                                    ->offColor('danger')
                                    ->helperText(
                                        'Un proyecto inactivo deja de ofrecerse en formularios '.
                                        'nuevos, pero conserva intactos sus lotes, ventas e histórico.'
                                    ),

                                Toggle::make('plano_esquematico')
                                    ->label('El plano es esquemático')
                                    ->onColor('warning')
                                    ->offColor('gray')
                                    ->helperText(
                                        'Se enciende solo cuando el sistema acomoda el plano. Significa que '.
                                        'el dibujo respeta el área de cada lote pero NO su ubicación real en '.
                                        'el terreno. Apagalo cuando la geometría venga del plano del topógrafo.'
                                    ),

                                Section::make('Plano público')
                                    ->description('La página que se le manda al cliente por WhatsApp.')
                                    ->icon('heroicon-o-globe-alt')
                                    ->schema([
                                        Toggle::make('plano_publico')
                                            ->label('Publicar el plano')
                                            ->onColor('success')
                                            ->offColor('gray')
                                            ->helperText(
                                                'Nace apagado a propósito. Encenderlo publica en internet el '.
                                                'plano, las medidas y LA LISTA DE PRECIOS COMPLETA: la '.
                                                'competencia también la puede abrir. Los lotes vendidos se ven '.
                                                'ocupados, pero nunca a qué precio se vendieron ni a quién.'
                                            ),

                                        TextInput::make('slug')
                                            ->label('Dirección de la página')
                                            ->prefix(url('/plano').'/')
                                            ->maxLength(80)
                                            ->unique(ignoreRecord: true)
                                            ->rule('regex:/^[a-z0-9]+(-[a-z0-9]+)*$/')
                                            ->helperText(
                                                'Minúsculas, números y guiones. Si lo dejás vacío se arma solo '.
                                                'con el nombre del proyecto. Cambiarla ROMPE todos los links '.
                                                'que ya se mandaron por WhatsApp, y nadie relaciona una cosa '.
                                                'con la otra: cambiala solo si hace falta de verdad.'
                                            ),

                                        CheckboxList::make('servicios')
                                            ->label('Servicios e infraestructura')
                                            ->options(ServicioDelProyecto::opciones())
                                            ->columns(2)
                                            ->helperText(
                                                'Lo que el desarrollo YA tiene. Es lo que termina de convencer '.
                                                'a quien ya vio el precio. Si no marcás ninguno, la sección '.
                                                'simplemente no aparece en la página.'
                                            ),

                                        TextInput::make('whatsapp')
                                            ->label('WhatsApp para consultas')
                                            ->tel()
                                            ->maxLength(20)
                                            ->placeholder('9999-9999')
                                            ->helperText(
                                                'A este número llegan las consultas de ESTE proyecto. Sin '.
                                                'número, la página se ve igual pero no muestra el botón — que '.
                                                'es mejor que mandar al cliente a un chat que nadie lee.'
                                            ),
                                    ])
                                    ->columns(1),

                                Section::make('Información del registro')
                                    ->description('Datos de auditoría que mantiene el sistema.')
                                    ->icon('heroicon-o-information-circle')
                                    ->visibleOn('edit')
                                    ->columns(2)
                                    ->schema([
                                        Placeholder::make('bloques_registrados')
                                            ->label('Bloques registrados')
                                            ->content(fn (?Proyecto $record): string => (string) ($record?->bloques()->count() ?? 0)),

                                        Placeholder::make('lotes_registrados')
                                            ->label('Lotes registrados')
                                            ->content(fn (?Proyecto $record): string => (string) ($record?->lotes()->count() ?? 0)),

                                        Placeholder::make('creado_en')
                                            ->label('Creado')
                                            ->content(static function (?Proyecto $record): string {
                                                $fecha = $record?->getAttribute('created_at');

                                                return $fecha instanceof CarbonInterface ? fechaLarga($fecha) : '—';
                                            }),

                                        Placeholder::make('actualizado_en')
                                            ->label('Última modificación')
                                            ->content(static function (?Proyecto $record): string {
                                                $fecha = $record?->getAttribute('updated_at');

                                                return $fecha instanceof CarbonInterface ? haceCuanto($fecha) : '—';
                                            }),
                                    ]),
                            ]),
                    ]),
            ]);
    }
}
