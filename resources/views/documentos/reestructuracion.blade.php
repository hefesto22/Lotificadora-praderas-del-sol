{{--
    El acta de una reestructuración del plan (traída de Maya, 25-oct-2026).

    Es el papel que firma el cliente cuando su plan de pagos cambia: qué debía,
    cómo lo pagaba y cómo lo va a pagar. Contesta tres preguntas, en ese orden:
    ¿cuánto pago ahora y hasta cuándo?, ¿cuánto interés me cuesta el cambio?,
    y ¿qué cuota vence cada mes?

    Los números salen del ACTA —el antes, el después y el plan que se pactó,
    guardados ese día— y no de las cuotas de hoy. Un abono posterior reescribe
    cuotas; no puede cambiar lo que el cliente firmó.

    ═══ LO YA PAGADO (26-sep-2026) ═══

    Lo que el cliente había pagado de las cuotas que se reemplazan pasa a
    pagar el plan nuevo, con los mismos recibos. El acta lo dice en tres
    lugares: «Ya pagado» en la comparación, la columna del plan, y la lista
    de recibos que se movieron —de qué cuota a qué cuota—, porque una
    reimpresión de esos recibos va a decir la cuota nueva (§4.L7).

    ═══ CADA LOTE CON SU CUOTA (Praderas, 25-oct-2026) ═══

    Cuando los lotes no se unifican, cada uno queda con su plan y su cuota. El
    plan de pagos de abajo es lo que el contrato paga por mes —la suma de los
    lotes—, y el cuadro «Cómo queda cada lote» dice cuánto es de cada uno. Así
    el cliente sabe qué paga si un mes trae plata solo para uno.

    ═══ POR QUE EL CSS ESTA ACA ADENTRO ═══

    El mismo motivo que el recibo y el acta de rescisión: esta página no vive
    dentro del panel y no puede depender de que un build de assets haya
    corrido (§15).

    ═══ LA TASA SE DICE DE LAS DOS FORMAS ═══

    «6.2 % anual, equivalente a 0.5167 % mensual» (§8.5): el mercado habla en
    anual y la tabla se calcula en mensual. Diciendo las dos no hay discusión
    posible sobre cuál se pactó.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $acta->esRenegociacion() ? 'Acta de renegociación' : 'Acta de reestructuración' }} {{ $acta->folio() }}</title>
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
        .folio .numero { font-size: 20px; font-weight: 700; font-variant-numeric: tabular-nums; }
        .folio .fecha { font-size: 12px; color: #52525b; }

        .regla { border: 0; border-top: 1px solid #e4e4e7; margin: 1rem 0; }

        .campos { display: flex; flex-wrap: wrap; gap: .875rem 2.5rem; }
        .campo { min-width: 0; }
        .campo .rotulo { font-size: 10px; font-weight: 600; letter-spacing: .08em; text-transform: uppercase; color: #71717a; }
        .campo .valor { font-size: 13.5px; font-weight: 600; }

        h2 { margin: 1.5rem 0 .5rem; font-size: 11px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: #52525b; }

        table { width: 100%; border-collapse: collapse; font-variant-numeric: tabular-nums; }
        th { padding: .4rem .5rem; font-size: 10px; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: #71717a; text-align: right; border-bottom: 1px solid #e4e4e7; }
        td { padding: .35rem .5rem; text-align: right; border-bottom: 1px solid #f4f4f5; white-space: nowrap; }
        th:first-child, td:first-child { text-align: left; }
        .comparacion td:first-child { color: #52525b; }
        .comparacion .antes { color: #71717a; }
        .comparacion .despues { font-weight: 700; }
        .plan tfoot td { border-top: 1px solid #a1a1aa; border-bottom: 0; font-weight: 700; }
        .reubicados td.detalle { white-space: normal; text-align: left; font-size: 11.5px; color: #3f3f46; }
        .reubicados th:nth-child(4), .reubicados th:nth-child(5) { text-align: left; }

        .diferencia { margin: .5rem 0 0; font-size: 12.5px; font-weight: 600; }

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

        .firmas { display: flex; gap: 3rem; margin-top: 3rem; }
        .firma { flex: 1; border-top: 1px solid #a1a1aa; padding-top: .375rem; text-align: center; font-size: 11.5px; color: #52525b; }

        .destino { margin-top: 1.25rem; font-size: 10.5px; color: #71717a; text-align: center; }

        @media (max-width: 480px) {
            .hoja { padding: 1rem; }
            .encabezado { flex-direction: column; }
            .emisor { max-width: none; }
            .folio { text-align: left; }
            .tabla-ancha { overflow-x: auto; }
        }

        @media print {
            body { background: #fff; padding: 0; }
            .hoja { max-width: none; border: 0; border-radius: 0; padding: 0; }
            .barra { display: none; }
            tr { break-inside: avoid; }
        }
    </style>
</head>
<body onload="window.print()">

@php
    $tasaAntes = $acta->tasaAnterior();
    $tasaNueva = $acta->tasaNueva();
    $antes = $acta->montoInteresAntes();
    $despues = $acta->montoInteresDespues();
    $capital = $acta->montoCapital();
    // El descuento de capital (18-oct-2026): el plan nuevo es sobre lo que queda.
    $descuento = $acta->montoDescuento();
    $capitalDespues = $acta->capitalDespues();
    $aFechas = $acta->esAFechas();
    $mesesAntes = (int) $acta->getAttribute('meses_antes');
    $mesesDespues = (int) $acta->getAttribute('meses_despues');
    $lotesQueEntran = (int) $acta->getAttribute('lotes');
    $desde = $plan[0]['numero'] ?? 1;
    $yaPagado = $acta->montoYaPagado();
    $conPagado = ! $yaPagado->esCero();
    $moraAntes = $acta->montoMoraAntes();
@endphp

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
            {{-- La renegociación (18-oct-2026) sale con el mismo papel: lo que cambia es qué se pactó. --}}
            <div class="rotulo">{{ $acta->esRenegociacion() ? 'Acta de renegociación del contrato' : 'Acta de reestructuración del plan' }}</div>
            <div class="numero">N.º {{ $acta->folio() }}</div>
            <div class="fecha">{{ $acta->fechaDe('fecha')?->format('d/m/Y') }}</div>
        </div>
    </div>

    <hr class="regla">

    <div class="campos">
        <div class="campo">
            <div class="rotulo">Titular</div>
            <div class="valor">{{ $titular ?? '—' }}</div>
        </div>
        @if ($dni)
            <div class="campo">
                <div class="rotulo">Identidad</div>
                <div class="valor">{{ $dni }}</div>
            </div>
        @endif
        <div class="campo">
            <div class="rotulo">Contrato</div>
            <div class="valor">{{ $contrato ?? '—' }}</div>
        </div>
        @if ($firmado)
            <div class="campo">
                <div class="rotulo">Firmado</div>
                <div class="valor">{{ $firmado }}</div>
            </div>
        @endif
        @if ($valor)
            <div class="campo">
                <div class="rotulo">Valor del contrato</div>
                <div class="valor">{{ $valor->formateado() }}</div>
            </div>
        @endif
        @if ($prima)
            <div class="campo">
                <div class="rotulo">Prima</div>
                <div class="valor">{{ $prima->formateado() }}</div>
            </div>
        @endif
        <div class="campo">
            <div class="rotulo">{{ $lotesQueEntran === 1 ? 'Lote' : 'Lotes' }}</div>
            <div class="valor">{{ $lotes ?: '—' }}</div>
        </div>
        <div class="campo">
            <div class="rotulo">Día de pago</div>
            <div class="valor">{{ $aFechas ? 'En las fechas pactadas' : 'Cada '.(int) $acta->getAttribute('dia_pago_nuevo').' de mes' }}</div>
        </div>
    </div>

    <h2>Lo que cambia</h2>

    <div class="tabla-ancha">
        <table class="comparacion">
            <thead>
                <tr><th>Desde la cuota {{ $desde }}</th><th>Antes</th><th>Desde esta acta</th></tr>
            </thead>
            <tbody>
                <tr>
                    <td>Capital</td>
                    <td class="antes">{{ $capital->formateado() }}</td>
                    <td class="despues">{{ $capitalDespues->formateado() }}</td>
                </tr>
                @unless ($descuento->esCero())
                    <tr>
                        <td>Descuento de capital</td>
                        <td class="antes">—</td>
                        <td class="despues">{{ $descuento->formateado() }}</td>
                    </tr>
                @endunless
                <tr>
                    <td>{{ $aFechas ? 'Primer pago' : 'Cuota por mes' }}</td>
                    <td class="antes">{{ $acta->montoCuotaAnterior()?->formateado() ?? '—' }}</td>
                    <td class="despues">{{ $acta->montoCuotaNueva()->formateado() }}@unless ($aFechas || $acta->interesIncluido()) (la primera)@endunless</td>
                </tr>
                <tr>
                    <td>{{ $aFechas ? 'Pagos' : 'Meses' }}</td>
                    <td class="antes">{{ $mesesAntes }}</td>
                    <td class="despues">{{ $mesesDespues }}</td>
                </tr>
                <tr>
                    <td>Tasa de interés anual</td>
                    <td class="antes">{{ $tasaAntes?->formateada() ?? 'distinta por lote' }}</td>
                    <td class="despues">{{ $tasaNueva->formateada() }}</td>
                </tr>
                @if ($acta->quitoLaMora())
                    <tr>
                        <td>Mora por atraso</td>
                        <td class="antes">{{ $acta->moraAnterior() }}</td>
                        <td class="despues">Sin mora</td>
                    </tr>
                @endif
                <tr>
                    <td>Interés</td>
                    <td class="antes">{{ $antes->formateado() }}</td>
                    <td class="despues">{{ $despues->formateado() }}</td>
                </tr>
                <tr>
                    <td>Capital más interés</td>
                    <td class="antes">{{ $capital->sumar($antes)->formateado() }}</td>
                    <td class="despues">{{ $capitalDespues->sumar($despues)->formateado() }}</td>
                </tr>
                @if ($conPagado)
                    <tr>
                        <td>Ya pagado</td>
                        <td class="antes">{{ $yaPagado->formateado() }}</td>
                        <td class="despues">{{ $yaPagado->formateado() }}</td>
                    </tr>
                    <tr>
                        <td>Falta pagar</td>
                        <td class="antes">{{ $acta->faltaAntes()->formateado() }}</td>
                        <td class="despues">{{ $acta->faltaDespues()->formateado() }}</td>
                    </tr>
                @endif
                <tr>
                    <td>Termina de pagar</td>
                    <td class="antes">{{ $acta->fechaDe('termina_antes')?->format('d/m/Y') ?? '—' }}</td>
                    <td class="despues">{{ $acta->fechaDe('termina_despues')?->format('d/m/Y') ?? '—' }}</td>
                </tr>
            </tbody>
        </table>
    </div>

    <p class="diferencia">
        @if ($despues->mayorQue($antes))
            Con este cambio el interés sube {{ $despues->restar($antes)->formateado() }}.
        @elseif ($antes->mayorQue($despues))
            Con este cambio el interés baja {{ $antes->restar($despues)->formateado() }}.
        @else
            Con este cambio el interés no cambia.
        @endif
    </p>

    <p class="nota">
        @unless ($descuento->esCero())
            <strong>Se descuentan {{ $descuento->formateado() }} del capital:</strong> el plan nuevo es sobre
            {{ $capitalDespues->formateado() }}.
        @endunless
        @if ($acta->quitoLaMora())
            <strong>Desde esta acta no corre mora:</strong> un atraso de acá en adelante no la cobra.
        @endif
        @if ($tasaNueva->esCero())
            <strong>El plan no lleva interés:</strong> cada {{ $aFechas ? 'pago' : 'cuota' }} es capital.
        @elseif ($aFechas)
            <strong>Los pagos se pactaron fecha por fecha</strong>, y a cada uno se le suma el interés de los días
            desde el pago anterior, al {{ $tasaNueva->formateada() }} anual sobre saldos.
        @elseif ($acta->interesIncluido())
            <strong>La cuota ya incluye el interés</strong>, al {{ $tasaNueva->formateada() }} anual sobre
            saldos, equivalente a {{ $mensual }} mensual. Se paga lo mismo todos los meses; la última
            cuota cierra el saldo exacto.
        @else
            <strong>El interés va encima de la cuota</strong>, al {{ $tasaNueva->formateada() }} anual
            sobre saldos, equivalente a {{ $mensual }} mensual: cada mes se paga la misma parte de
            capital más el interés de lo que se debe ese mes. Por eso la cuota baja mes a mes.
        @endif
        @if ($lotesQueEntran > 1 && $acta->cadaLoteConSuCuota())
            Cada lote queda con su propio plan y su propia cuota; lo que se pague de un lote se aplica a ese lote.
        @elseif ($lotesQueEntran > 1)
            Los {{ $lotesQueEntran }} lotes se pagan con una sola cuota, como si fueran uno.
        @endif
    </p>

    @if ($lotesQueEntran > 1 && $porLote !== [])
        <h2>Cómo queda cada lote</h2>

        <div class="tabla-ancha">
            <table class="plan">
                <thead>
                    <tr><th>Lote</th><th>Capital</th><th>Cuotas</th><th>Cuota</th><th>La última</th><th>Del</th><th>Al</th></tr>
                </thead>
                <tbody>
                    @foreach ($porLote as $lote)
                        <tr>
                            <td>{{ $lote['codigo'] }}</td>
                            <td>{{ $lote['capital']->formateado() }}</td>
                            <td>{{ $lote['cuotas'] }}</td>
                            <td>{{ $lote['cuota']->formateado() }}</td>
                            <td>{{ $lote['ultima']->formateado() }}</td>
                            <td>{{ $lote['primera'] }}</td>
                            <td>{{ $lote['termina'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <h2>El plan de pagos</h2>

    <div class="tabla-ancha">
        <table class="plan">
            <thead>
                <tr><th>Cuota</th><th>Vence</th><th>Paga</th><th>Capital</th><th>Interés</th>@if ($conPagado)<th>Ya pagado</th>@endif</tr>
            </thead>
            <tbody>
                @foreach ($plan as $renglon)
                    <tr>
                        <td>{{ $renglon['numero'] }}</td>
                        <td>{{ $renglon['fecha'] }}</td>
                        <td>{{ $renglon['monto']->formateado() }}</td>
                        <td>{{ $renglon['capital']->formateado() }}</td>
                        <td>{{ $renglon['interes']->formateado() }}</td>
                        @if ($conPagado)
                            <td>{{ $renglon['cubierto']->esCero() ? '—' : $renglon['cubierto']->formateado() }}</td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="2">Total</td>
                    <td>{{ $totales['monto']->formateado() }}</td>
                    <td>{{ $totales['capital']->formateado() }}</td>
                    <td>{{ $totales['interes']->formateado() }}</td>
                    @if ($conPagado)
                        <td>{{ $totales['cubierto']->formateado() }}</td>
                    @endif
                </tr>
            </tfoot>
        </table>
    </div>

    @if ($pagos !== [])
        <h2>Lo que ya pagó, en el plan nuevo</h2>

        <div class="tabla-ancha">
            <table class="reubicados">
                <thead>
                    <tr><th>Recibo</th><th>Fecha</th><th>Monto</th><th>Se aplicaba a</th><th>Ahora se aplica a</th></tr>
                </thead>
                <tbody>
                    @foreach ($pagos as $pago)
                        <tr>
                            <td>{{ $pago['folio'] }}</td>
                            <td>{{ $pago['fecha'] }}</td>
                            <td>{{ $pago['dinero']->sumar($pago['mora'])->formateado() }}</td>
                            <td class="detalle">{{ $pago['antes'] }}</td>
                            <td class="detalle">{{ $pago['despues'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <p class="nota">
            Los recibos conservan su número, su fecha y su monto. Lo que cambia es la cuota que pagan: si se
            reimprimen, dicen la cuota del plan nuevo.
        </p>
    @endif

    @if (! $moraAntes->esCero() || ! $moraCobrada->esCero())
        <p class="nota">
            <strong>Mora:</strong>
            @if (! $moraAntes->esCero())
                con esta acta se perdonan {{ $moraAntes->formateado() }} de mora que corrían: el plan nuevo arranca
                al día y sin mora.
            @endif
            @if (! $moraCobrada->esCero())
                La mora ya cobrada en los recibos de arriba ({{ $moraCobrada->formateado() }}) no entra en la
                cuenta del plan nuevo: sigue siendo mora.
            @endif
        </p>
    @endif

    <div class="motivo">
        <span class="rotulo">Motivo</span>{{ $acta->getAttribute('motivo') }}
    </div>

    <p class="nota">
        <strong>Con la firma de esta acta, el plan de pagos {{ $lotesQueEntran === 1 ? 'del lote' : 'de los lotes' }}
        {{ $lotes }} del contrato {{ $contrato }} queda reemplazado desde la cuota {{ $desde }}</strong> por el
        que se detalla arriba. Las cuotas
        anteriores y todos los recibos conservan su validez; lo ya pagado de las cuotas reemplazadas
        queda aplicado al plan nuevo como se detalla. El precio, el área y el valor de los lotes no
        cambian. Los atrasos se cobran según las condiciones de mora del contrato.
    </p>

    <div class="firmas">
        <div class="firma">{{ $titular ?? 'El cliente' }}</div>
        <div class="firma">Por {{ $emisor['residencial'] ?? 'la lotificadora' }}</div>
    </div>

    <div class="destino">Original: cliente · Copia: expediente.</div>
</div>

</body>
</html>
