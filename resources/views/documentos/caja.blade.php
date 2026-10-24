{{--
    El reporte de caja, como sale por la impresora — 24-oct-2026.

    Es la pantalla «Caja» en una hoja: lo que entró en un rango de días, de uno,
    varios o todos los proyectos, abierto por forma de pago, día por día, y con
    el detalle de cada recibo al final. Lo arma ReporteDeCajaController con el
    mismo CajaPorDia de la pantalla.

    El CSS va adentro por lo mismo que en el estado mensual: esta página no vive
    en el panel y no puede depender de que un build de assets haya corrido. Las
    medidas son las de esa hoja —186 mm, que entran en A4 y en carta—.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reporte de caja · {{ $caja->periodoEscrito() }}</title>
    @include('comun.fuente')
    <style>
        @page { size: A4; margin: 14mm 12mm; }
        * { box-sizing: border-box; }

        body {
            margin: 0; padding: 1.5rem 1rem;
            background: #f4f4f5; color: #18181b;
            font-family: var(--olympo-fuente);
            font-size: 12px; line-height: 1.45;
        }

        .hoja {
            max-width: 186mm; margin: 0 auto; padding: 1.75rem 2rem 2rem;
            background: #fff; border: 1px solid #e4e4e7; border-radius: .5rem;
        }

        .barra { max-width: 186mm; margin: 0 auto .875rem; display: flex; gap: .5rem; justify-content: flex-end; }
        .barra button, .barra a {
            padding: .5rem .9rem; border: 1px solid #d4d4d8; border-radius: .375rem;
            background: #fff; color: #27272a; font: inherit; font-size: 13px;
            text-decoration: none; cursor: pointer;
        }
        .barra button { background: #18181b; border-color: #18181b; color: #fff; font-weight: 600; }

        .encabezado { display: flex; justify-content: space-between; gap: 1.5rem; align-items: flex-start; }
        .emisor { max-width: 58%; display: flex; gap: .75rem; align-items: flex-start; }
        .emisor img { height: 46px; width: auto; max-width: 140px; object-fit: contain; flex: none; }
        .emisor .datos { display: block; min-width: 0; }
        .emisor .residencial { font-size: 13.5px; font-weight: 700; letter-spacing: -.01em; line-height: 1.25; }
        .emisor .linea { color: #52525b; font-size: 10.5px; line-height: 1.4; }

        .titulo { text-align: right; max-width: 42%; }
        .titulo .que { font-size: 11px; font-weight: 700; letter-spacing: .09em; text-transform: uppercase; color: #52525b; white-space: nowrap; }
        .titulo .periodo { font-size: 17px; font-weight: 700; line-height: 1.2; letter-spacing: -.015em; }

        .regla { border: 0; border-top: 2px solid #18181b; border-bottom: 1px solid #18181b; height: 3px; margin: .875rem 0 0; }

        .ficha { display: flex; flex-wrap: wrap; gap: 0 2rem; padding: .5rem 0 .875rem; border-bottom: 1px solid #e4e4e7; }
        .ficha div { font-size: 10px; }
        .ficha .rotulo { font-weight: 700; letter-spacing: .07em; text-transform: uppercase; color: #a1a1aa; }
        .ficha .valor { font-size: 11.5px; font-weight: 600; color: #27272a; }

        h2 {
            margin: 1.375rem 0 .25rem; font-size: 10px; font-weight: 700;
            letter-spacing: .11em; text-transform: uppercase; color: #18181b;
            padding-bottom: .1875rem; border-bottom: 1px solid #18181b;
        }
        h2 .numeral { color: #a1a1aa; margin-right: .375rem; }

        table { width: 100%; border-collapse: collapse; font-size: 11.5px; }
        th {
            padding: .25rem .5rem; text-align: right; white-space: nowrap;
            font-size: 9px; font-weight: 700; letter-spacing: .07em; text-transform: uppercase;
            color: #71717a; border-bottom: 1px solid #d4d4d8;
        }
        td { padding: .25rem .5rem; text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        th:first-child, td:first-child { text-align: left; padding-left: 0; }
        th:last-child, td:last-child { padding-right: 0; }

        .cifra { width: 30%; }
        tbody tr.renglon td { border-bottom: 1px dotted #e4e4e7; }
        tbody tr.cierre td {
            border-top: 2px solid #18181b; border-bottom: 3px double #18181b;
            font-weight: 700; font-size: 13px; padding: .4375rem .5rem;
        }
        tbody tr.cierre td:first-child { padding-left: 0; }
        tbody tr.cierre td:last-child { padding-right: 0; }
        td.rotulo { color: #3f3f46; }
        .apagado { color: #a1a1aa; }

        .franja { margin-top: .5rem; padding: .4375rem .625rem; background: #fafafa; border: 1px solid #e4e4e7; border-radius: .25rem; }
        .franja .titulo-franja { display: block; font-size: 9px; font-weight: 700; letter-spacing: .07em; text-transform: uppercase; color: #a1a1aa; }
        .franja table { margin-top: .25rem; font-size: 10.5px; }
        .franja th { padding: .1875rem .5rem; border-bottom-color: #e4e4e7; }
        .franja td { padding: .25rem .5rem; color: #52525b; }
        .franja td:first-child { white-space: normal; color: #18181b; }
        .franja tbody tr + tr td { border-top: 1px dotted #e4e4e7; }

        /* Día por día, y el anexo: el encabezado se repite en cada hoja impresa;
           sin eso, la página 2 es una columna de números sin título. */
        table.dias thead, table.recibos thead { display: table-header-group; }
        table.dias td, table.dias th { font-size: 10.5px; padding: .1875rem .4375rem; }
        table.dias tbody td { border-bottom: 1px dotted #e4e4e7; }
        table.recibos td, table.recibos th { font-size: 9.5px; padding: .1875rem .3125rem; }
        table.recibos tbody td { border-bottom: 1px dotted #e4e4e7; }
        table.recibos td.texto, table.recibos th.texto { text-align: left; }
        table.recibos td.nombre { white-space: normal; max-width: 44mm; }
        table.dias tfoot td, table.recibos tfoot td {
            border-top: 2px solid #18181b; border-bottom: 3px double #18181b;
            font-weight: 700; padding-top: .3125rem;
        }
        table.dias th:first-child, table.dias td:first-child,
        table.recibos th:first-child, table.recibos td:first-child { padding-left: 0; }
        table.dias th:last-child, table.dias td:last-child,
        table.recibos th:last-child, table.recibos td:last-child { padding-right: 0; }

        .aviso {
            margin-top: .5rem; padding: .4375rem .625rem; border-radius: .25rem;
            background: #fef2f2; border: 1px solid rgba(220, 38, 38, .35);
            color: #7f1d1d; font-size: 10.5px; line-height: 1.5;
        }
        .aviso.tranquilo { background: #fafafa; border-color: #d4d4d8; color: #52525b; }

        .firmas { display: grid; grid-template-columns: 1fr 1fr; gap: 3rem; margin-top: 2.5rem; }
        .firma { border-top: 1px solid #71717a; padding-top: .25rem; text-align: center; font-size: 10px; color: #52525b; }

        .notas { margin-top: 1.25rem; padding-top: .5rem; border-top: 1px solid #e4e4e7; font-size: 9px; line-height: 1.5; color: #a1a1aa; }
        .notas b { color: #71717a; }
        .pie { margin-top: .5rem; display: flex; justify-content: space-between; gap: 1rem; font-size: 9px; color: #a1a1aa; }

        .en-marco body { padding: .75rem .5rem; }
        .en-marco .barra a { display: none; }

        @media print {
            body { padding: 0; background: #fff; font-size: 10.5px; }
            .barra { display: none !important; }
            .hoja { max-width: none; border: 0; border-radius: 0; padding: 0; }
            .aviso, .franja { background: transparent; }
            table { page-break-inside: auto; }
            tr { page-break-inside: avoid; }
            h2 { page-break-after: avoid; }
            .firmas { page-break-inside: avoid; }
        }
    </style>

    {{-- En el head y no al final: la clase queda puesta antes de que el
         navegador pinte, y «Volver» no parpadea adentro del marco. --}}
    <script>
        if (window.self !== window.top) {
            document.documentElement.classList.add('en-marco');
        }
    </script>
</head>
<body>

<div class="barra">
    <a href="{{ url()->previous() }}">Volver</a>
    <button type="button" onclick="window.print()">Imprimir</button>
</div>

<div class="hoja">

    <div class="encabezado">
        <div class="emisor">
            @if ($logoDelProyecto ?? null)
                <img src="{{ $logoDelProyecto }}" alt="">
            @elseif ($logo)
                <img src="{{ $logo }}" alt="">
            @endif

            <div class="datos">
                <div class="residencial">{{ $emisor['residencial'] ?? ($emisor['nombre'] ?? '') }}</div>
                @if (($emisor['nombre'] ?? null) && ($emisor['residencial'] ?? null))
                    <div class="linea">{{ $emisor['nombre'] }}</div>
                @endif
                @if ($emisor['rtn'] ?? null)
                    <div class="linea">RTN {{ $emisor['rtn'] }}</div>
                @endif
                @if ($emisor['direccion'] ?? null)
                    <div class="linea">{{ $emisor['direccion'] }}</div>
                @endif
                @if ($emisor['telefono'] ?? null)
                    <div class="linea">Tel. {{ $emisor['telefono'] }}</div>
                @endif
            </div>
        </div>

        <div class="titulo">
            <div class="que">Reporte de caja</div>
            <div class="periodo">{{ $caja->periodoEscrito() }}</div>
        </div>
    </div>

    <hr class="regla">

    <div class="ficha">
        <div>
            <div class="rotulo">Proyectos</div>
            <div class="valor">{{ $proyectos }}</div>
        </div>
        <div>
            <div class="rotulo">Período</div>
            <div class="valor">{{ $caja->desde->format('d/m/Y') }} — {{ $caja->hasta->format('d/m/Y') }}</div>
        </div>
        <div>
            <div class="rotulo">Recibos</div>
            <div class="valor">{{ $caja->recibos }}</div>
        </div>
        @if ($soloDe !== null)
            <div>
                <div class="rotulo">Recibido por</div>
                <div class="valor">{{ $soloDe }}</div>
            </div>
        @endif
    </div>

    @if ($recortado)
        <div class="aviso">Se muestra un año como mucho: el período se recortó desde la fecha final hacia atrás.</div>
    @endif

    @if (! $caja->huboMovimiento())
        <div class="aviso tranquilo">No entró dinero en este período.</div>
    @endif

    {{-- ── 1 · Lo que entró ──────────────────────────────────────── --}}

    <h2><span class="numeral">1</span> Lo que entró</h2>

    <table>
        <thead>
            <tr>
                <th>Forma de pago</th>
                <th class="cifra">Monto</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($caja->formas() as $forma)
                <tr class="renglon">
                    <td class="rotulo">{{ $forma->etiqueta() }}</td>
                    <td>{{ $caja->deLaForma($forma)->formateado() }}</td>
                </tr>
            @empty
                <tr class="renglon">
                    <td class="rotulo apagado">Sin cobros en el período</td>
                    <td class="apagado">{{ $caja->total->formateado() }}</td>
                </tr>
            @endforelse

            <tr class="cierre">
                <td>Total recibido</td>
                <td>{{ $caja->total->formateado() }}</td>
            </tr>
        </tbody>
    </table>

    @if ($quienes !== [])
        {{-- Quién tiene el billete en la mano: la mitad de un arqueo. --}}
        <div class="franja">
            <span class="titulo-franja">Quién lo recibió</span>
            <table>
                <tbody>
                    @foreach ($quienes as $quien)
                        <tr>
                            <td>{{ $quien['nombre'] }}</td>
                            <td>{{ $quien['total']->formateado() }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if ($caja->huboMovimiento())

        {{-- ── 2 · Día por día ──────────────────────────────────────── --}}

        <h2><span class="numeral">2</span> Día por día</h2>

        <table class="dias">
            <thead>
                <tr>
                    <th>Día</th>
                    <th>Recibos</th>
                    @foreach ($caja->formas() as $forma)
                        <th>{{ $forma->etiqueta() }}</th>
                    @endforeach
                    <th>Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($caja->dias as $dia)
                    <tr>
                        <td>{{ $caja->nombreDelDia($dia) }}</td>
                        <td>{{ $dia->recibos }}</td>
                        @foreach ($caja->formas() as $forma)
                            @php
                                $monto = $dia->deLaForma($forma);
                            @endphp
                            <td class="{{ $monto->esCero() ? 'apagado' : '' }}">{{ $monto->esCero() ? '—' : $monto->formateado() }}</td>
                        @endforeach
                        <td><b>{{ $dia->total->formateado() }}</b></td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td>Total</td>
                    <td>{{ $caja->recibos }}</td>
                    @foreach ($caja->formas() as $forma)
                        <td>{{ $caja->deLaForma($forma)->formateado() }}</td>
                    @endforeach
                    <td>{{ $caja->total->formateado() }}</td>
                </tr>
            </tfoot>
        </table>

        {{-- ── 3 · Los recibos ──────────────────────────────────────── --}}

        <h2><span class="numeral">3</span> Los recibos</h2>

        <table class="recibos">
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th class="texto">Recibo</th>
                    <th class="texto">Recibí de</th>
                    <th class="texto">Lote</th>
                    <th class="texto">Concepto</th>
                    <th class="texto">Forma</th>
                    <th class="texto">Lo recibió</th>
                    <th>Monto</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($renglones as $renglon)
                    <tr>
                        <td>{{ $renglon['fecha']->format('d/m/Y') }}</td>
                        <td class="texto">{{ $renglon['folio'] }}</td>
                        <td class="texto nombre">{{ $renglon['nombre'] }}</td>
                        <td class="texto">{{ $renglon['lotes'] }}</td>
                        <td class="texto">{{ $renglon['concepto'] }}</td>
                        <td class="texto">{{ $renglon['forma'] }}</td>
                        <td class="texto nombre">{{ $renglon['recibio'] }}</td>
                        <td>{{ $renglon['monto']->formateado() }}</td>
                    </tr>
                @endforeach
            </tbody>
            @if ($faltan === 0)
                <tfoot>
                    <tr>
                        <td colspan="7">Total · {{ $caja->recibos }} {{ $caja->recibos === 1 ? 'recibo' : 'recibos' }}</td>
                        <td>{{ $caja->total->formateado() }}</td>
                    </tr>
                </tfoot>
            @endif
        </table>

        @if ($faltan > 0)
            <div class="aviso tranquilo">
                Y {{ $faltan }} {{ $faltan === 1 ? 'recibo' : 'recibos' }} más en el período: la lista completa está en
                Recibos, en el panel. Los totales de arriba sí los incluyen.
            </div>
        @endif

    @endif

    <div class="firmas">
        <div class="firma">Entregó</div>
        <div class="firma">Recibió</div>
    </div>

    <div class="notas">
        <b>Notas.</b> Es lo que entró por recibo en el período, por la fecha del recibo: no cuenta los
        anulados e incluye las señas de apartados. No resta gastos ni devoluciones.
        @if ($soloDe !== null)
            Solo incluye lo que recibió {{ $soloDe }}.
        @endif
    </div>

    <div class="pie">
        <span>{{ $emisor['residencial'] ?? ($emisor['nombre'] ?? '') }} · Reporte de caja · {{ $caja->periodoEscrito() }}</span>
        <span>Emitido el {{ now()->format('d/m/Y H:i') }}</span>
    </div>

</div>

</body>
</html>
