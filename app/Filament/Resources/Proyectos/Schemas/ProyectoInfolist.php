<?php

declare(strict_types=1);

namespace App\Filament\Resources\Proyectos\Schemas;

use App\Domain\Enums\UnidadDeArea;
use App\Models\Proyecto;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\TextSize;

/**
 * La ficha del proyecto.
 *
 * ═══ POR QUE ESTA DISTRIBUCION ═══
 *
 * El contenido de un ViewRecord se acomoda en una grilla de dos columnas.
 * Antes las tres secciones eran de una columna cada una, así que
 * Identificación y Ubicación quedaban lado a lado y **Estado se iba sola a
 * la izquierda con media pantalla vacía al costado**.
 *
 * Ahora Estado ocupa el ancho completo y reparte sus cuatro datos en cuatro
 * columnas. Además es lo que más se mira —cuántos bloques, cuántos lotes—,
 * así que gana el renglón entero en vez de compartir media pantalla.
 */
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
                        TextEntry::make('nombre')
                            ->label('Nombre del proyecto')
                            ->weight('medium')
                            ->columnSpanFull(),

                        TextEntry::make('codigo')
                            ->label('Código')
                            ->badge()
                            ->helperText('Prefijo de los contratos'),

                        /*
                        | 🔴 Faltaba, y se notó en pantalla el 14-ago-2026:
                        | esto decide cómo se lee TODO el desarrollo —el área
                        | del lote, la del precio, la del plano y la del
                        | contrato— y para verlo había que entrar a Editar.
                        */
                        TextEntry::make('unidad_area')
                            ->label('Unidad del área')
                            ->badge()
                            ->color('gray')
                            ->formatStateUsing(fn (UnidadDeArea $state): string => $state->etiqueta()),

                        /*
                        | Un dato que antes no se veía en ningún lado y que
                        | cambia cómo se lee todo el plano: si la geometría
                        | es la del levantamiento o un trazado aproximado.
                        */
                        TextEntry::make('plano_esquematico')
                            ->label('Plano')
                            ->badge()
                            ->formatStateUsing(fn (bool $state): string => $state ? 'Esquemático' : 'Geometría real')
                            ->color(fn (bool $state): string => $state ? 'warning' : 'success'),
                    ]),

                /*
                | Con qué papel se le cobra a la gente de este desarrollo.
                |
                | 🔴 Esta sección nació de un ensayo en pantalla: un cobro de
                | El Bambú salió como recibo interno teniendo facturación con
                | CAI, y para siquiera empezar a mirar por qué había que
                | entrar a Editar → pestaña Facturación. Un dato que decide
                | qué documento recibe el cliente no puede estar escondido a
                | dos clics.
                */
                Section::make('Con qué papel cobra')
                    ->icon('heroicon-o-document-text')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('facturacion.nombre')
                            ->label('Facturación')
                            ->badge()
                            ->color('success')
                            ->placeholder('Solo recibo interno — sin CAI')
                            ->helperText(fn (Proyecto $record): string => $record->getAttribute('facturacion_id') === null
                                ? 'Los cobros de este desarrollo salen como comprobante de caja, con «NO VÁLIDO PARA CRÉDITO FISCAL».'
                                : 'Los cobros de este desarrollo salen como FACTURA con CAI.'),

                        TextEntry::make('facturacion.rtn')
                            ->label('RTN del emisor')
                            ->placeholder('—'),
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
                    ->columns(4)
                    ->columnSpanFull()
                    ->schema([
                        IconEntry::make('activo')
                            ->label('Activo')
                            ->boolean(),

                        /*
                        | `withCount` y no `->count()` por entrada: son dos
                        | consultas extra por visita a la ficha. A esta
                        | escala da igual, pero el §12 pide `withCount()`
                        | para contadores y no hay razón para desviarse.
                        */
                        TextEntry::make('bloques_count')
                            ->label('Bloques')
                            ->size(TextSize::Large)
                            ->weight('bold')
                            ->state(fn (Proyecto $record): int => $record->bloques()->count()),

                        TextEntry::make('lotes_count')
                            ->label('Lotes')
                            ->size(TextSize::Large)
                            ->weight('bold')
                            ->state(fn (Proyecto $record): int => $record->lotes()->count()),

                        TextEntry::make('created_at')
                            ->label('Creado')
                            ->dateTime('d/m/Y H:i'),
                    ]),

                /*
                | Las observaciones del plano traen el número de CICH, quién
                | levantó y quién dibujó. Es lo que hay que citar cuando algo
                | del plano se discute, y hasta ahora no se veía en pantalla.
                */
                Section::make('Observaciones')
                    ->icon('heroicon-o-document-text')
                    ->columnSpanFull()
                    ->collapsible()
                    ->visible(fn (Proyecto $record): bool => filled($record->getAttribute('observaciones')))
                    ->schema([
                        TextEntry::make('observaciones')
                            ->label('')
                            ->prose(),
                    ]),
            ]);
    }
}
