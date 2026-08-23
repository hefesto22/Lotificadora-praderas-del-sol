<?php

declare(strict_types=1);

namespace App\Filament\Resources\Ventas\Schemas;

use App\Domain\Enums\ConceptoDeRecibo;
use App\Domain\Enums\EstadoCompromiso;
use App\Domain\Enums\EstadoVenta;
use App\Domain\ValueObjects\Monto;
use App\Filament\Support\Cuadros;
use App\Models\Compromiso;
use App\Models\Recibo;
use App\Models\Venta;
use Carbon\CarbonInterface;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

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
                                ->filter(static fn ($cliente): bool => $cliente->getAttribute('pivot')
                                    ?->getAttribute('titular_hasta') === null)
                                /*
                                 * El titular primero. La relacion ordena por
                                 * `orden` —la posicion en el contrato— y quien
                                 * entra por una cesion se sienta al final de
                                 * esa lista: sin esto, un rotulo que promete
                                 * «Titular y copropietarios» saca al titular
                                 * ultimo. sortByDesc es estable, asi que los
                                 * copropietarios conservan su orden.
                                 */
                                ->sortByDesc(static fn ($cliente): bool => $cliente->getAttribute('pivot')
                                    ?->getAttribute('titular') === true)
                                ->map(static fn ($cliente): string => $cliente->getAttribute('nombre')
                                    .($cliente->getAttribute('pivot')?->getAttribute('titular') === true ? ' (titular)' : ''))
                                ->implode(' · ')),

                        /*
                         * La cesión de derechos, cuando la hubo (22-ago-2026).
                         *
                         * Va acá y no solo en la bitácora porque la pregunta
                         * —«¿este expediente no era de fulano?»— se hace en
                         * ventanilla, con el cliente enfrente, y nadie va a ir
                         * a Registros de actividad a filtrar por subject_id.
                         *
                         * `hidden()` y no un guion: en el 99% de los
                         * expedientes esto no pasó nunca, y una fila vacía en
                         * todas las fichas para el caso raro es ruido.
                         */
                        TextEntry::make('titulares_anteriores')
                            ->label('Fue de')
                            ->getStateUsing(static fn (Venta $record): string => $record->titularesAnteriores()
                                ->map(static function ($cliente): string {
                                    $hasta = $cliente->getAttribute('pivot')?->getAttribute('titular_hasta');

                                    return $cliente->getAttribute('nombre')
                                        .($hasta instanceof CarbonInterface ? ' (hasta el '.$hasta->format('d/m/Y').')' : '');
                                })
                                ->implode(' · '))
                            ->hidden(static fn (Venta $record): bool => $record->titularesAnteriores()->isEmpty()),
                    ]),

                /*
                 * Un renglón por lote, con SU plazo y SU cuota: desde el
                 * 5-ago-2026 un contrato puede llevar el primero a 12 meses y
                 * el tercero a 48. El cuadro es el mismo que se ve en el plano
                 * antes de firmar —lo arma Cuadros—, para que el papel y la
                 * pantalla no puedan decir cosas distintas.
                 *
                 * ═══ POR QUE ESTA SECCION VA A TODO EL ANCHO ═══
                 *
                 * La página de un ViewRecord arma la ficha en DOS columnas
                 * (`ViewRecord::infolist()`), así que sin este `columnSpanFull`
                 * el cuadro cae en media pantalla. Son siete columnas de
                 * dinero: no entran, y la tarjeta no las achica ni las manda
                 * a scroll —las RECORTA—. La cuota del último lote se veía
                 * «L. 54,1». De ahí para abajo la ficha va en una sola
                 * columna: dejar «Dinero» en media dejaba media página vacía.
                 */
                Section::make('Lotes')
                    ->icon('heroicon-o-map')
                    ->description('Área, precio, plazo y valor quedaron congelados al firmar.')
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('compromisos')
                            ->hiddenLabel()
                            // array_values: `Collection::all()` devuelve
                            // array<int, ...> y Cuadros pide una lista.
                            ->getStateUsing(static fn (Venta $record): HtmlString => Cuadros::lotes(
                                array_values($record->compromisos
                                    // Un lote rescindido ya no es de este
                                    // contrato: dejarlo aca contradiria a los
                                    // totales, que se recalcularon sin el.
                                    ->reject(static fn (Compromiso $c): bool => $c->getAttribute('estado') === EstadoCompromiso::Rescindido)
                                    ->map(static fn (Compromiso $c): array => [
                                        'codigo' => (string) $c->lote?->getAttribute('codigo'),
                                        'area'   => (string) $c->getAttribute('area_varas'),
                                        'plazo'  => (int) $c->getAttribute('plazo_meses'),
                                        'precio' => new Monto((string) $c->getAttribute('precio_vara')),
                                        'valor'  => $c->montoValor(),
                                        'prima'  => new Monto((string) ($c->getAttribute('prima') ?? '0')),
                                        'cuota'  => $c->cuotas()->value('monto') === null
                                            ? null
                                            : new Monto((string) $c->cuotas()->value('monto')),
                                    ])
                                    ->all()),
                                'Este contrato no tiene lotes.',
                                $record->proyecto?->unidadDeArea(),
                            )),
                    ]),

                Section::make('Dinero')
                    ->icon('heroicon-o-banknotes')
                    ->columns(4)
                    ->columnSpanFull()
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

                        /*
                         * ═══ 🔴 UNA VENTA DE CONTADO NO TIENE NADA DE ESTO ═══
                         *
                         * Lo agarró Mauricio el 14-ago-2026 mirando la ficha
                         * del contrato REB-2026-0004: decía «Día de pago: cada
                         * 5 de mes» y «Primer mes» en una venta que se pagó
                         * entera el día que se firmó. Cuatro cuadros
                         * contestando preguntas que nadie hizo, y uno de ellos
                         * —el día de pago— contradiciendo al de al lado, que
                         * decía «Contado».
                         *
                         * Es el mismo criterio que en el modal de venta: sin
                         * cuotas no hay plazo, ni día de pago, ni escalera. En
                         * su lugar va lo único que importa saber de un contado:
                         * que ya se cobró, cuándo, y que el papel está emitido.
                         */
                        TextEntry::make('pagado_al_firmar')
                            ->label('Cómo se pagó')
                            ->visible(static fn (Venta $record): bool => $record->esDeContado())
                            ->columnSpan(2)
                            ->weight('bold')
                            ->getStateUsing(static fn (Venta $record): string => sprintf(
                                'De contado: %s el %s',
                                $record->montoValorTotal()->formateado(),
                                $record->getAttribute('fecha_contrato')?->format('d/m/Y') ?? 'el día del contrato',
                            ))
                            /*
                             * 🔴 Y QUE PAPEL SALIO, con su numero.
                             *
                             * «¿Dónde está el botón para pagar lo de contado y
                             * que se emita la factura?» — Mauricio, 14-ago-2026,
                             * mirando esta misma ficha. No faltaba ningun boton:
                             * la factura ya se habia emitido al firmar. Lo que
                             * faltaba era decirlo ACA, con el numero, en vez de
                             * mandarlo a buscar a una pestaña.
                             *
                             * Una consulta mas en la ficha de UN expediente es
                             * barata; que alguien dude de si el papel existe,
                             * no.
                             */
                            ->helperText(static function (Venta $record): string {
                                $papel = self::papelDeLaPrima($record);

                                return $papel === null
                                    ? 'Se cobró completo al firmar (R5). Todavía no hay papel emitido.'
                                    : sprintf('Se cobró completo al firmar (R5). Salió %s — está en la pestaña Recibos.', $papel);
                            }),

                        TextEntry::make('cuota_mensual')
                            ->label('Primer mes')
                            ->visible(static fn (Venta $record): bool => ! $record->esDeContado())
                            ->formatStateUsing(static fn (Venta $record): string => $record->montoCuotaMensual()?->formateado() ?? '—')
                            ->helperText('Lo más alto: es lo que paga mientras todos los lotes siguen vivos.'),

                        TextEntry::make('plazo_meses')
                            ->label('Hasta')
                            ->visible(static fn (Venta $record): bool => ! $record->esDeContado())
                            ->formatStateUsing(static fn (int $state): string => 'el mes '.$state),

                        TextEntry::make('dia_pago')
                            ->label('Día de pago')
                            ->visible(static fn (Venta $record): bool => ! $record->esDeContado())
                            ->formatStateUsing(static fn (int $state): string => 'Cada '.$state." de mes\n"),

                        TextEntry::make('cuotas_pendientes')
                            ->label('Cuotas por pagar')
                            ->visible(static fn (Venta $record): bool => ! $record->esDeContado())
                            ->getStateUsing(static fn (Venta $record): string => $record->cuotas()->deLotesVivos()->pendientes()->count()
                                .' de '.$record->cuotas()->deLotesVivos()->count()),

                        /*
                         * La escalera. Sale de las CUOTAS GUARDADAS y no de un
                         * recálculo: el contrato es lo que está en la base.
                         */
                        TextEntry::make('escalera')
                            ->label('Lo que paga por mes')
                            ->visible(static fn (Venta $record): bool => ! $record->esDeContado())
                            ->columnSpanFull()
                            ->getStateUsing(static fn (Venta $record): HtmlString => Cuadros::escalera($record->tramosDeCuotas())),
                    ]),

                Section::make('Observaciones')
                    ->icon('heroicon-o-chat-bubble-bottom-center-text')
                    ->columnSpanFull()
                    ->visible(static fn (Venta $record): bool => filled($record->getAttribute('observaciones')))
                    ->schema([
                        TextEntry::make('observaciones')
                            ->label('')
                            ->prose(),
                    ]),
            ]);
    }

    /**
     * Con qué papel se cobró la prima, si ya salió alguno.
     *
     * Null cuando no hay ninguno, y eso es un caso real y no un error: una
     * venta cuya prima quedó cubierta entera por la seña de un apartado no
     * emite recibo nuevo —esa plata ya tuvo el suyo— y una cartera vieja
     * importada en papel puede no tenerlo.
     */
    private static function papelDeLaPrima(Venta $venta): ?string
    {
        $recibo = $venta->recibos()
            ->where('concepto', ConceptoDeRecibo::Prima->value)
            ->whereNull('anulado_el')
            ->latest('id')
            ->first();

        if (! $recibo instanceof Recibo) {
            return null;
        }

        return $recibo->esFactura()
            ? 'la factura '.$recibo->numeroDelPapel()
            : 'el recibo N.º '.$recibo->folio();
    }
}
