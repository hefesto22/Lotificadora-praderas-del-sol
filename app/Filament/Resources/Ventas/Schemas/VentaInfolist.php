<?php

declare(strict_types=1);

namespace App\Filament\Resources\Ventas\Schemas;

use App\Domain\Enums\EstadoVenta;
use App\Models\Compromiso;
use App\Models\Venta;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * La ficha que se mira cuando el cliente pregunta por su expediente.
 *
 * El estado de cuenta completo —cuota por cuota, con vencidas y días de
 * atraso— es su propia pantalla y viene después. Acá está el encabezado:
 * quién, qué lotes, por cuánto y cómo va pagando.
 */
class VentaInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Contrato')
                    ->icon('heroicon-o-document-text')
                    ->columns(4)
                    ->schema([
                        TextEntry::make('numero_contrato')
                            ->label('Número de contrato')
                            ->weight('bold')
                            ->copyable(),

                        TextEntry::make('numero_expediente')
                            ->label('Expediente')
                            ->formatStateUsing(static fn (?int $state): string => $state === null
                                ? '—'
                                : str_pad((string) $state, 4, '0', STR_PAD_LEFT)),

                        TextEntry::make('fecha_contrato')
                            ->label('Firmado')
                            ->date('d/m/Y'),

                        TextEntry::make('estado')
                            ->label('Estado')
                            ->badge()
                            ->formatStateUsing(static fn (EstadoVenta $state): string => $state->etiqueta())
                            ->color(static fn (EstadoVenta $state): string => $state->color()),
                    ]),

                Section::make('Quiénes compran')
                    ->icon('heroicon-o-users')
                    ->schema([
                        TextEntry::make('clientes')
                            ->label('Titular y copropietarios')
                            ->getStateUsing(static fn (Venta $record): string => $record->clientes
                                ->map(static fn ($cliente): string => $cliente->getAttribute('nombre')
                                    .($cliente->getAttribute('pivot')?->getAttribute('titular') === true ? ' (titular)' : ''))
                                ->implode(' · ')),
                    ]),

                Section::make('Lotes')
                    ->icon('heroicon-o-map')
                    ->description('Área, precio y valor quedaron congelados al firmar.')
                    ->schema([
                        TextEntry::make('compromisos')
                            ->label('Lotes del contrato')
                            ->getStateUsing(static fn (Venta $record): string => $record->compromisos
                                ->map(static fn (Compromiso $c): string => sprintf(
                                    '%s — %s vr² — %s',
                                    (string) $c->lote?->getAttribute('codigo'),
                                    (string) $c->getAttribute('area_varas'),
                                    $c->montoValor()->formateado(),
                                ))
                                ->implode("\n"))
                            ->listWithLineBreaks(),
                    ]),

                Section::make('Dinero')
                    ->icon('heroicon-o-banknotes')
                    ->columns(4)
                    ->schema([
                        TextEntry::make('valor_total')
                            ->label('Valor total')
                            ->formatStateUsing(static fn (Venta $record): string => $record->montoValorTotal()->formateado()),

                        TextEntry::make('prima')
                            ->label('Prima')
                            ->formatStateUsing(static fn (Venta $record): string => $record->montoPrima()->formateado()),

                        TextEntry::make('saldo_financiar')
                            ->label('Saldo financiado')
                            ->formatStateUsing(static fn (Venta $record): string => $record->montoSaldoFinanciar()->formateado()),

                        TextEntry::make('saldo_pendiente')
                            ->label('Saldo pendiente hoy')
                            ->weight('bold')
                            ->getStateUsing(static fn (Venta $record): string => $record->saldoPendiente()->formateado()),

                        TextEntry::make('cuota_mensual')
                            ->label('Cuota mensual')
                            ->formatStateUsing(static fn (Venta $record): string => $record->montoCuotaMensual()?->formateado() ?? 'Venta de contado'),

                        TextEntry::make('plazo_meses')
                            ->label('Plazo')
                            ->formatStateUsing(static fn (int $state): string => $state === 0 ? 'Contado' : $state.' meses'),

                        TextEntry::make('dia_pago')
                            ->label('Día de pago')
                            ->formatStateUsing(static fn (int $state): string => 'Cada '.$state." de mes\n"),

                        TextEntry::make('cuotas_pendientes')
                            ->label('Cuotas por pagar')
                            ->getStateUsing(static fn (Venta $record): string => $record->cuotas()->pendientes()->count()
                                .' de '.$record->cuotas()->count()),
                    ]),

                Section::make('Observaciones')
                    ->icon('heroicon-o-chat-bubble-bottom-center-text')
                    ->visible(static fn (Venta $record): bool => filled($record->getAttribute('observaciones')))
                    ->schema([
                        TextEntry::make('observaciones')
                            ->label('')
                            ->prose(),
                    ]),
            ]);
    }
}
