{{--
    El estado de resultados del mes, como sale por la impresora.

    Pedido de Mauricio el 24-ago-2026: «reportes mensuales de pagos, gastos, y
    porcentajes dependiendo de cuánto tengan los socios». En HTML: «ya él decide
    si lo imprime o no, nada de generar pdf». Sobre el alcance: **«nada de qué
    hay en la caja, solo que muestre el estado de resultados mes a mes, nada de
    acumulado, y qué hay que entregar»**.

    El CSS va adentro por lo mismo que en el recibo y el estado de cuenta: esta
    pagina no vive en el panel, no tiene el CSS de Filament ni el de Tailwind, y
    no debe depender de que un build de assets haya corrido.

    ═══ 🔴 LO QUE SE CORRIGIO EN LA SEGUNDA VUELTA ═══

    Mauricio miró la primera y preguntó: «¿eso te parece profesional?». No lo
    era, y estas cuatro cosas eran por qué:

     1. `text-transform: capitalize` escribía «Agosto De 2026». En un documento
        contable eso se nota antes que cualquier número.
     2. Con gastos en cero, la sección de egresos eran CUATRO renglones para
        decir cero. Ahora colapsa a uno.
     3. La sección de resultado repetía cifras que ya estaban dos veces arriba.
     4. Las notas explicaban el sistema a sí mismo en el medio de la hoja. Un
        estado de resultados no se explica: se lee. Lo que hacía falta decir
        quedó en una nota al pie, chiquita y al final.

    Y le faltaba sustancia: se agregaron el desglose por FORMA de cobro —con el
    que se cuadra contra el banco— y la tabla del AÑO mes a mes, que es la que
    contesta «¿cómo viene el año?» de un vistazo.

    ═══ EL PAPEL: A4 **Y** CARTA, CON UNA SOLA MEDIDA ═══

    A4 mide 210 mm de ancho y carta 216. El bloque va a 186 mm —210 menos los
    dos márgenes de 12— así que entra en las dos. Medirlo para carta haría que
    en A4 se saliera por la derecha, y eso en una impresora es una columna
    cortada.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Estado de resultados · {{ $cierre->mesEscrito() }}</title>
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

        /* ── Membrete ── */
        .encabezado { display: flex; justify-content: space-between; gap: 1.5rem; align-items: flex-start; }
        .emisor { max-width: 60%; display: flex; gap: .75rem; align-items: flex-start; }
        .emisor img { height: 46px; width: auto; max-width: 140px; object-fit: contain; flex: none; }
        .emisor .datos { display: block; min-width: 0; }
        .emisor .residencial { font-size: 13.5px; font-weight: 700; letter-spacing: -.01em; line-height: 1.25; }
        .emisor .linea { color: #52525b; font-size: 10.5px; line-height: 1.4; }

        .titulo { text-align: right; white-space: nowrap; }
        .titulo .que { font-size: 11px; font-weight: 700; letter-spacing: .09em; text-transform: uppercase; color: #52525b; }
        .titulo .mes { font-size: 20px; font-weight: 700; line-height: 1.15; letter-spacing: -.015em; }

        /* La regla doble de los estados financieros. */
        .regla { border: 0; border-top: 2px solid #18181b; border-bottom: 1px solid #18181b; height: 3px; margin: .875rem 0 0; }

        /* La ficha del documento: qué proyecto, qué período, en qué moneda.
           Sin esto, una hoja fotocopiada no dice de qué desarrollo es. */
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

        /* ── Tablas de cifras ── */
        table { width: 100%; border-collapse: collapse; font-size: 11.5px; }
        th {
            padding: .25rem .5rem; text-align: right; white-space: nowrap;
            font-size: 9px; font-weight: 700; letter-spacing: .07em; text-transform: uppercase;
            color: #71717a; border-bottom: 1px solid #d4d4d8;
        }
        td { padding: .25rem .5rem; text-align: right; font-variant-numeric: tabular-nums; }
        th:first-child, td:first-child { text-align: left; padding-left: 0; }
        th:last-child, td:last-child { padding-right: 0; }

        .cifra { width: 30%; }

        tbody tr.renglon td { border-bottom: 1px dotted #e4e4e7; }
        tbody tr.subtotal td { border-top: 1px solid #a1a1aa; font-weight: 700; padding-top: .3125rem; }
        tbody tr.cierre td {
            border-top: 2px solid #18181b; border-bottom: 3px double #18181b;
            font-weight: 700; font-size: 13px; padding: .4375rem .5rem;
        }
        tbody tr.perdida td { color: #b91c1c; }
        td.rotulo { color: #3f3f46; }
        .sangria { padding-left: .75rem !important; }
        .apagado { color: #a1a1aa; }

        /* ── Por forma de cobro: una franja, no una tabla ── */
        .formas { display: flex; flex-wrap: wrap; gap: .25rem 1.5rem; margin-top: .5rem; padding: .4375rem .625rem; background: #fafafa; border: 1px solid #e4e4e7; border-radius: .25rem; }
        .formas .par { font-size: 10.5px; color: #52525b; font-variant-numeric: tabular-nums; }
        .formas .par b { color: #18181b; font-weight: 600; }
        .formas .titulo-franja { font-size: 9px; font-weight: 700; letter-spacing: .07em; text-transform: uppercase; color: #a1a1aa; width: 100%; }

        /* ── El año ── */
        table.anio td, table.anio th { font-size: 10.5px; padding: .1875rem .4375rem; }
        table.anio tbody tr td { border-bottom: 1px dotted #e4e4e7; }
        table.anio tr.actual td { background: #fafafa; font-weight: 700; }
        table.anio tr.vacio td { color: #d4d4d8; }

        /* ── Anexo: lo que quedó sin cobrar ──
           Tipografía más chica que el resto: es una lista que puede traer cien
           renglones y tiene que caber. `display: table-header-group` hace que
           el encabezado se repita en cada hoja impresa; sin eso, la página 2
           es una columna de números sin título. */
        table.pendientes thead { display: table-header-group; }
        table.pendientes td, table.pendientes th { font-size: 10px; padding: .1875rem .375rem; }
        table.pendientes tbody td { border-bottom: 1px dotted #e4e4e7; }
        table.pendientes tfoot td {
            border-top: 2px solid #18181b; border-bottom: 3px double #18181b;
            font-weight: 700; padding-top: .3125rem;
        }
        table.pendientes .nombre { max-width: 46mm; }
        table.pendientes .n { width: 7%; }
        .atraso { margin-left: .25rem; font-size: 8.5px; font-weight: 700; color: #b91c1c; white-space: nowrap; }
        .aviso.bien { background: #f0fdf4; border-color: rgba(22, 163, 74, .35); color: #14532d; }

        /* ── Reparto ── */
        table.reparto tbody td { border-bottom: 1px dotted #e4e4e7; }
        table.reparto tfoot td { border-top: 2px solid #18181b; border-bottom: 3px double #18181b; font-weight: 700; padding-top: .3125rem; }
        tr.sobregirado td { color: #b91c1c; }
        .marca { font-size: 8.5px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; display: block; }

        .aviso {
            margin-top: .5rem; padding: .4375rem .625rem; border-radius: .25rem;
            background: #fef2f2; border: 1px solid rgba(220, 38, 38, .35);
            color: #7f1d1d; font-size: 10.5px; line-height: 1.5;
        }
        .aviso.tranquilo { background: #fafafa; border-color: #d4d4d8; color: #52525b; }

        .vacio-nota { padding: .4375rem 0; color: #71717a; font-size: 11px; }

        .firmas { display: grid; grid-template-columns: 1fr 1fr; gap: 3rem; margin-top: 2rem; }
        .firma { border-top: 1px solid #71717a; padding-top: .25rem; text-align: center; font-size: 10px; color: #52525b; }

        .notas { margin-top: 1.25rem; padding-top: .5rem; border-top: 1px solid #e4e4e7; font-size: 9px; line-height: 1.5; color: #a1a1aa; }
        .notas b { color: #71717a; }
        .pie { margin-top: .5rem; display: flex; justify-content: space-between; gap: 1rem; font-size: 9px; color: #a1a1aa; }

        /* ── Adentro de la ventana flotante del panel ──
           La misma hoja se sirve suelta y metida en un `<iframe>`. Cambian dos
           cosas y nada más: «Volver» no tiene a dónde volver —el modal se
           cierra con su propio botón— y el aire de los costados sobra cuando
           el marco ya recorta. La clase la pone el script de abajo. */
        .en-marco body { padding: .75rem .5rem; }
        .en-marco .barra a { display: none; }

        @media print {
            body { padding: 0; background: #fff; font-size: 10.5px; }
            .barra { display: none !important; }
            .hoja { max-width: none; border: 0; border-radius: 0; padding: 0; }
            .aviso, .formas, table.anio tr.actual td { background: transparent; }
            table { page-break-inside: auto; }
            tr { page-break-inside: avoid; }
            h2 { page-break-after: avoid; }
            .firmas { page-break-inside: avoid; }
        }
    </style>

    {{-- Va en el `<head>` y no al final del `<body>`: así la clase está puesta
         ANTES de que el navegador pinte, y «Volver» no llega a parpadear
         adentro del marco. --}}
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

        <div class="titulo">
            <div class="que">Estado de resultados</div>
            {{-- `ucfirst` y NO `text-transform: capitalize`: aquello escribía
                 «Agosto De 2026», con la preposición en mayúscula. --}}
            <div class="mes">{{ ucfirst($cierre->mesEscrito()) }}</div>
        </div>
    </div>

    <hr class="regla">

    <div class="ficha">
        <div>
            <div class="rotulo">Proyecto</div>
            <div class="valor">{{ $cierre->proyecto->getAttribute('nombre') }} · {{ $cierre->proyecto->getAttribute('codigo') }}</div>
        </div>
        <div>
            <div class="rotulo">Período</div>
            <div class="valor">{{ $cierre->primerDia->format('d/m/Y') }} — {{ $cierre->ultimoDia->format('d/m/Y') }}</div>
        </div>
        <div>
            <div class="rotulo">Moneda</div>
            <div class="valor">Lempiras (HNL)</div>
        </div>
        <div>
            <div class="rotulo">Base</div>
            <div class="valor">Efectivo (caja)</div>
        </div>
    </div>

    @unless ($cierre->huboMovimiento())
        <div class="aviso tranquilo">
            En {{ $cierre->mesEscrito() }} no se registró ningún cobro, gasto ni devolución en este
            proyecto.
        </div>
    @endunless

    {{-- ── 1 · Ingresos ──────────────────────────────────────────── --}}

    <h2><span class="numeral">1</span> Ingresos del mes</h2>

    <table>
        <thead>
            <tr>
                <th>Concepto</th>
                <th class="cifra">Monto</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($cierre->cobradoPorConcepto as $clave => $monto)
                <tr class="renglon">
                    <td class="rotulo">{{ \App\Domain\Enums\ConceptoDeRecibo::from($clave)->etiqueta() }}</td>
                    <td>{{ $monto->formateado() }}</td>
                </tr>
            @empty
                <tr class="renglon">
                    <td class="rotulo apagado">Sin cobros registrados en el mes</td>
                    <td class="apagado">L. 0.00</td>
                </tr>
            @endforelse

            <tr class="subtotal">
                <td>Total de ingresos</td>
                <td>{{ $cierre->cobradoDelMes->formateado() }}</td>
            </tr>
        </tbody>
    </table>

    @unless ($cierre->cobradoPorForma === [])
        {{-- Con esto se cuadra contra el banco: el efectivo tiene que estar en
             la caja y el resto tiene que aparecer en el estado de cuenta. --}}
        <div class="formas">
            <span class="titulo-franja">Por forma de cobro</span>
            @foreach ($cierre->cobradoPorForma as $clave => $monto)
                <span class="par">{{ \App\Domain\Enums\FormaDePago::from($clave)->etiqueta() }}
                    <b>{{ $monto->formateado() }}</b></span>
            @endforeach
        </div>
    @endunless

    {{-- ── 2 · Egresos ───────────────────────────────────────────── --}}

    <h2><span class="numeral">2</span> Egresos del mes</h2>

    <table>
        <thead>
            <tr>
                <th>Categoría</th>
                <th class="cifra">Monto</th>
            </tr>
        </thead>
        <tbody>
            {{-- Colapsa a UN renglón cuando no hubo nada: cuatro líneas para
                 decir cero es lo que hacía que la hoja pareciera un borrador. --}}
            @if ($cierre->gastadoPorCategoria === [] && $cierre->devueltoDelMes->esCero())
                <tr class="renglon">
                    <td class="rotulo apagado">Sin egresos registrados en el mes</td>
                    <td class="apagado">L. 0.00</td>
                </tr>
            @else
                @foreach ($cierre->gastadoPorCategoria as $clave => $monto)
                    <tr class="renglon">
                        <td class="rotulo">{{ \App\Domain\Enums\CategoriaDeGasto::from($clave)->etiqueta() }}</td>
                        <td>{{ $monto->formateado() }}</td>
                    </tr>
                @endforeach

                {{-- La devolución de una seña NO es un gasto del desarrollo: es
                     plata del cliente que volvió. Va en su propio renglón para
                     que la suma de las categorías siga cuadrando, al céntimo,
                     contra la pestaña Gastos del proyecto. --}}
                @unless ($cierre->devueltoDelMes->esCero())
                    <tr class="renglon">
                        <td class="rotulo sangria">Devuelto a clientes</td>
                        <td>{{ $cierre->devueltoDelMes->formateado() }}</td>
                    </tr>
                @endunless
            @endif

            <tr class="subtotal">
                <td>Total de egresos</td>
                <td>{{ $cierre->salidasDelMes->formateado() }}</td>
            </tr>

            <tr class="cierre @unless ($cierre->perdidaDelMes->esCero()) perdida @endunless">
                <td>{{ $cierre->perdidaDelMes->esCero() ? 'Utilidad del mes' : 'Pérdida del mes' }}</td>
                <td>
                    {{ $cierre->perdidaDelMes->esCero()
                        ? $cierre->utilidadDelMes->formateado()
                        : $cierre->perdidaDelMes->formateado() }}
                </td>
            </tr>
        </tbody>
    </table>

    {{-- ── 3 · El reparto ────────────────────────────────────────── --}}

    <h2><span class="numeral">3</span> Qué hay que entregarle a cada socio</h2>

    @if ($cierre->reparto === [])
        <p class="vacio-nota">
            Este proyecto todavía no tiene socios cargados. Se agregan en el proyecto, pestaña
            «Socios», con el porcentaje de cada uno.
        </p>
    @elseif (! $cierre->perdidaDelMes->esCero())
        <p class="vacio-nota">El mes cerró con pérdida: no hay utilidad que repartir.</p>
    @else
        <table class="reparto">
            <thead>
                <tr>
                    <th>Socio</th>
                    <th>Parte</th>
                    <th>Le toca</th>
                    <th>Ya se le entregó</th>
                    <th>Hay que entregarle</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($cierre->reparto as $parte)
                    <tr @class(['sobregirado' => $parte->estaSobregirado()])>
                        <td>{{ $parte->nombre() }}</td>
                        <td>{{ $parte->porcentajeEscrito() }}</td>
                        <td>{{ $parte->leToca->formateado() }}</td>
                        <td>{{ $parte->entregado->formateado() }}</td>
                        <td>
                            @if ($parte->estaSobregirado())
                                <span class="marca">Se le adelantó</span>
                                {{ $parte->entregadoDeMas->formateado() }}
                            @else
                                {{ $parte->porEntregar->formateado() }}
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td>Totales</td>
                    <td></td>
                    <td>{{ $cierre->utilidadDelMes->formateado() }}</td>
                    <td>{{ $cierre->entregadoDelMes->formateado() }}</td>
                    <td>{{ $cierre->porEntregar->formateado() }}</td>
                </tr>
            </tfoot>
        </table>

        @unless ($cierre->sinRepartir()->esCero())
            <div class="aviso">
                Quedan {{ $cierre->sinRepartir()->formateado() }} sin dueño: los porcentajes de los
                socios activos no suman 100 %. Se revisa en el proyecto, pestaña «Socios».
            </div>
        @endunless
    @endif

    {{-- ── 4 · El año ────────────────────────────────────────────── --}}

    <h2><span class="numeral">4</span> El año {{ $cierre->primerDia->year }}, mes a mes</h2>

    <table class="anio">
        <thead>
            <tr>
                <th>Mes</th>
                <th class="cifra">Ingresos</th>
                <th class="cifra">Egresos</th>
                <th class="cifra">Resultado</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($cierre->mesAMesDelAnio() as $fila)
                <tr @class([
                    'actual' => $fila['esElQueSeCierra'],
                    'vacio' => $fila['ingresos']->esCero() && $fila['egresos']->esCero(),
                    'perdida' => ! $fila['perdida']->esCero(),
                ])>
                    <td>{{ ucfirst($fila['mes']) }}</td>
                    <td>{{ $fila['ingresos']->formateado() }}</td>
                    <td>{{ $fila['egresos']->formateado() }}</td>
                    <td>
                        {{ $fila['perdida']->esCero()
                            ? $fila['utilidad']->formateado()
                            : '('.$fila['perdida']->formateado().')' }}
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    {{-- ── 5 · Lo que vencía y no entró ──────────────────────────────
         Pedido de Mauricio el 25-ago-2026: «sería bueno que también diga lo
         pendiente de personas que no pagaron cuota que les tocaba ese mes».

         Va al final y no entre los ingresos, por dos razones: no es plata que
         entró —el resultado de arriba es de caja y esto no lo toca— y es la
         única sección que puede ocupar tres hojas. El estado de resultados
         queda en la primera; esto lo sigue como anexo. --}}

    <h2><span class="numeral">5</span> Cuotas de {{ $cierre->mesEscrito() }} que quedaron sin pagar</h2>

    @if (! $cierre->sinCobrar->hayAlgo())
        @if ($cierre->vencioEnElMes->esCero())
            <p class="vacio-nota">En {{ $cierre->mesEscrito() }} no vencía ninguna cuota en este proyecto.</p>
        @else
            <div class="aviso bien">
                Se cobró el total de lo que vencía en el mes
                ({{ $cierre->vencioEnElMes->formateado() }}): no quedó ninguna cuota pendiente.
            </div>
        @endif
    @else
        <div class="formas">
            <span class="titulo-franja">Resumen de la cobranza del mes</span>
            <span class="par">Vencía en el mes <b>{{ $cierre->vencioEnElMes->formateado() }}</b></span>
            @if ($cierre->sinCobrar->cumplimiento($cierre->vencioEnElMes) !== null)
                <span class="par">Se cobró <b>{{ $cierre->sinCobrar->cumplimiento($cierre->vencioEnElMes) }}</b></span>
            @endif
            <span class="par">Quedó sin pagar <b>{{ $cierre->sinCobrar->saldo->formateado() }}</b></span>
            <span class="par">Cuotas <b>{{ count($cierre->sinCobrar->cuotas) }}</b>
                de <b>{{ $cierre->sinCobrar->expedientes }}</b> expedientes</span>
        </div>

        <table class="pendientes">
            <thead>
                <tr>
                    <th>Expediente</th>
                    <th>Cliente</th>
                    <th>Lote</th>
                    <th class="n">Cuota</th>
                    <th>Vence</th>
                    <th>Monto</th>
                    <th>Pagado</th>
                    <th>Saldo</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($cierre->sinCobrar->cuotas as $cuota)
                    <tr>
                        <td>{{ $cuota->expediente }}</td>
                        <td class="nombre">{{ $cuota->cliente }}</td>
                        <td>{{ $cuota->lote }}</td>
                        <td class="n">{{ $cuota->numero }}</td>
                        <td>
                            {{ $cuota->vence->format('d/m/Y') }}
                            {{-- La del 30 en un mes corriente NO está atrasada.
                                 Marcarlas iguales pondría en mora a un cliente
                                 que está al día. --}}
                            @if ($cuota->yaVencio())
                                <span class="atraso">+{{ $cuota->diasDeAtraso }} d</span>
                            @endif
                        </td>
                        <td>{{ $cuota->monto->formateado() }}</td>
                        <td class="{{ $cuota->esPagoAMedias() ? '' : 'apagado' }}">
                            {{ $cuota->pagado->formateado() }}
                        </td>
                        <td>{{ $cuota->saldo->formateado() }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="4">Totales</td>
                    <td>{{ $cierre->sinCobrar->vencidas }} vencidas</td>
                    <td>{{ $cierre->sinCobrar->monto->formateado() }}</td>
                    <td>{{ $cierre->sinCobrar->pagado->formateado() }}</td>
                    <td>{{ $cierre->sinCobrar->saldo->formateado() }}</td>
                </tr>
            </tfoot>
        </table>
    @endif

    <div class="firmas">
        <div class="firma">Preparado por</div>
        <div class="firma">Revisado por</div>
    </div>

    <div class="notas">
        <b>Notas.</b> Los ingresos son los recibos con fecha del período, sin contar los anulados.
        Los egresos son los gastos del proyecto más lo devuelto a clientes; lo retenido de una seña
        no sale de caja y no se cuenta. El reparto se calcula sobre la utilidad del mes por el
        porcentaje de cada socio, y «ya se le entregó» son las entregas anotadas a cuenta de este
        mes aunque se hayan pagado otro día. En la tabla del año, un resultado entre paréntesis es
        pérdida. Base de efectivo: no entra lo que los clientes todavía deben.
        <b>Sobre el anexo 5:</b> son las cuotas con vencimiento dentro del período que todavía
        tienen saldo —incluidas las abonadas a medias— de expedientes vigentes; no incluye atrasos
        de meses anteriores ni lotes rescindidos, y <b>no forma parte del resultado ni del
        reparto</b>. «+n d» son los días corridos desde el vencimiento a la fecha de emisión.
    </div>

    <div class="pie">
        <span>{{ $emisor['residencial'] ?? '' }} · Estado de resultados · {{ $cierre->mesEscrito() }}</span>
        <span>Emitido el {{ now()->format('d/m/Y H:i') }}</span>
    </div>

</div>

</body>
</html>
