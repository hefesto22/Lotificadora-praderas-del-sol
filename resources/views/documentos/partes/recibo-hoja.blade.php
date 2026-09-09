{{--
    UNA hoja de recibo: del membrete a las firmas, sin `<html>` alrededor.

    Se extrajo el 9-sep-2026 para que el documento de varios recibos pueda
    repetirla sin copiarla. Espera exactamente las mismas variables que le
    pasa `ImprimirReciboController`, y por eso el que imprime varios arma un
    juego completo por cada recibo en vez de inventar un formato aparte: dos
    plantillas que dicen lo mismo terminan no diciéndolo.

    ⚠️ El salto de página entre hojas NO va acá: es cosa de quien apila, no de
    la hoja. Sola, esta hoja no tiene que saber que hay otra después.
--}}
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
        {{-- Quién recibió el dinero — 31-ago-2026.

             «También debe de decir el nombre de la persona que recibió el
             dinero» — Mauricio. El sistema ya lo sabía (R24, 27-ago) y el
             corte de caja del día cuenta por él; lo que faltaba era que el
             papel del cliente lo dijera.

             Va ACA ARRIBA y no solo en la firma: cuando alguien vuelve con un
             reclamo, «a quién le pagué» se busca entre los datos, no entre
             las rayas del final.

             Sale solo si se sabe. Los recibos de la cartera vieja se cargaron
             sin sesión, y ahí el papel no inventa un nombre. --}}
        @if ($recibio)
            <div class="dato">
                <div class="rotulo">Recibido por</div>
                <div class="valor">{{ $recibio }}</div>
            </div>
        @endif
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

                        🔴 El nombre del renglón sale del CONCEPTO del recibo y
                        no está escrito acá — 31-ago-2026. `montoACapital()` es
                        una resta, así que en un recibo de prima o de seña da el
                        papel entero y este renglón salía llamándole «abono a
                        capital» a una prima. Ver `Recibo::rotuloDelSobrante()`.
                    --}}
                    {{--
                        🔴 UN RENGLON POR LOTE — 8-sep-2026. «Debería
                        especificar abono a capital cuánto a qué lote, o sea
                        que se vea más información detallada» — Mauricio,
                        mirando un papel que decía «Abono a capital
                        L 2,000.00» sin decir que eran mil a cada uno.

                        Desde que el sobrante de «Ambas» se reparte, el
                        renglón único esconde justamente la decisión que se
                        acaba de tomar — y es la que el cliente va a querer
                        revisar dentro de un año.

                        `capitalPorLote()` viene vacío cuando el desglose no
                        suma el total del renglón (una prima, una seña, un
                        abono que no reprogramó nada), y ahí el papel sale
                        como salía. Ver su docblock: partes que no suman es
                        peor que ningún detalle.
                    --}}
                    @forelse ($capitalPorLote as $renglonDeCapital)
                        <tr class="capital">
                            <td>@if ($variosLotes){{ $renglonDeCapital['codigo'] }} · @endif {{ $recibo->rotuloDelSobrante() }}</td>
                            <td>—</td>
                            @if ($recibo->esFactura())
                                <td>1</td>
                                <td>{{ $renglonDeCapital['monto']->formateado() }}</td>
                            @endif
                            <td>{{ $renglonDeCapital['monto']->formateado() }}</td>
                        </tr>
                    @empty
                        <tr class="capital">
                            <td>{{ $recibo->rotuloDelSobrante() }}</td>
                            <td>—</td>
                            @if ($recibo->esFactura())
                                <td>1</td>
                                <td>{{ $aCapital->formateado() }}</td>
                            @endif
                            <td>{{ $aCapital->formateado() }}</td>
                        </tr>
                    @endforelse
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

    {{-- Las dos firmas dicen QUIENES son — 31-ago-2026.

         En un recibo el dinero ENTRA: firma «recibí» quien lo recibió por la
         lotificadora, y «entregué» quien lo pagó. (En el acta de devolución el
         dinero SALE, así que ahí los dos papeles están al revés — y esa ya
         dice de quién es cada raya desde el 14-ago.)

         Una raya sin nombre no identifica a nadie: dos meses después, la firma
         de quien recibió no la reconoce ni quien la hizo. --}}
    <div class="firmas">
        <div class="firma">Recibí conforme{{ $recibio === null ? '' : ' — '.$recibio }}</div>
        <div class="firma">Entregué conforme{{ $recibo->nombreDelPapel() === '—' ? '' : ' — '.$recibo->nombreDelPapel() }}</div>
    </div>

    <div class="corte"></div>
</div>
