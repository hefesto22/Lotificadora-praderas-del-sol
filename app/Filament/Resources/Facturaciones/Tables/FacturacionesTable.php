<?php

declare(strict_types=1);

namespace App\Filament\Resources\Facturaciones\Tables;

use App\Models\AutorizacionDeImpresion;
use App\Models\Facturacion;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * El listado que se mira cuando alguien pregunta «¿con qué facturamos?».
 *
 * La columna que importa es la última: cuánto le queda a la autorización.
 * Una CAI que se vence en marzo con 40 facturas por delante es un trámite
 * que hay que empezar ahora, no una fila más de una tabla.
 */
final class FacturacionesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('nombre')
                    ->label('Facturación')
                    ->searchable()
                    ->sortable()
                    ->weight('medium')
                    ->wrap(),

                TextColumn::make('proyectos_count')
                    ->label('Proyectos')
                    ->counts('proyectos')
                    ->alignCenter()
                    ->badge()
                    ->color('gray'),

                /*
                 * El número tal como sale impreso, con el correlativo que
                 * toca. Verlo armado es la forma más rápida de darse cuenta
                 * de que el establecimiento quedó en 000 por descuido.
                 */
                TextColumn::make('codigo_establecimiento')
                    ->label('Próximo número')
                    ->state(static function (Facturacion $record): string {
                        $vigente = $record->autorizacionVigente();

                        return $vigente instanceof AutorizacionDeImpresion
                            ? $record->numeroCompleto((int) $vigente->getAttribute('proximo_correlativo'))
                            : 'sin autorización vigente';
                    })
                    ->fontFamily('mono'),

                TextColumn::make('autorizaciones_count')
                    ->label('Le queda')
                    ->state(static function (Facturacion $record): string {
                        $vigente = $record->autorizacionVigente();

                        if (! $vigente instanceof AutorizacionDeImpresion) {
                            return 'Hay que cargar la autorización';
                        }

                        return sprintf(
                            '%d facturas · vence en %d días',
                            $vigente->quedanDocumentos(),
                            max(0, $vigente->diasParaVencer()),
                        );
                    })
                    ->badge()
                    ->color(static function (Facturacion $record): string {
                        $vigente = $record->autorizacionVigente();

                        if (! $vigente instanceof AutorizacionDeImpresion) {
                            return 'danger';
                        }

                        return $vigente->convieneRenovar() ? 'warning' : 'success';
                    }),

                IconColumn::make('activa')
                    ->label('Activa')
                    ->boolean()
                    ->alignCenter(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->defaultSort('nombre');
    }
}
