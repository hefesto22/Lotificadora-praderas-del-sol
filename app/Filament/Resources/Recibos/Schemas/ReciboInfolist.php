<?php

declare(strict_types=1);

namespace App\Filament\Resources\Recibos\Schemas;

use App\Domain\Enums\ConceptoDeRecibo;
use App\Domain\Enums\FormaDePago;
use App\Domain\ValueObjects\MontoEnLetras;
use App\Models\Recibo;
use DateTimeInterface;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

/**
 * La ficha del recibo: a qué fue el dinero y cuántas veces salió el papel.
 *
 * Es lo que se mira cuando alguien reclama. Mirarla no imprime nada — para el
 * papel está el botón, y ese sí queda registrado.
 */
class ReciboInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Recibo')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('numero')
                            ->label('Número')
                            ->weight('bold')
                            ->formatStateUsing(static fn (Recibo $record): string => $record->folio()),

                        /*
                         * Los datos fiscales salen del RECIBO y no de la
                         * facturacion de hoy: son los que llevaba impresos
                         * ese papel. Por eso esta ficha contesta «¿con que
                         * autorizacion se emitio esta?» sin que nadie tenga
                         * que cruzar dos pantallas — que es la primera
                         * pregunta de una fiscalizacion.
                         *
                         * Los renglones no salen cuando es recibo interno:
                         * cuatro campos vacios en la ficha de todos los dias
                         * ensucian la pantalla del caso normal.
                         */
                        TextEntry::make('numero_factura')
                            ->label('Factura')
                            ->weight('bold')
                            ->copyable()
                            ->visible(static fn (Recibo $record): bool => $record->esFactura()),

                        TextEntry::make('cai')
                            ->label('CAI')
                            ->columnSpanFull()
                            ->visible(static fn (Recibo $record): bool => $record->esFactura()),

                        TextEntry::make('rango_desde')
                            ->label('Rango autorizado')
                            ->state(static fn (Recibo $record): ?string => $record->rangoAutorizado())
                            ->visible(static fn (Recibo $record): bool => $record->esFactura()),

                        TextEntry::make('fecha_limite_emision')
                            ->label('Fecha límite de emisión')
                            ->date('d/m/Y')
                            ->visible(static fn (Recibo $record): bool => $record->esFactura()),

                        TextEntry::make('fecha')
                            ->label('Fecha')
                            ->date('d/m/Y'),

                        TextEntry::make('concepto')
                            ->label('Concepto')
                            ->badge()
                            ->formatStateUsing(static fn (Recibo $record): string => $record->getAttribute('concepto') instanceof ConceptoDeRecibo
                                ? $record->getAttribute('concepto')->etiqueta()
                                : '—'),

                        TextEntry::make('cliente.nombre')
                            ->label('Recibí de')
                            ->placeholder('—'),

                        TextEntry::make('venta.numero_contrato')
                            ->label('Contrato')
                            ->placeholder('—'),

                        // NULL cuando el recibo cubre varios lotes: el
                        // código sale de las cuotas que tocó.
                        TextEntry::make('lotes')
                            ->label('Lote')
                            ->badge()
                            ->color('gray')
                            ->state(static fn (Recibo $record): array => $record->codigosDeLotes())
                            ->placeholder('—'),

                        TextEntry::make('forma_pago')
                            ->label('Forma de pago')
                            ->formatStateUsing(static fn (Recibo $record): string => $record->getAttribute('forma_pago') instanceof FormaDePago
                                ? $record->getAttribute('forma_pago')->etiqueta()
                                : '—'),

                        TextEntry::make('referencia')
                            ->label('Referencia')
                            ->placeholder('—'),

                        TextEntry::make('monto')
                            ->label('Monto')
                            ->weight('bold')
                            ->formatStateUsing(static fn (Recibo $record): string => $record->montoTotal()->formateado()),

                        TextEntry::make('en_letras')
                            ->label('En letras')
                            ->columnSpanFull()
                            ->state(static fn (Recibo $record): string => MontoEnLetras::de($record->montoTotal())),
                    ]),

                Section::make('A qué se aplicó')
                    ->description('Un pago puede cubrir media cuota o tres. Acá está cuánto le tocó a cada una.')
                    ->schema([
                        TextEntry::make('detalle')
                            ->hiddenLabel()
                            ->columnSpanFull()
                            ->state(static fn (Recibo $record): HtmlString => self::detalle($record)),
                    ]),

                Section::make('Impresiones')
                    ->description('El original sale limpio; de la segunda vez en adelante el papel dice COPIA.')
                    ->schema([
                        TextEntry::make('impresiones')
                            ->hiddenLabel()
                            ->columnSpanFull()
                            ->state(static fn (Recibo $record): HtmlString => self::impresiones($record)),
                    ]),
            ]);
    }

    // ─── Interno ──────────────────────────────────────────────────────

    /**
     * El reparto, con el renglón de capital cuando lo hay (R21).
     */
    private static function detalle(Recibo $record): HtmlString
    {
        $filas = '';

        foreach ($record->aplicaciones()->with('cuota')->get() as $aplicacion) {
            $cuota = $aplicacion->cuota;

            $filas .= sprintf(
                '<tr><td class="lote">Cuota %s</td><td>%s</td><td class="fuerte">%s</td></tr>',
                e((string) ($cuota?->getAttribute('numero') ?? '—')),
                e($cuota?->getAttribute('fecha_vencimiento')?->format('d/m/Y') ?? '—'),
                e($aplicacion->montoAplicado()->formateado()),
            );
        }

        $aCapital = $record->montoACapital();

        if (! $aCapital->esCero()) {
            $filas .= sprintf(
                '<tr><td class="lote">Abono a capital</td><td class="apagado">reprogramó el plan</td><td class="fuerte">%s</td></tr>',
                e($aCapital->formateado()),
            );
        }

        if ($filas === '') {
            return new HtmlString('<p class="olympo-vacio">Este recibo no se aplicó a ninguna cuota.</p>');
        }

        return new HtmlString(
            '<table class="olympo-tabla"><thead><tr><th>Concepto</th><th>Vence</th><th>Monto</th></tr></thead>'
            .'<tbody>'.$filas.'</tbody></table>'
        );
    }

    private static function impresiones(Recibo $record): HtmlString
    {
        $filas = '';

        foreach ($record->impresiones()->with('createdBy')->get() as $impresion) {
            $cuando = $impresion->getAttribute('created_at');

            $filas .= sprintf(
                '<li><span class="meses">%s — %s</span><span class="monto">%s</span></li>',
                $impresion->esCopia() ? 'Copia' : 'Original',
                e($impresion->createdBy?->getAttribute('name') ?? 'usuario dado de baja'),
                e($cuando instanceof DateTimeInterface ? $cuando->format('d/m/Y H:i') : '—'),
            );
        }

        if ($filas === '') {
            return new HtmlString('<p class="olympo-vacio">Nunca se imprimió.</p>');
        }

        return new HtmlString('<ul class="olympo-escalera">'.$filas.'</ul>');
    }
}
