{{--
    El estado de cuenta, como sale por la impresora de la ventanilla.

    El CSS va adentro por lo mismo que en el recibo: esta pagina no vive en el
    panel, no tiene el CSS de Filament ni el de Tailwind, y no debe depender de
    que un build de assets haya corrido.

    Una seccion por lote, porque desde el 5-ago cada uno lleva su propio plazo
    y su propia escalera. Con un solo lote se lee igual que siempre.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Estado de cuenta {{ $cuenta->venta->getAttribute('numero_contrato') }}</title>
    @include('comun.fuente')
    <style>
        @page { size: letter; margin: 12mm; }
        * { box-sizing: border-box; }

        body {
            margin: 0; padding: 1.5rem 1rem;
            background: #f4f4f5; color: #18181b;
            font-family: var(--olympo-fuente);
            font-size: 13px; line-height: 1.5;
        }

        .hoja {
            max-width: 190mm; margin: 0 auto; padding: 1.75rem 2rem 2rem;
            background: #fff; border: 1px solid #e4e4e7; border-radius: .5rem;
        }

        .barra { max-width: 190mm; margin: 0 auto .875rem; display: flex; gap: .5rem; justify-content: flex-end; }
        .barra button, .barra a {
            padding: .5rem .9rem; border: 1px solid #d4d4d8; border-radius: .375rem;
            background: #fff; color: #27272a; font: inherit; font-size: 13px;
            text-decoration: none; cursor: pointer;
        }
        .barra button { background: #18181b; border-color: #18181b; color: #fff; font-weight: 600; }

        .encabezado { display: flex; justify-content: space-between; gap: 1.5rem; align-items: flex-start; }
        .emisor { max-width: 60%; }
        .emisor img { max-height: 52px; max-width: 190px; margin-bottom: .5rem; }
        .emisor .residencial { font-size: 15px; font-weight: 700; letter-spacing: -.01em; }
        .emisor .linea { color: #52525b; font-size: 12px; }

        .titulo { text-align: right; white-space: nowrap; }
        .titulo .que { font-size: 15px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; }
        .titulo .contrato { font-size: 20px; font-weight: 700; font-variant-numeric: tabular-nums; }
        .titulo .corte { font-size: 12px; color: #52525b; }

        hr { border: 0; border-top: 1px solid #e4e4e7; margin: 1.125rem 0; }

        .datos { display: grid; grid-template-columns: 1fr 1fr; gap: .5rem 1.5rem; }
        .dato .rotulo { font-size: 10px; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: #71717a; }
        .dato .valor { font-weight: 600; }

        h2 {
            margin: 1.5rem 0 .5rem; font-size: 11px; font-weight: 700;
            letter-spacing: .08em; text-transform: uppercase; color: #71717a;
        }

        /* ── Resumen ── */
        .resumen { display: grid; grid-template-columns: repeat(4, 1fr); gap: .625rem; }
        .caja { padding: .625rem .75rem; background: #fafafa; border: 1px solid #e4e4e7; border-radius: .5rem; }
        .caja .rotulo { font-size: 10px; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: #71717a; }
        .caja .cifra { font-size: 16px; font-weight: 700; font-variant-numeric: tabular-nums; }
        .caja.saldo { background: #eff6ff; border-color: rgba(37, 99, 235, .3); }
        .caja.saldo .cifra { color: #1d4ed8; }
        .caja.atraso { background: #fef2f2; border-color: rgba(220, 38, 38, .3); }
        .caja.atraso .cifra { color: #b91c1c; }

        .aviso {
            margin-top: .75rem; padding: .625rem .75rem; border-radius: .5rem;
            background: #fef2f2; border: 1px solid rgba(220, 38, 38, .3);
            color: #7f1d1d; font-size: 12px; line-height: 1.6;
        }
        .aviso.aldia { background: #f0fdf4; border-color: rgba(22, 163, 74, .3); color: #14532d; }

        /* ── Lotes ── */
        .lote { margin-top: 1.25rem; page-break-inside: auto; }
        .lote .cabeza {
            display: flex; justify-content: space-between; align-items: baseline; gap: 1rem;
            padding-bottom: .375rem; border-bottom: 2px solid #18181b;
        }
        .lote .codigo { font-size: 14px; font-weight: 700; }
        .lote .condiciones { font-size: 11.5px; color: #52525b; }
        .lote .cancelado { color: #15803d; font-weight: 700; font-size: 11px; letter-spacing: .06em; text-transform: uppercase; }

        table { width: 100%; border-collapse: collapse; font-size: 12px; margin-top: .25rem; }
        th {
            padding: .375rem .5rem; text-align: right; white-space: nowrap;
            font-size: 10px; font-weight: 600; letter-spacing: .05em; text-transform: uppercase;
            color: #71717a; border-bottom: 1px solid #e4e4e7;
        }
        td { padding: .3125rem .5rem; text-align: right; font-variant-numeric: tabular-nums; border-bottom: 1px dashed #e4e4e7; }
        th:first-child, td:first-child { text-align: left; padding-left: 0; }
        th:last-child, td:last-child { padding-right: 0; }
        tfoot td { border-bottom: 0; border-top: 2px solid #18181b; font-weight: 700; padding-top: .5rem; }
        tr.pagada td { color: #a1a1aa; }
        tr.vencida td { color: #b91c1c; }
        tr.vencida td:first-child { font-weight: 700; }
        .marca { font-size: 10px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; }

        .nota { margin-top: 1rem; font-size: 11px; line-height: 1.6; color: #71717a; }
        .firmas { display: grid; grid-template-columns: 1fr 1fr; gap: 3rem; margin-top: 2.5rem; }
        .firma { border-top: 1px solid #a1a1aa; padding-top: .375rem; text-align: center; font-size: 11px; color: #52525b; }

        @media print {
            body { padding: 0; background: #fff; font-size: 11.5px; }
            .barra { display: none !important; }
            .hoja { max-width: none; border: 0; border-radius: 0; padding: 0; }
            .caja, .aviso { background: transparent; }
            .lote { page-break-inside: avoid; }
        }
    </style>
</head>
<body>

<div class="barra">
    <a href="{{ url()->previous() }}">Volver</a>
    <button type="button" onclick="window.print()">Imprimir</button>
</div>

<div class="hoja">

    <div class="encabezado">
        <div class="emisor">
            @if ($logo)
                <img src="{{ $logo }}" alt="">
            @endif
            <div class="residencial">{{ $emisor['residencial'] ?? '' }}</div>
            @if ($emisor['nombre'] ?? null)
                <div class="linea">{{ $emisor['nombre'] }}</div>
            @endif
            @if ($emisor['rtn'] ?? null)
                <div class="linea">RTN {{ $emisor['rtn'] }}</div>
            @endif
            @if ($emisor['direccion'] ?? null)
                <div class="linea">{{ $emisor['direccion'] }}</div>
            @endif
        </div>

        <div class="titulo">
            <div class="que">Estado de cuenta</div>
            <div class="contrato">{{ $cuenta->venta->getAttribute('numero_contrato') }}</div>
            {{-- La fecha de corte va bien visible: el mismo expediente impreso
                 mañana dice otra cosa, y sin esto parecería que cambió solo. --}}
            <div class="corte">Al {{ $cuenta->alDia->format('d/m/Y') }}</div>
        </div>
    </div>

    <hr>

    <div class="datos">
        <div class="dato">
            <div class="rotulo">Titular</div>
            <div class="valor">{{ $cuenta->titular?->getAttribute('nombre') ?? '—' }}</div>
        </div>
        <div class="dato">
            <div class="rotulo">Expediente</div>
            <div class="valor">{{ $cuenta->venta->getAttribute('numero_expediente') ?? '—' }}</div>
        </div>
        <div class="dato">
            <div class="rotulo">Fecha del contrato</div>
            <div class="valor">{{ $cuenta->venta->getAttribute('fecha_contrato')?->format('d/m/Y') ?? '—' }}</div>
        </div>
        <div class="dato">
            <div class="rotulo">Lotes</div>
            <div class="valor">{{ count($cuenta->lotes) }}</div>
        </div>

        @if ($cuenta->copropietarios !== [])
            {{-- R8: el estado de cuenta sale a nombre del titular, con los
                 demás listados. Cualquiera de ellos puede pagar. --}}
            <div class="dato" style="grid-column: 1 / -1;">
                <div class="rotulo">Copropietarios</div>
                <div class="valor">
                    {{ collect($cuenta->copropietarios)->map(fn ($c) => $c->getAttribute('nombre'))->implode(' · ') }}
                </div>
            </div>
        @endif
    </div>

    <h2>Resumen</h2>

    <div class="resumen">
        <div class="caja">
            <div class="rotulo">Valor del contrato</div>
            <div class="cifra">{{ $cuenta->valorTotal->formateado() }}</div>
        </div>
        <div class="caja">
            <div class="rotulo">Total pagado</div>
            <div class="cifra">{{ $cuenta->totalPagado()->formateado() }}</div>
        </div>
        <div class="caja saldo">
            <div class="rotulo">Saldo</div>
            <div class="cifra">{{ $cuenta->saldo->formateado() }}</div>
        </div>
        <div class="caja {{ $cuenta->estaAlDia() ? '' : 'atraso' }}">
            <div class="rotulo">Cuotas</div>
            <div class="cifra">{{ $cuenta->cuotasPagadas }} / {{ $cuenta->cuotasTotales }}</div>
        </div>
    </div>

    @if ($cuenta->llevaInteres)
        {{-- La pregunta que hace un cliente el primer mes: «¿cuánto de lo que
             pagué bajó mi deuda?». Contestada en palabras y no solo en
             columnas, porque el que pregunta no está leyendo la tabla. --}}
        <p class="nota">
            De lo que lleva pagado en cuotas, <strong>{{ $cuenta->capitalPagado->formateado() }}</strong>
            bajó el precio del terreno y <strong>{{ $cuenta->interesPagado->formateado() }}</strong>
            fue interés por el financiamiento. Al terminar de pagar habrá cubierto
            {{ $cuenta->interes->formateado() }} de interés en total; faltan
            {{ $cuenta->interesPendiente()->formateado() }}.
        </p>
    @endif

    @if ($cuenta->estaCancelado())
        <p class="aviso aldia"><strong>Este contrato está totalmente pagado.</strong> No queda saldo pendiente.</p>
    @elseif ($cuenta->estaAlDia())
        <p class="aviso aldia">
            <strong>Al día.</strong> Su próximo pago es de {{ $cuenta->cuotaDelMes()->formateado() }}
            @if ($cuenta->proximaCuota())
                y vence el {{ $cuenta->proximaCuota()->getAttribute('fecha_vencimiento')?->format('d/m/Y') }}.
            @else
                .
            @endif
        </p>
    @else
        {{-- R2: se muestran los días de atraso porque la administración los
             necesita, pero NO generan cargo. El cliente atrasado debe
             exactamente lo mismo que debía el día del vencimiento. --}}
        @php
            $vencidas = $cuenta->cuotasVencidas.' '.($cuenta->cuotasVencidas === 1 ? 'cuota vencida' : 'cuotas vencidas');
            $atraso = $cuenta->diasDeAtraso().' '.($cuenta->diasDeAtraso() === 1 ? 'día' : 'días');
        @endphp
        <p class="aviso">
            <strong>{{ $vencidas }} por {{ $cuenta->vencido->formateado() }}</strong>, con {{ $atraso }} de atraso en la más antigua.
            El atraso <strong>no genera ningún recargo</strong>: usted debe exactamente lo mismo que debía el día del vencimiento.
        </p>
    @endif

    @foreach ($cuenta->lotes as $lote)
        <div class="lote">
            <div class="cabeza">
                <span class="codigo">Lote {{ $lote->codigo }}</span>
                <span class="condiciones">
                    @if ($lote->estaCancelado())
                        <span class="cancelado">Cancelado</span>
                    @else
                        Cuota {{ $lote->cuota?->formateado() ?? '—' }} ·
                        Saldo <strong>{{ $lote->saldo->formateado() }}</strong>
                        @if ($lote->termina)
                            · Termina {{ $lote->termina->format('m/Y') }}
                        @endif
                    @endif
                </span>
            </div>

            @if ($lote->cuotas === [])
                <p class="nota">Este lote se pagó de contado: la prima cubrió el valor completo.</p>
            @else
                <table>
                    <thead>
                        <tr>
                            <th>Cuota</th>
                            <th>Vence</th>
                            {{-- Capital e interés solo si el plan cobra. Con tasa 0
                                 —Praderas, R1— serían dos columnas de ceros al lado
                                 del número que importa. --}}
                            @if ($lote->llevaInteres)
                                <th>Capital</th>
                                <th>Interés</th>
                            @endif
                            <th>Monto</th>
                            <th>Pagado</th>
                            <th>Falta</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($lote->cuotas as $cuota)
                            <tr class="{{ $cuota->estaPagada() ? 'pagada' : ($cuota->estaVencida() ? 'vencida' : '') }}">
                                <td>{{ $cuota->getAttribute('numero') }}</td>
                                <td>{{ $cuota->getAttribute('fecha_vencimiento')?->format('d/m/Y') ?? '—' }}</td>
                                @if ($lote->llevaInteres)
                                    <td>{{ $cuota->montoCapital()->formateado() }}</td>
                                    <td>{{ $cuota->montoInteres()->formateado() }}</td>
                                @endif
                                <td>{{ $cuota->montoTotal()->formateado() }}</td>
                                <td>{{ $cuota->montoPagado()->esCero() ? '—' : $cuota->montoPagado()->formateado() }}</td>
                                <td>{{ $cuota->saldo()->esCero() ? '—' : $cuota->saldo()->formateado() }}</td>
                                <td class="marca">
                                    @if ($cuota->estaPagada())
                                        Pagada
                                    @elseif ($cuota->estaVencida())
                                        Vencida · {{ $cuota->diasDeAtraso() }} d
                                    @else
                                        Pendiente
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        {{-- ⚠️ Con interés, «Monto» NO es valor − prima: eso es el
                             capital. La columna suma capital + interés, y si el pie
                             dijera solo el capital no cerraría contra sus propias
                             filas — que es exactamente lo que revisa un cliente con
                             la calculadora en la mano. --}}
                        <tr>
                            <td colspan="2">Total del lote</td>
                            @if ($lote->llevaInteres)
                                <td>{{ $lote->valor->restar($lote->prima)->formateado() }}</td>
                                <td>{{ $lote->interes->formateado() }}</td>
                            @endif
                            <td>{{ $lote->valor->restar($lote->prima)->sumar($lote->interes)->formateado() }}</td>
                            <td>{{ $lote->pagado->formateado() }}</td>
                            <td>{{ $lote->saldo->formateado() }}</td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>

                @if ($lote->huboMora())
                    {{-- Solo cuando la hubo. Un renglón «Mora L 0.00» en un papel
                         que se le entrega al cliente invita a preguntar por un
                         cobro que no existe. --}}
                    <p class="nota">
                        Mora de este lote: se cobró {{ $lote->mora->formateado() }}@unless ($lote->moraCondonada->esCero()) y se condonó {{ $lote->moraCondonada->formateado() }}@endunless.
                        La mora se paga aparte de la cuota y no baja el saldo del lote.
                    </p>
                @endif
            @endif
        </div>
    @endforeach

    <p class="nota">
        La prima de {{ $cuenta->prima->formateado() }} se pagó al firmar y ya está descontada del
        saldo que generó estas cuotas; por eso no aparece como una cuota más.
        Documento informativo de uso interno. No es comprobante fiscal.
    </p>

    <div class="firmas">
        <div class="firma">Recibí conforme</div>
        <div class="firma">{{ $emisor['residencial'] ?? '' }}</div>
    </div>
</div>

<script>
    // Se abre para mirar tanto como para imprimir —a diferencia del recibo—,
    // así que el diálogo NO sale solo: quien quiere el papel aprieta el botón.
</script>

</body>
</html>
