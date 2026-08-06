{{--
    El recibo, como sale por la impresora de la ventanilla.

    ═══ POR QUE EL CSS ESTA ACA ADENTRO ═══

    Esta página no vive dentro del panel: no tiene el CSS de Filament ni el de
    Tailwind, y no debe tenerlos. Un documento que se entrega no puede
    depender de que un build de assets haya corrido — el día que Vite falle,
    el recibo tiene que seguir saliendo igual.

    ═══ MEDIA CARTA SOBRE CARTA ═══

    El contenido va en una columna angosta arriba de la hoja. Así el mismo
    archivo sale bien en carta y en A4, y quien use media carta corta por la
    línea de puntos. No hay tamaño de papel decidido con la contratante; este
    es el que no obliga a decidirlo.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Recibo {{ $recibo->folio() }}</title>
    <style>
        @page { size: letter; margin: 12mm; }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            padding: 1.5rem 1rem;
            background: #f4f4f5;
            color: #18181b;
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
            font-size: 13px;
            line-height: 1.5;
        }

        .hoja {
            max-width: 190mm;
            margin: 0 auto;
            padding: 1.75rem 2rem 2rem;
            background: #fff;
            border: 1px solid #e4e4e7;
            border-radius: .5rem;
        }

        /* ── La barra de arriba no se imprime ── */
        .barra {
            max-width: 190mm;
            margin: 0 auto .875rem;
            display: flex;
            gap: .5rem;
            justify-content: flex-end;
        }
        .barra button, .barra a {
            padding: .5rem .9rem;
            border: 1px solid #d4d4d8;
            border-radius: .375rem;
            background: #fff;
            color: #27272a;
            font: inherit;
            font-size: 13px;
            text-decoration: none;
            cursor: pointer;
        }
        .barra button { background: #18181b; border-color: #18181b; color: #fff; font-weight: 600; }

        /* ── Encabezado ── */
        .encabezado { display: flex; justify-content: space-between; gap: 1.5rem; align-items: flex-start; }
        .emisor { max-width: 60%; }
        .emisor img { max-height: 52px; max-width: 190px; margin-bottom: .5rem; }
        .emisor .residencial { font-size: 15px; font-weight: 700; letter-spacing: -.01em; }
        .emisor .linea { color: #52525b; font-size: 12px; }

        .folio { text-align: right; white-space: nowrap; }
        .folio .rotulo { font-size: 10px; font-weight: 600; letter-spacing: .08em; text-transform: uppercase; color: #71717a; }
        .folio .numero { font-size: 22px; font-weight: 700; font-variant-numeric: tabular-nums; }
        .folio .fecha { font-size: 12px; color: #52525b; }

        .copia {
            display: inline-block; margin-top: .375rem;
            padding: .2rem .55rem; border: 1px solid #dc2626; border-radius: 9999px;
            color: #dc2626; font-size: 10px; font-weight: 700; letter-spacing: .1em;
        }

        hr { border: 0; border-top: 1px solid #e4e4e7; margin: 1.125rem 0; }

        /* ── Datos ── */
        .datos { display: grid; grid-template-columns: 1fr 1fr; gap: .5rem 1.5rem; }
        .dato .rotulo { font-size: 10px; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: #71717a; }
        .dato .valor { font-weight: 600; }

        /* ── Detalle ── */
        h2 { margin: 1.25rem 0 .5rem; font-size: 11px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: #71717a; }

        table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
        th {
            padding: 0 .5rem .375rem; text-align: right; white-space: nowrap;
            font-size: 10px; font-weight: 600; letter-spacing: .06em; text-transform: uppercase;
            color: #71717a; border-bottom: 1px solid #e4e4e7;
        }
        td { padding: .4rem .5rem; text-align: right; font-variant-numeric: tabular-nums; border-bottom: 1px dashed #e4e4e7; }
        th:first-child, td:first-child { text-align: left; padding-left: 0; }
        th:last-child, td:last-child { padding-right: 0; }
        tr:last-child td { border-bottom: 0; }
        .capital td { color: #1d4ed8; font-weight: 600; }

        /* ── Total ── */
        .total { margin-top: 1rem; padding: .75rem 1rem; background: #fafafa; border: 1px solid #e4e4e7; border-radius: .5rem; }
        .total .cifra { display: flex; justify-content: space-between; align-items: baseline; gap: 1rem; }
        .total .cifra .rotulo { font-size: 11px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: #71717a; }
        .total .cifra .monto { font-size: 20px; font-weight: 700; font-variant-numeric: tabular-nums; }
        .total .letras { margin-top: .25rem; font-size: 11px; font-weight: 600; color: #3f3f46; letter-spacing: .02em; }

        .nota { margin-top: .75rem; font-size: 11px; line-height: 1.6; color: #71717a; }

        /* ── Firmas ── */
        .firmas { display: grid; grid-template-columns: 1fr 1fr; gap: 3rem; margin-top: 3rem; }
        .firma { border-top: 1px solid #a1a1aa; padding-top: .375rem; text-align: center; font-size: 11px; color: #52525b; }

        .corte { margin-top: 1.5rem; border-top: 1px dashed #d4d4d8; }

        /* ── Impresión ── */
        @media print {
            body { padding: 0; background: #fff; font-size: 12px; }
            .barra { display: none !important; }
            .hoja { max-width: none; border: 0; border-radius: 0; padding: 0; }
            .total { background: transparent; }
            .copia { border-width: 2px; }
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
            @if ($emisor['telefono'] ?? null)
                <div class="linea">Tel. {{ $emisor['telefono'] }}</div>
            @endif
        </div>

        <div class="folio">
            <div class="rotulo">Recibo de {{ $recibo->concepto?->etiqueta() ?? 'pago' }}</div>
            <div class="numero">N.º {{ $recibo->folio() }}</div>
            <div class="fecha">{{ $recibo->fecha?->format('d/m/Y') }}</div>
            @if ($impresion->esCopia())
                {{-- Dos papeles con el mismo número no pueden pasar por dos cobros. --}}
                <div class="copia">COPIA · {{ $impresion->numero_de_impresion }}.ª impresión</div>
            @endif
        </div>
    </div>

    <hr>

    <div class="datos">
        <div class="dato">
            <div class="rotulo">Recibí de</div>
            <div class="valor">{{ $recibo->cliente?->getAttribute('nombre') ?? '—' }}</div>
        </div>
        <div class="dato">
            <div class="rotulo">Contrato</div>
            <div class="valor">{{ $recibo->venta?->getAttribute('numero_contrato') ?? '—' }}</div>
        </div>
        <div class="dato">
            <div class="rotulo">Lote</div>
            <div class="valor">{{ $recibo->compromiso?->lote?->getAttribute('codigo') ?? '—' }}</div>
        </div>
        <div class="dato">
            <div class="rotulo">Forma de pago</div>
            <div class="valor">
                {{ $recibo->forma_pago?->etiqueta() ?? '—' }}
                @if ($recibo->getAttribute('referencia'))
                    · ref. {{ $recibo->getAttribute('referencia') }}
                @endif
            </div>
        </div>
    </div>

    @if ($recibo->aplicaciones->isNotEmpty() || ! $aCapital->esCero())
        <h2>En concepto de</h2>

        <table>
            <thead>
                <tr>
                    <th>Concepto</th>
                    <th>Vence</th>
                    <th>Monto</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($recibo->aplicaciones as $aplicacion)
                    <tr>
                        <td>Cuota {{ $aplicacion->cuota?->getAttribute('numero') }}</td>
                        <td>{{ $aplicacion->cuota?->getAttribute('fecha_vencimiento')?->format('d/m/Y') ?? '—' }}</td>
                        <td>{{ $aplicacion->montoAplicado()->formateado() }}</td>
                    </tr>
                @endforeach

                @unless ($aCapital->esCero())
                    {{--
                        R21: un abono puede poner al día lo vencido Y bajar el
                        capital con el sobrante. Los dos renglones se imprimen,
                        o el cliente no entiende por qué pagó L 100,000.00 y sus
                        cuotas solo bajaron L 50,000.00.
                    --}}
                    <tr class="capital">
                        <td>Abono a capital</td>
                        <td>—</td>
                        <td>{{ $aCapital->formateado() }}</td>
                    </tr>
                @endunless
            </tbody>
        </table>
    @endif

    <div class="total">
        <div class="cifra">
            <span class="rotulo">Total recibido</span>
            <span class="monto">{{ $recibo->montoTotal()->formateado() }}</span>
        </div>
        {{-- A un número se le agrega un cero con un trazo; a la cantidad en
             letras, no. Por eso van las dos. --}}
        <div class="letras">{{ $enLetras }}</div>
    </div>

    @if ($saldo)
        <p class="nota">
            Saldo del lote al {{ now()->format('d/m/Y') }}: <strong>{{ $saldo->formateado() }}</strong>.
            El saldo cambia con cada pago; este recibo acredita el monto recibido, no el saldo.
        </p>
    @endif

    @if ($recibo->getAttribute('observaciones'))
        <p class="nota">{{ $recibo->getAttribute('observaciones') }}</p>
    @endif

    {{-- R10: no se usa CAI. Decirlo en el papel evita que alguien lo presente
         como comprobante fiscal. --}}
    <p class="nota">Documento de uso interno. No es comprobante fiscal.</p>

    <div class="firmas">
        <div class="firma">Recibí conforme</div>
        <div class="firma">Entregué conforme</div>
    </div>

    <div class="corte"></div>
</div>

<script>
    // El flujo de ventanilla es cobrar e imprimir: el diálogo sale solo. Quien
    // solo quiere mirar lo cancela, y para eso está la ficha del recibo en el
    // panel, que no imprime nada.
    window.addEventListener('load', function () { window.print(); });
</script>

</body>
</html>
