{{--
    El recibo, como sale por la impresora de la ventanilla.

    ═══ POR QUE EL CSS ESTA ACA ADENTRO ═══

    Esta página no vive dentro del panel: no tiene el CSS de Filament ni el de
    Tailwind, y no debe tenerlos. Un documento que se entrega no puede
    depender de que un build de assets haya corrido — el día que Vite falle,
    el recibo tiene que seguir saliendo igual.

    ⚠️ La ÚNICA excepción, desde el 11-ago-2026, es la tipografía
    (`@include('comun.fuente')`): el recibo que el cliente se lleva estaba
    escrito en una letra distinta a la de la pantalla donde se lo cobraron.
    No contradice el párrafo de arriba —esos archivos los publica
    `filament:assets`, no Vite, y están versionados en git—, y si faltaran,
    el `font-display: swap` deja la hoja exactamente como se veía antes.

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
    <title>{{ $recibo->tipoDeDocumento()->denominacion() }} {{ $recibo->numeroDelPapel() }}</title>
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
        /* Membrete: el logo a la IZQUIERDA y los datos al lado, en una sola
           columna que baja. `.datos` es block a proposito —y esta dicho—
           porque `.emisor` es flex: sin esto, cada renglon del emisor se
           volvia una columna del encabezado y la direccion terminaba
           flotando al lado del nombre.

           `height` fija con `width:auto` y `object-fit:contain`: asi un logo
           alto, uno ancho y uno cuadrado ocupan la misma franja y el membrete
           se ve igual en los tres desarrollos. */
        .emisor { max-width: 62%; display: flex; gap: .75rem; align-items: flex-start; }
        .emisor img { height: 44px; width: auto; max-width: 140px; object-fit: contain; flex: none; }
        .emisor .datos { display: block; min-width: 0; }
        .emisor .residencial { font-size: 14px; font-weight: 700; letter-spacing: -.01em; line-height: 1.25; }
        .emisor .linea { color: #52525b; font-size: 11.5px; line-height: 1.45; }

        .folio { text-align: right; white-space: nowrap; }
        .folio .rotulo { font-size: 10px; font-weight: 600; letter-spacing: .08em; text-transform: uppercase; color: #71717a; }
        .folio .numero { font-size: 22px; font-weight: 700; font-variant-numeric: tabular-nums; }
        .folio .fecha { font-size: 12px; color: #52525b; }

        .copia {
            display: inline-block; margin-top: .375rem;
            padding: .2rem .55rem; border: 1px solid #dc2626; border-radius: 9999px;
            color: #dc2626; font-size: 10px; font-weight: 700; letter-spacing: .1em;
        }

        /* Un recibo anulado se puede seguir imprimiendo —hace falta para
           mostrar que ese número no vale— pero el papel tiene que gritarlo,
           no susurrarlo en una esquina. */
        .anulado {
            display: block; margin-top: .5rem;
            padding: .35rem .6rem; border: 2px solid #dc2626; border-radius: .375rem;
            background: #fef2f2; color: #b91c1c;
            font-size: 13px; font-weight: 800; letter-spacing: .12em; text-align: center;
        }
        .anulado small {
            display: block; margin-top: .2rem;
            font-size: 9px; font-weight: 500; letter-spacing: 0; color: #7f1d1d;
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

        /* ── Lo que la factura tiene que decir y el recibo no ──
           Acuerdo 481-2017, Art. 10. Va en un recuadro propio porque son
           datos que nadie lee de corrido: se buscan. Un auditor mira la CAI,
           el cliente mira el numero, y los dos tienen que encontrarlos sin
           leer el resto del papel. */
        .fiscal {
            margin-top: 1rem; padding: .625rem .875rem;
            border: 1px solid #d4d4d8; border-radius: .375rem;
            font-size: 10.5px; line-height: 1.55; color: #3f3f46;
        }
        .fiscal .cai { font-family: ui-monospace, "SFMono-Regular", Menlo, Consolas, monospace; word-break: break-all; }
        .fiscal .rotulo { font-weight: 700; color: #52525b; }
        .fiscal .destino { margin-top: .375rem; color: #71717a; }

        /* El desglose del impuesto. Grid de dos columnas para que las cifras
           queden alineadas a la derecha sin una tabla mas. */
        .impuesto { margin-top: .625rem; display: grid; grid-template-columns: 1fr auto; gap: .15rem .75rem; font-size: 11.5px; }
        .impuesto .cifra { text-align: right; font-variant-numeric: tabular-nums; }
        .impuesto .fuerte { font-weight: 700; }
        /* El total se despega del desglose: son la misma caja pero no la
           misma pregunta. */
        .impuesto + .cifra { margin-top: .5rem; padding-top: .5rem; border-top: 1px solid #e4e4e7; }

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
            {{-- UN solo logo: el del desarrollo, que es la marca que el cliente
                 reconoce. El de la inmobiliaria queda de respaldo para los que
                 todavía no tengan el suyo cargado. --}}
            @if ($logoDelProyecto ?? null)
                <img src="{{ $logoDelProyecto }}" alt="">
            @elseif ($logo)
                <img src="{{ $logo }}" alt="">
            @endif

            <div class="datos">
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
        </div>

        <div class="folio">
            {{-- La denominacion del documento la exige el Art. 10, num. 5: el
                 papel tiene que decir como se llama. En el recibo interno se
                 sigue leyendo «Recibo de cuota», que es como lo nombra la
                 gente en ventanilla. --}}
            <div class="rotulo">
                @if ($recibo->esFactura())
                    {{ $recibo->tipoDeDocumento()->denominacion() }}
                @else
                    Recibo de {{ $recibo->concepto?->etiqueta() ?? 'pago' }}
                @endif
            </div>
            <div class="numero">N.º {{ $recibo->numeroDelPapel() }}</div>
            <div class="fecha">{{ $recibo->fecha?->format('d/m/Y') }}</div>
            @if ($recibo->estaAnulado())
                <div class="anulado">
                    ANULADO
                    <small>{{ $recibo->anulado_el?->format('d/m/Y') }} · {{ $recibo->getAttribute('motivo_anulacion') }}</small>
                </div>
            @endif
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
            <div class="valor">{{ $recibo->nombreDelPapel() }}</div>
        </div>
        {{-- En la factura NO es opcional: sin RTN ni identidad el documento no
             dice a quien se le vendio (Art. 10, num. 11). Por eso el renglon
             sale siempre —aunque salga vacio, que es una pregunta para quien
             cobro— mientras que en el recibo interno aparece solo si se
             cargo. --}}
        @if ($recibo->esFactura() || $recibo->dniDelPapel())
            <div class="dato">
                <div class="rotulo">{{ $recibo->esFactura() ? 'RTN o identidad' : 'Identidad' }}</div>
                <div class="valor">{{ ($recibo->esFactura() ? $recibo->identidadDelPapel() : $recibo->dniDelPapel()) ?? '—' }}</div>
            </div>
        @endif
        @if ($recibo->esANombreDeOtro())
            {{--
                Cuando el recibo sale a nombre de un representado, el papel
                tiene que decir TAMBIEN de qué expediente es. Si no, queda un
                comprobante a nombre de alguien que no aparece en ningún
                contrato — y dentro de dos años nadie puede decir contra qué
                deuda entró ese dinero.
            --}}
            <div class="dato">
                <div class="rotulo">Por cuenta de</div>
                <div class="valor">{{ $recibo->cliente?->getAttribute('nombre') ?? '—' }}</div>
            </div>
        @endif
        <div class="dato">
            <div class="rotulo">Contrato</div>
            <div class="valor">{{ $recibo->venta?->getAttribute('numero_contrato') ?? '—' }}</div>
        </div>
        <div class="dato">
            <div class="rotulo">{{ $variosLotes ? 'Lotes' : 'Lote' }}</div>
            <div class="valor">{{ $recibo->rotuloDeLotes() }}</div>
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

    {{-- La factura imprime SIEMPRE su detalle. Un recibo de prima o de seña no
         tiene aplicaciones que listar —la prima no toca cuotas— y hasta hoy
         eso salía sin tabla, que en un recibo interno está bien. En una
         factura no: el Art. 10, num. 13 pide descripción, cantidad, precio
         unitario y valor de lo que se cobró. Por eso, cuando no hay renglones,
         baja uno solo con el concepto. --}}
    @if ($recibo->esFactura() || $recibo->aplicaciones->isNotEmpty() || ! $aCapital->esCero())
        <h2>En concepto de</h2>

        <table>
            <thead>
                <tr>
                    <th>Concepto</th>
                    <th>Vence</th>
                    @if ($recibo->esFactura())
                        <th>Cant.</th>
                        <th>Valor unitario</th>
                    @endif
                    <th>Monto</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($recibo->aplicaciones as $aplicacion)
                    <tr>
                        {{-- Con varios lotes, «Cuota 1» tres veces no dice nada:
                             cada plan numera desde 1. El código va adelante. --}}
                        <td>@if ($variosLotes){{ $aplicacion->cuota?->compromiso?->lote?->getAttribute('codigo') ?? '—' }} · @endif Cuota {{ $aplicacion->cuota?->getAttribute('numero') }}</td>
                        <td>{{ $aplicacion->cuota?->getAttribute('fecha_vencimiento')?->format('d/m/Y') ?? '—' }}</td>
                        @if ($recibo->esFactura())
                            {{-- Una cuota es una, y su valor unitario es lo que
                                 se aplicó. Las columnas existen porque la
                                 factura las pide, no porque acá se vendan
                                 cosas por docena. --}}
                            <td>1</td>
                            <td>{{ $aplicacion->montoAplicado()->formateado() }}</td>
                        @endif
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
                        @if ($recibo->esFactura())
                            <td>1</td>
                            <td>{{ $aCapital->formateado() }}</td>
                        @endif
                        <td>{{ $aCapital->formateado() }}</td>
                    </tr>
                @endunless

                @if ($recibo->esFactura() && $recibo->aplicaciones->isEmpty() && $aCapital->esCero())
                    <tr>
                        <td>
                            {{ $recibo->concepto?->etiqueta() ?? 'Pago' }}
                            @if ($recibo->rotuloDeLotes() !== '—') · lote {{ $recibo->rotuloDeLotes() }} @endif
                        </td>
                        <td>—</td>
                        <td>1</td>
                        <td>{{ $recibo->montoTotal()->formateado() }}</td>
                        <td>{{ $recibo->montoTotal()->formateado() }}</td>
                    </tr>
                @endif
            </tbody>
        </table>
    @endif

    <div class="total">
        {{-- El desglose lo pide el Art. 10, num. 15-16, y sale del modelo:
             qué parte del cobro grava es una decisión que se toma en un solo
             lugar. Ver Recibo::desgloseFiscal(). --}}
        @if ($recibo->esFactura())
            <div class="impuesto">
                @foreach ($recibo->desgloseFiscal() as $renglon => $importe)
                    <span>{{ $renglon }}</span>
                    <span class="cifra">{{ $importe->formateado() }}</span>
                @endforeach
            </div>
        @endif

        <div class="cifra">
            <span class="rotulo">{{ $recibo->esFactura() ? 'Total' : 'Total recibido' }}</span>
            <span class="monto">{{ $recibo->montoTotal()->formateado() }}</span>
        </div>
        {{-- A un número se le agrega un cero con un trazo; a la cantidad en
             letras, no. Por eso van las dos. --}}
        <div class="letras">{{ $enLetras }}</div>
    </div>

    {{-- En qué se convirtió lo que entregó hoy.

         Solo cuando hay algo además de capital: con tasa 0 y sin mora (R1,
         R2) esta línea diría «capital» y el total otra vez, y repetir el
         mismo número con otro nombre hace dudar de los dos. --}}
    @if ($recibo->cobroMora() || $recibo->cobroInteres())
        <p class="nota">
            <strong>De este pago:</strong>
            @if ($recibo->cobroMora()) mora {{ $recibo->montoMora()->formateado() }} · @endif
            @if ($recibo->cobroInteres()) interés {{ $recibo->interesDeCuotas()->formateado() }} · @endif
            capital {{ $recibo->capitalDeCuotas()->sumar($aCapital)->formateado() }}.
            El interés es el costo del financiamiento y no baja su saldo; el capital sí.
        </p>
    @endif

    @if ($recibo->condonoMora())
        <p class="nota">
            Se le condonó mora por <strong>{{ $recibo->moraCondonada()->formateado() }}</strong>,
            que no se cobró en este recibo.
        </p>
    @endif

    {{-- El descuento por pronto pago, 23-ago-2026.

         Va ACA ABAJO y no adentro del total, y no es una decisión de diseño:
         el total es lo que el cliente entregó, y es contra ese número que se
         cuadra la caja del día. Un descuento sumado ahí haría que el arqueo
         busque un efectivo que nunca existió.

         Pero tampoco se calla: el cliente acordó de palabra una rebaja y este
         papel es lo único que la deja escrita. --}}
    @if ($recibo->tuvoDescuento())
        <p class="nota">
            Se le descontó <strong>{{ $recibo->capitalCondonado()->formateado() }}</strong> por pronto pago,
            que no se cobró en este recibo. Con este pago el lote queda saldado.
        </p>
    @endif

    {{-- Lo que le queda por pagar, lote por lote — 27-ago-2026.

         «Que diga cuánto le queda de x lote o lotes que él tiene, ya que les
         gusta saber cuánto les resta de pagar» — Mauricio.

         Hasta hoy esto imprimía UN saldo y solo cuando el recibo tenía
         `compromiso_id`, así que el cobro de varios lotes —el único donde el
         desglose hace falta de verdad— no mostraba ninguno.

         El total va solo cuando hay más de uno: con un lote repetiría el mismo
         número con otro nombre, y eso hace dudar de los dos. --}}
    @if ($saldos !== [])
        <p class="nota">
            <strong>Le queda por pagar</strong> al {{ now()->format('d/m/Y') }}:
            @foreach ($saldos as $renglon)
                {{ $renglon['codigo'] }} <strong>{{ $renglon['saldo']->formateado() }}</strong>{{ $loop->last ? '' : ' · ' }}
            @endforeach
            @if (count($saldos) > 1)
                — <strong>total {{ $saldoTotal->formateado() }}</strong>
            @endif
            <br>
            El saldo cambia con cada pago; este recibo acredita el monto recibido, no el saldo.
        </p>
    @endif

    @if ($recibo->getAttribute('observaciones'))
        <p class="nota">{{ $recibo->getAttribute('observaciones') }}</p>
    @endif

    @if ($recibo->esFactura())
        {{-- ═══ EL BLOQUE FISCAL ═══

             Acuerdo 481-2017, Art. 10: numeros 8 (rango autorizado), 9 (fecha
             limite de emision), 10 (CAI) y 6 (destino de cada ejemplar). Van
             juntos y en recuadro porque son datos que se BUSCAN, no que se
             leen de corrido.

             El numero interno tambien se imprime, chiquito: es el que cuadra
             la caja (R12) y el que va a buscar quien reciba un reclamo. Dos
             numeros en un papel no confunden si uno esta grande arriba y el
             otro dice para que sirve. --}}
        <div class="fiscal">
            <div><span class="rotulo">CAI:</span> <span class="cai">{{ $recibo->getAttribute('cai') }}</span></div>
            @if ($recibo->rangoAutorizado())
                <div><span class="rotulo">Rango autorizado:</span> {{ $recibo->rangoAutorizado() }}</div>
            @endif
            <div><span class="rotulo">Fecha límite de emisión:</span> {{ $recibo->fecha_limite_emision?->format('d/m/Y') }}</div>

            @if (($facturacion ?? null)?->getAttribute('direccion_casa_matriz'))
                <div><span class="rotulo">Casa matriz:</span> {{ $facturacion->getAttribute('direccion_casa_matriz') }}</div>
            @endif
            @if (($facturacion ?? null)?->getAttribute('direccion_establecimiento'))
                <div><span class="rotulo">Establecimiento:</span> {{ $facturacion->getAttribute('direccion_establecimiento') }}</div>
            @endif

            {{-- Quien imprime el papel. Con talonario van los datos de la
                 imprenta; siendo autoimpresor —que es el caso de esta
                 lotificadora— va el numero de la resolucion que lo autoriza.
                 Sale solo si esta cargado: un renglon vacio en el pie de una
                 factura se ve peor que no tenerlo. --}}
            @if (($facturacion ?? null)?->getAttribute('imprenta_nombre'))
                <div>
                    <span class="rotulo">Imprenta:</span> {{ $facturacion->getAttribute('imprenta_nombre') }}
                    @if (($facturacion ?? null)?->getAttribute('imprenta_rtn')) · RTN {{ $facturacion->getAttribute('imprenta_rtn') }} @endif
                </div>
            @endif
            @if (($facturacion ?? null)?->getAttribute('imprenta_certificado'))
                <div><span class="rotulo">Autorización:</span> {{ $facturacion->getAttribute('imprenta_certificado') }}</div>
            @endif

            <div class="destino">
                Original: cliente · Copia: obligado tributario emisor.
                Control interno N.º {{ $recibo->folio() }}.
            </div>
        </div>
    @else
        {{-- La Clausula Segunda, modulo g-i, pide el recibo interno correlativo
             «con NO VALIDO PARA CREDITO FISCAL». Esas palabras son texto del
             contrato, no una parafrasis nuestra, y son las que evitan que
             alguien intente presentar este papel ante el SAR. Sin CAI, este
             papel nunca va a ser un comprobante fiscal. --}}
        <p class="nota"><strong>NO VÁLIDO PARA CRÉDITO FISCAL</strong></p>
        <p class="nota">Documento de uso interno.</p>
    @endif

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
