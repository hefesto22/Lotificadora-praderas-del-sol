{{--
    El papel de una SALIDA de dinero: la devolución de una seña (R14) o el
    acta de una rescisión de lote (R22).

    Es un solo archivo para los dos trámites porque los dos contestan las
    mismas tres preguntas —cuánto entró, cuánto salió, cuánto quedó— y quien
    lo firma es la misma persona. Lo que cambia es el título, el rótulo de lo
    retenido y si hay contrato del que hablar; eso lo decide
    `TipoDeDevolucion`, no la plantilla.

    ═══ POR QUE EL CSS ESTA ACA ADENTRO ═══

    Mismo motivo que el recibo: esta página no vive dentro del panel y no debe
    depender de que un build de assets haya corrido. El día que Vite falle, el
    acta tiene que seguir saliendo igual.

    ═══ ESTE PAPEL NO DICE «COPIA» ═══

    A diferencia del recibo, no se registra cada impresión. Un recibo numerado
    que aparece dos veces es un problema de caja; un acta reimpresa no —de
    hecho salen DOS de entrada, una para el cliente y una para el expediente—.
    Por eso el pie dice a quién va cada ejemplar en vez de contar visitas.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $devolucion->tipoDeDevolucion()->titulo() }} {{ $devolucion->folio() }}</title>
    @include('comun.fuente')
    <style>
        @page { size: letter; margin: 12mm; }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            padding: 1.5rem 1rem;
            background: #f4f4f5;
            color: #18181b;
            font-family: var(--olympo-fuente);
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

        .encabezado { display: flex; justify-content: space-between; gap: 1.5rem; align-items: flex-start; }
        .emisor { max-width: 62%; display: flex; gap: .75rem; align-items: flex-start; }
        .emisor img { height: 44px; width: auto; max-width: 140px; object-fit: contain; flex: none; }
        .emisor .datos { display: block; min-width: 0; }
        .emisor .residencial { font-size: 14px; font-weight: 700; letter-spacing: -.01em; line-height: 1.25; }
        .emisor .linea { color: #52525b; font-size: 11.5px; line-height: 1.45; }

        .folio { text-align: right; white-space: nowrap; }
        .folio .rotulo { font-size: 10px; font-weight: 600; letter-spacing: .08em; text-transform: uppercase; color: #71717a; }
        .folio .numero { font-size: 22px; font-weight: 700; font-variant-numeric: tabular-nums; }
        .folio .fecha { font-size: 12px; color: #52525b; }

        .regla { border: 0; border-top: 1px solid #e4e4e7; margin: 1rem 0; }

        .campos { display: flex; flex-wrap: wrap; gap: .875rem 2.5rem; }
        .campo { min-width: 0; }
        .campo .rotulo { font-size: 10px; font-weight: 600; letter-spacing: .08em; text-transform: uppercase; color: #71717a; }
        .campo .valor { font-size: 13.5px; font-weight: 600; }

        /* ── La liquidación: los tres números que se firman ── */
        .liquidacion {
            margin-top: 1.25rem;
            border: 1px solid #e4e4e7;
            border-radius: .5rem;
            overflow: hidden;
        }
        .liquidacion .renglon {
            display: flex;
            justify-content: space-between;
            gap: 1rem;
            padding: .625rem .9rem;
            border-bottom: 1px solid #f4f4f5;
        }
        .liquidacion .renglon:last-child { border-bottom: 0; }
        .liquidacion .cifra { font-variant-numeric: tabular-nums; font-weight: 600; }
        .liquidacion .retenido { background: #fafafa; font-weight: 700; }
        .liquidacion .retenido .cifra { font-size: 15px; }

        .letras { margin-top: .5rem; font-size: 11.5px; font-weight: 600; color: #3f3f46; letter-spacing: .02em; }

        .nota { margin: .875rem 0 0; font-size: 11.5px; color: #52525b; }
        .nota strong { color: #18181b; }

        .motivo {
            margin-top: 1rem;
            padding: .75rem .9rem;
            background: #fafafa;
            border: 1px solid #f4f4f5;
            border-radius: .5rem;
            font-size: 12.5px;
            white-space: pre-line;
        }
        .motivo .rotulo { font-size: 10px; font-weight: 600; letter-spacing: .08em; text-transform: uppercase; color: #71717a; display: block; margin-bottom: .25rem; }

        /* El aviso al contador: solo aparece si el desarrollo factura con
           CAI y ademas salio plata. Borde punteado y letra chica porque no es
           para el cliente — es una nota interna que viaja en el mismo papel. */
        .fiscal {
            margin-top: 1rem;
            padding: .75rem .9rem;
            border: 1px dashed #d4d4d8;
            border-radius: .5rem;
            font-size: 11.5px;
            color: #52525b;
            line-height: 1.5;
        }
        .fiscal .titulo { display: block; margin-bottom: .25rem; font-size: 10px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: #71717a; }
        .fiscal strong { color: #18181b; }

        .firmas { display: flex; gap: 3rem; margin-top: 3rem; }
        .firma { flex: 1; border-top: 1px solid #a1a1aa; padding-top: .375rem; text-align: center; font-size: 11.5px; color: #52525b; }

        .destino { margin-top: 1.25rem; font-size: 10.5px; color: #71717a; text-align: center; }

        .corte { margin-top: 2rem; border-top: 1px dashed #d4d4d8; }

        @media print {
            body { background: #fff; padding: 0; }
            .hoja { max-width: none; border: 0; border-radius: 0; padding: 0; }
            .barra { display: none; }
        }
    </style>
</head>
<body onload="window.print()">

<div class="barra">
    <button type="button" onclick="window.print()">Imprimir</button>
    <a href="{{ url()->previous() }}">Volver</a>
</div>

<div class="hoja">
    <div class="encabezado">
        <div class="emisor">
            @if ($logo)
                <img src="{{ $logo }}" alt="">
            @endif
            <div class="datos">
                <div class="residencial">{{ $emisor['residencial'] ?? $emisor['nombre'] ?? '' }}</div>
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
        </div>

        <div class="folio">
            <div class="rotulo">{{ $devolucion->tipoDeDevolucion()->titulo() }}</div>
            <div class="numero">N.º {{ $devolucion->folio() }}</div>
            <div class="fecha">{{ $devolucion->fecha?->format('d/m/Y') }}</div>
        </div>
    </div>

    <hr class="regla">

    <div class="campos">
        <div class="campo">
            <div class="rotulo">Entregado a</div>
            <div class="valor">{{ $cliente?->getAttribute('nombre_completo') ?? $cliente?->getAttribute('nombre') ?? '—' }}</div>
        </div>
        @if ($identidad)
            <div class="campo">
                <div class="rotulo">Identidad</div>
                <div class="valor">{{ $identidad }}</div>
            </div>
        @endif
        <div class="campo">
            <div class="rotulo">Lote</div>
            <div class="valor">{{ $codigoDelLote ?? '—' }}</div>
        </div>
        @if ($contrato)
            <div class="campo">
                <div class="rotulo">Contrato</div>
                <div class="valor">{{ $contrato }}</div>
            </div>
        @endif
        <div class="campo">
            <div class="rotulo">Forma de pago</div>
            <div class="valor">{{ $devolucion->getAttribute('forma_pago')?->etiqueta() ?? '—' }}</div>
        </div>
        @if ($devolucion->getAttribute('referencia'))
            <div class="campo">
                <div class="rotulo">Referencia</div>
                <div class="valor">{{ $devolucion->getAttribute('referencia') }}</div>
            </div>
        @endif
    </div>

    {{-- Los tres números que sostienen todo el papel. El retenido va
         destacado porque es el que nadie recuerda dentro de un año y el que
         explica por qué la lotificadora no devolvió todo. --}}
    <div class="liquidacion">
        <div class="renglon">
            <span>Recibido del cliente</span>
            <span class="cifra">{{ $devolucion->montoRecibido()->formateado() }}</span>
        </div>
        <div class="renglon">
            <span>Devuelto en este acto</span>
            <span class="cifra">{{ $devolucion->montoDevuelto()->formateado() }}</span>
        </div>
        <div class="renglon retenido">
            <span>{{ $devolucion->tipoDeDevolucion()->rotuloDeLoRetenido() }}</span>
            <span class="cifra">{{ $devolucion->montoRetenido()->formateado() }}</span>
        </div>
    </div>

    <div class="letras">{{ $enLetras }}</div>

    <div class="motivo">
        <span class="rotulo">Motivo</span>{{ $devolucion->getAttribute('motivo') }}
    </div>

    @if ($devolucion->esRescision())
        {{-- Lo que el cliente tiene que entender antes de firmar, dicho sin
             tecnicismos: el lote deja de ser suyo y lo retenido no vuelve. --}}
        <p class="nota">
            <strong>Con la firma de esta acta el lote {{ $codigoDelLote }} queda rescindido</strong> y
            vuelve a estar disponible para la venta. Las cuotas pendientes de ese lote quedan sin
            efecto@if ($contratoSigue), y el contrato {{ $contrato }} continúa vigente con los demás
            lotes@endif. Los recibos y facturas ya emitidos por los pagos hechos conservan su
            validez y no se anulan.
        </p>
    @else
        <p class="nota">
            <strong>Con la firma de este comprobante queda saldada la devolución</strong> de la seña
            del lote {{ $codigoDelLote }}, que vuelve a estar disponible para la venta.
        </p>
    @endif

    @if ($notaDeCredito)
        {{-- ═══ EL AVISO FISCAL ═══

             Solo cuando el desarrollo factura con CAI y ademas salio plata.
             Una factura entregada hace meses NO se anula: lo que corrige un
             documento fiscal ya emitido es una NOTA DE CREDITO.

             Si la lotificadora no las emite —que es el caso normal, es otra
             autorizacion del SAR— el acta igual lo dice, para que el contador
             decida. El sistema no puede emitir lo que el SAR no autorizo,
             pero si puede evitar que nadie se acuerde. --}}
        <div class="fiscal">
            <div class="titulo">Para el contador</div>
            @if ($notaDeCredito['emite'])
                Este desarrollo factura con CAI. Por los {{ $notaDeCredito['monto'] }} devueltos
                <strong>corresponde emitir una nota de crédito</strong>. Las facturas ya emitidas
                no se anulan: conservan su validez y su CAI.
            @else
                Este desarrollo factura con CAI y <strong>no tiene habilitadas las notas de
                crédito</strong>. Consultá con el contador si corresponde emitir una por los
                {{ $notaDeCredito['monto'] }} devueltos. Las facturas ya emitidas no se anulan.
            @endif
        </div>
    @endif

    <div class="firmas">
        <div class="firma">Recibí conforme — cliente</div>
        <div class="firma">Entregué conforme — {{ $emisor['residencial'] ?? 'la lotificadora' }}</div>
    </div>

    <div class="destino">Original: cliente · Copia: expediente.</div>

    <div class="corte"></div>
</div>

</body>
</html>
