{{--
    El estado general del proyecto, como sale por la impresora (10-oct-2026).

    Pedido de Mauricio desde la pantalla del Estado mensual: «un estado de
    resultados general de todo: ganancias, gastos, cuánto recibió cada socio,
    en qué recibió, general de todo el proyecto, con proyección de lo que ya se
    vendió y falta de cobrar, con un diseño bonito y profesional».

    Es hermana de la hoja del mes y se viste igual —la misma tipografía, la
    misma regla doble, los mismos encabezados— para que las dos se lean como
    parte de un mismo juego de papeles. Lo que agrega es lo que un mes suelto
    no puede decir: el resumen en tarjetas arriba, la historia año por año y
    hacia dónde va el proyecto.

    El cálculo vive en `EstadoGeneral`. Acá no se suma nada que no venga
    hecho: una vista que hace cuentas es una segunda fuente de verdad.

    ═══ LAS BARRAS ═══

    Son `div` con `flex-grow` proporcional, no anchos en por ciento: así los
    tramos suman el ancho entero aunque los por cientos redondeados no sumen
    cien. Llevan su leyenda con montos —el color nunca es lo único que dice
    qué es cada tramo— y un `title` para quien pase el puntero.

    ═══ EL PAPEL ═══

    186 mm de ancho, como la del mes: entra en A4 y en carta.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Estado general · {{ $general->proyecto->getAttribute('nombre') }}</title>
    @include('comun.fuente')
    <style>
        @page { size: A4; margin: 14mm 12mm; }
        * { box-sizing: border-box; }

        :root {
            --tinta: #18181b;
            --tinta-2: #3f3f46;
            --tinta-3: #71717a;
            --tenue: #a1a1aa;
            --linea: #e4e4e7;
            --fondo: #fafafa;
            --pagado: #047857;
            --por-vencer: #2fbf8f;
            --atrasado: #dc2626;
            --perdida: #b91c1c;
        }

        body {
            margin: 0; padding: 1.5rem 1rem;
            background: #f4f4f5; color: var(--tinta);
            font-family: var(--olympo-fuente);
            font-size: 12px; line-height: 1.45;
        }

        .hoja {
            max-width: 186mm; margin: 0 auto; padding: 1.75rem 2rem 2rem;
            background: #fff; border: 1px solid var(--linea); border-radius: .5rem;
        }

        .barra { max-width: 186mm; margin: 0 auto .875rem; display: flex; gap: .5rem; justify-content: flex-end; }
        .barra button, .barra a {
            padding: .5rem .9rem; border: 1px solid #d4d4d8; border-radius: .375rem;
            background: #fff; color: #27272a; font: inherit; font-size: 13px;
            text-decoration: none; cursor: pointer;
        }
        .barra button { background: var(--tinta); border-color: var(--tinta); color: #fff; font-weight: 600; }

        /* Membrete: idéntico al de la hoja del mes. */
        .encabezado { display: flex; justify-content: space-between; gap: 1.5rem; align-items: flex-start; }
        .emisor { max-width: 60%; display: flex; gap: .75rem; align-items: flex-start; }
        .emisor img { height: 46px; width: auto; max-width: 140px; object-fit: contain; flex: none; }
        .emisor .datos { display: block; min-width: 0; }
        .emisor .residencial { font-size: 13.5px; font-weight: 700; letter-spacing: -.01em; line-height: 1.25; }
        .emisor .linea { color: #52525b; font-size: 10.5px; line-height: 1.4; }

        .titulo { text-align: right; }
        .titulo .que { font-size: 11px; font-weight: 700; letter-spacing: .09em; text-transform: uppercase; color: #52525b; white-space: nowrap; }
        .titulo .mes { font-size: 20px; font-weight: 700; line-height: 1.15; letter-spacing: -.015em; }
        .titulo .al { font-size: 10.5px; color: var(--tinta-3); margin-top: .125rem; white-space: nowrap; }

        .regla { border: 0; border-top: 2px solid var(--tinta); border-bottom: 1px solid var(--tinta); height: 3px; margin: .875rem 0 0; }

        .ficha { display: flex; flex-wrap: wrap; gap: 0 2rem; padding: .5rem 0 .875rem; border-bottom: 1px solid var(--linea); }
        .ficha div { font-size: 10px; }
        .ficha .rotulo { font-weight: 700; letter-spacing: .07em; text-transform: uppercase; color: var(--tenue); }
        .ficha .valor { font-size: 11.5px; font-weight: 600; color: #27272a; }

        /* ── Las tarjetas del resumen ──
           Dos renglones de cuatro: arriba lo que ya pasó, abajo lo que viene.
           La cifra nunca se parte; si no cabe, la tarjeta baja de renglón. */
        .tarjetas {
            display: grid; gap: .5rem; margin-top: 1rem;
            grid-template-columns: repeat(4, minmax(0, 1fr));
        }
        .tarjetas + .tarjetas { margin-top: .5rem; }
        .tarjeta {
            padding: .5625rem .625rem .625rem; border: 1px solid var(--linea); border-radius: .375rem;
            background: #fff; min-width: 0;
        }
        .tarjeta .que { font-size: 8.5px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: var(--tinta-3); }
        .tarjeta .cifra { margin-top: .1875rem; font-size: 14px; font-weight: 700; letter-spacing: -.015em; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .tarjeta .nota { margin-top: .125rem; font-size: 9.5px; color: var(--tinta-3); line-height: 1.35; }
        .tarjeta.fuerte { background: var(--tinta); border-color: var(--tinta); color: #fff; }
        .tarjeta.fuerte .que, .tarjeta.fuerte .nota { color: #d4d4d8; }
        .tarjeta.roja { background: var(--perdida); border-color: var(--perdida); color: #fff; }
        .tarjeta.roja .que, .tarjeta.roja .nota { color: #fecaca; }
        .tarjeta.suave { background: var(--fondo); }
        .tarjeta .cifra.atraso { color: var(--atrasado); }

        h2 {
            margin: 1.5rem 0 .25rem; font-size: 10px; font-weight: 700;
            letter-spacing: .11em; text-transform: uppercase; color: var(--tinta);
            padding-bottom: .1875rem; border-bottom: 1px solid var(--tinta);
        }
        h2 .numeral { color: var(--tenue); margin-right: .375rem; }

        /* ── Tablas de cifras: las mismas reglas de la hoja del mes ── */
        table { width: 100%; border-collapse: collapse; font-size: 11.5px; }
        th {
            padding: .25rem .5rem; text-align: right; white-space: nowrap;
            font-size: 9px; font-weight: 700; letter-spacing: .07em; text-transform: uppercase;
            color: var(--tinta-3); border-bottom: 1px solid #d4d4d8;
        }
        td { padding: .25rem .5rem; text-align: right; font-variant-numeric: tabular-nums; }
        th:first-child, td:first-child { text-align: left; padding-left: 0; }
        th:last-child, td:last-child { padding-right: 0; }
        td:not(:first-child) { white-space: nowrap; }

        .cifra-col { width: 30%; }

        tbody tr.renglon td { border-bottom: 1px dotted var(--linea); }
        tbody tr.subtotal td { border-top: 1px solid var(--tenue); font-weight: 700; padding-top: .3125rem; }
        tbody tr.cierre td, tfoot tr.cierre td {
            border-top: 2px solid var(--tinta); border-bottom: 3px double var(--tinta);
            font-weight: 700; font-size: 13px; padding: .4375rem .5rem;
        }
        tbody tr.cierre td:first-child, tfoot tr.cierre td:first-child { padding-left: 0; }
        tbody tr.cierre td:last-child, tfoot tr.cierre td:last-child { padding-right: 0; }
        tr.perdida td, td.perdida { color: var(--perdida); }
        td.rotulo { color: var(--tinta-2); }
        .sangria { padding-left: .75rem !important; }
        .apagado { color: var(--tenue); }
        .chico { font-size: 9.5px; color: var(--tinta-3); font-weight: 400; }

        tfoot td { border-top: 2px solid var(--tinta); border-bottom: 3px double var(--tinta); font-weight: 700; padding-top: .3125rem; }

        /* La franja gris de la hoja del mes, con una tabla chica adentro. */
        .franja { margin-top: .5rem; padding: .4375rem .625rem; background: var(--fondo); border: 1px solid var(--linea); border-radius: .25rem; }
        .franja .titulo-franja { display: block; font-size: 9px; font-weight: 700; letter-spacing: .07em; text-transform: uppercase; color: var(--tenue); }
        .franja .pares { display: flex; flex-wrap: wrap; gap: .25rem 1.5rem; margin-top: .125rem; }
        .franja .par { font-size: 10.5px; color: #52525b; font-variant-numeric: tabular-nums; }
        .franja .par b { color: var(--tinta); font-weight: 600; }
        .franja table { margin-top: .25rem; font-size: 10.5px; }
        .franja th { padding: .1875rem .5rem; border-bottom-color: var(--linea); }
        .franja td { padding: .25rem .5rem; color: #52525b; }
        .franja td:first-child { color: var(--tinta); }
        .franja tbody tr + tr td { border-top: 1px dotted var(--linea); }
        .franja td.total { color: var(--tinta); font-weight: 700; }
        .franja td.apagado { color: #d4d4d8; }

        /* ── Barras ── */
        .cartera {
            display: flex; gap: 2px; height: 12px; margin-top: .75rem;
            border-radius: 4px; overflow: hidden; background: #fff;
        }
        .cartera span { display: block; min-width: 2px; }
        .leyenda { display: flex; flex-wrap: wrap; gap: .375rem 1.5rem; margin-top: .4375rem; font-size: 10.5px; color: #52525b; font-variant-numeric: tabular-nums; }
        .leyenda b { color: var(--tinta); font-weight: 600; }
        .muestra { display: inline-block; width: 9px; height: 9px; border-radius: 2px; margin-right: .3125rem; vertical-align: -1px; }

        td.con-barra { width: 26%; padding-left: .75rem; }
        .barrita { display: block; height: 7px; border-radius: 0 3px 3px 0; min-width: 2px; }
        .barrita.ingresos { background: var(--pagado); }
        .barrita.vence { background: var(--por-vencer); }
        .barrita.atrasado { background: var(--atrasado); }

        tr.actual td { background: var(--fondo); font-weight: 700; }
        tr.atrasado-fila td:first-child { color: var(--atrasado); font-weight: 700; }

        .punto { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: .375rem; vertical-align: 0; }

        .marca { font-size: 8.5px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; display: block; }
        tr.sobregirado td { color: var(--perdida); }

        .aviso {
            margin-top: .5rem; padding: .4375rem .625rem; border-radius: .25rem;
            background: #fef2f2; border: 1px solid rgba(220, 38, 38, .35);
            color: #7f1d1d; font-size: 10.5px; line-height: 1.5;
        }
        .aviso.tranquilo { background: var(--fondo); border-color: #d4d4d8; color: #52525b; }

        .vacio-nota { margin: .4375rem 0 0; color: var(--tinta-3); font-size: 10.5px; }

        .firmas { display: grid; grid-template-columns: 1fr 1fr; gap: 3rem; margin-top: 2rem; }
        .firma { border-top: 1px solid var(--tinta-3); padding-top: .25rem; text-align: center; font-size: 10px; color: #52525b; }

        .notas { margin-top: 1.25rem; padding-top: .5rem; border-top: 1px solid var(--linea); font-size: 9px; line-height: 1.5; color: var(--tenue); }
        .notas b { color: var(--tinta-3); }
        .pie { margin-top: .5rem; display: flex; justify-content: space-between; gap: 1rem; font-size: 9px; color: var(--tenue); }

        .en-marco body { padding: .75rem .5rem; }
        .en-marco .barra a { display: none; }

        @media (max-width: 640px) {
            .hoja { padding: 1.25rem 1rem 1.5rem; }
            .tarjetas { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .tarjeta .cifra { font-size: 13px; }
            .encabezado { flex-direction: column; gap: .75rem; }
            .emisor { max-width: none; }
            .titulo { text-align: left; }
            .tabla-ancha { overflow-x: auto; }
        }

        /* Las barras y las tarjetas oscuras llevan su color también al papel:
           sin esto, el navegador imprime los fondos en blanco. */
        .cartera span, .barrita, .muestra, .punto, .tarjeta.fuerte, .tarjeta.roja {
            -webkit-print-color-adjust: exact; print-color-adjust: exact;
        }

        @media print {
            body { padding: 0; background: #fff; font-size: 10.5px; }
            .barra { display: none !important; }
            .hoja { max-width: none; border: 0; border-radius: 0; padding: 0; }
            .franja, .aviso, .tarjeta.suave, tr.actual td { background: transparent; }
            table { page-break-inside: auto; }
            /* El pie de cada tabla sale una sola vez, al final: repetido en
               cada hoja parecía que había dos totales. */
            tfoot { display: table-row-group; }
            tr, .tarjeta { page-break-inside: avoid; }
            h2 { page-break-after: avoid; }
            .firmas { page-break-inside: avoid; }
        }
    </style>

    <script>
        if (window.self !== window.top) {
            document.documentElement.classList.add('en-marco');
        }
    </script>
</head>
<body>

@php
    // `$pct` da el ancho de las barras; `$pctTexto`, el número que se lee.
    $pct = static fn ($parte, $total, $decimales = 0) => \App\Domain\Reportes\EstadoGeneral::porcentaje($parte, $total, $decimales);
    $pctTexto = static fn ($parte, $total) => \App\Domain\Reportes\EstadoGeneral::porcentajeEscrito($parte, $total);
    $cobrarTodo = $general->siSeCobraTodo();
    $venderTodo = $general->siSeVendeTodo();
    $enPerdida = ! $general->perdida->esCero();
    $formasDeEntrega = $general->formasDeEntrega();
    $mayorAnual = $general->mayorIngresoAnual();
    $mayorPorCobrar = $general->mayorPorCobrarAnual();
    $socios = $general->reparto;
@endphp

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
            <div class="que">Estado general de resultados</div>
            {{-- `ucfirst` sobre la fecha escrita, igual que el mes de la hoja
                 mensual: nada de `text-transform: capitalize`. --}}
            <div class="mes">{{ ucfirst($general->fechaDeCorteEscrita()) }}</div>
            <div class="al">Desde el inicio del proyecto</div>
        </div>
    </div>

    <hr class="regla">

    <div class="ficha">
        <div>
            <div class="rotulo">Proyecto</div>
            <div class="valor">{{ $general->proyecto->getAttribute('nombre') }} · {{ $general->proyecto->getAttribute('codigo') }}</div>
        </div>
        <div>
            <div class="rotulo">Período</div>
            <div class="valor">
                @if ($general->desde)
                    {{ $general->desde->format('d/m/Y') }} — {{ $general->hoy->format('d/m/Y') }}
                @else
                    Sin movimientos todavía
                @endif
            </div>
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

    @unless ($general->huboMovimiento())
        <div class="aviso tranquilo">
            Este proyecto todavía no tiene cobros ni gastos registrados. La hoja se llena sola a medida
            que se cobra y se gasta.
        </div>
    @endunless

    {{-- ── El resumen ────────────────────────────────────────────── --}}

    <div class="tarjetas">
        <div class="tarjeta">
            <div class="que">Vendido</div>
            <div class="cifra">{{ $general->vendido->formateado() }}</div>
            <div class="nota">{{ $general->lotesVendidos }} {{ $general->lotesVendidos === 1 ? 'lote' : 'lotes' }} en {{ $general->expedientes }} {{ $general->expedientes === 1 ? 'expediente' : 'expedientes' }}</div>
        </div>
        <div class="tarjeta">
            <div class="que">Cobrado</div>
            <div class="cifra">{{ $general->cobrado->formateado() }}</div>
            <div class="nota">{{ $general->recibos }} {{ $general->recibos === 1 ? 'recibo' : 'recibos' }}</div>
        </div>
        <div class="tarjeta">
            <div class="que">Egresos</div>
            <div class="cifra">{{ $general->salidas->formateado() }}</div>
            <div class="nota">
                {{ $general->gastos }} {{ $general->gastos === 1 ? 'gasto' : 'gastos' }}
                @unless ($general->devuelto->esCero())
                    y devoluciones
                @endunless
            </div>
        </div>
        <div @class(['tarjeta', 'fuerte' => ! $enPerdida, 'roja' => $enPerdida])>
            <div class="que">{{ $enPerdida ? 'Pérdida a la fecha' : 'Utilidad a la fecha' }}</div>
            <div class="cifra">{{ $enPerdida ? $general->perdida->formateado() : $general->utilidad->formateado() }}</div>
            <div class="nota">
                @if ($enPerdida)
                    Se ha gastado más de lo cobrado
                @elseif ($general->cobrado->esCero())
                    Sin cobros todavía
                @else
                    {{ $pctTexto($general->utilidad, $general->cobrado) }} % de lo cobrado
                @endif
            </div>
        </div>
    </div>

    <div class="tarjetas">
        <div class="tarjeta suave">
            <div class="que">Falta cobrar</div>
            <div class="cifra">{{ $general->porCobrar->formateado() }}</div>
            <div class="nota">De lo ya vendido</div>
        </div>
        <div class="tarjeta suave">
            <div class="que">Atrasado</div>
            <div @class(['cifra', 'atraso' => ! $general->atrasado->esCero()])>{{ $general->atrasado->formateado() }}</div>
            <div class="nota">
                @if ($general->atrasado->esCero())
                    Nadie está atrasado
                @else
                    {{ $general->expedientesAtrasados }} {{ $general->expedientesAtrasados === 1 ? 'expediente' : 'expedientes' }}
                @endif
            </div>
        </div>
        <div class="tarjeta suave">
            <div class="que">Próximos 12 meses</div>
            <div class="cifra">{{ $general->proximos12Meses->formateado() }}</div>
            <div class="nota">Lo que vence en un año</div>
        </div>
        <div class="tarjeta suave">
            <div class="que">Si se cobra todo</div>
            <div class="cifra">
                @if ($cobrarTodo['perdida']->esCero())
                    {{ $cobrarTodo['utilidad']->formateado() }}
                @else
                    ({{ $cobrarTodo['perdida']->formateado() }})
                @endif
            </div>
            <div class="nota">{{ $cobrarTodo['perdida']->esCero() ? 'Utilidad' : 'Pérdida' }}, sin gastos nuevos</div>
        </div>
    </div>

    {{-- ── 1 · Ingresos ──────────────────────────────────────────── --}}

    <h2><span class="numeral">1</span> Ingresos acumulados</h2>

    <table>
        <thead>
            <tr>
                <th>Concepto</th>
                <th class="cifra-col">Monto</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($general->cobradoPorConcepto as $clave => $monto)
                <tr class="renglon">
                    <td class="rotulo">{{ \App\Domain\Enums\ConceptoDeRecibo::from($clave)->etiqueta() }}</td>
                    <td>{{ $monto->formateado() }}</td>
                </tr>
            @empty
                <tr class="renglon">
                    <td class="rotulo apagado">Sin cobros registrados</td>
                    <td class="apagado">L. 0.00</td>
                </tr>
            @endforelse

            <tr class="subtotal">
                <td>Total de ingresos</td>
                <td>{{ $general->cobrado->formateado() }}</td>
            </tr>
        </tbody>
    </table>

    @unless ($general->cobradoPorForma === [])
        <div class="franja">
            <span class="titulo-franja">Por forma de cobro</span>
            <div class="pares">
                @foreach ($general->cobradoPorForma as $clave => $monto)
                    <span class="par">{{ \App\Domain\Enums\FormaDePago::from($clave)->etiqueta() }}
                        <b>{{ $monto->formateado() }}</b>
                        <span class="chico">· {{ $pctTexto($monto, $general->cobrado) }} %</span></span>
                @endforeach
            </div>
        </div>
    @endunless

    {{-- Quién recibió el dinero de los clientes, y en qué forma. Las columnas
         son las formas de la franja de arriba, en el mismo orden: cada
         columna suma lo que dice la franja. --}}
    @unless ($general->cobradoPorPersona === [])
        <div class="franja tabla-ancha">
            <span class="titulo-franja">Quién recibió el dinero</span>
            <table>
                <thead>
                    <tr>
                        <th>Persona</th>
                        @foreach (array_keys($general->cobradoPorForma) as $clave)
                            <th>{{ \App\Domain\Enums\FormaDePago::from($clave)->etiqueta() }}</th>
                        @endforeach
                        <th>Total</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($general->cobradoPorPersona as $persona)
                        <tr>
                            <td>{{ $persona['nombre'] }}</td>
                            @foreach (array_keys($general->cobradoPorForma) as $clave)
                                @if (isset($persona['porForma'][$clave]))
                                    <td>{{ $persona['porForma'][$clave]->formateado() }}</td>
                                @else
                                    <td class="apagado">—</td>
                                @endif
                            @endforeach
                            <td class="total">{{ $persona['total']->formateado() }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endunless

    {{-- ── 2 · Egresos y resultado ───────────────────────────────── --}}

    <h2><span class="numeral">2</span> Egresos acumulados y resultado</h2>

    <table>
        <thead>
            <tr>
                <th>Rubro</th>
                <th class="cifra-col">Monto</th>
            </tr>
        </thead>
        <tbody>
            @if ($general->gastadoPorCategoria === [] && $general->devuelto->esCero())
                <tr class="renglon">
                    <td class="rotulo apagado">Sin egresos registrados</td>
                    <td class="apagado">L. 0.00</td>
                </tr>
            @else
                @foreach ($general->gastadoPorCategoria as $clave => $monto)
                    <tr class="renglon">
                        <td class="rotulo">{{ \App\Domain\Enums\CategoriaDeGasto::from($clave)->etiqueta() }}
                            <span class="chico">· {{ $pctTexto($monto, $general->salidas) }} %</span></td>
                        <td>{{ $monto->formateado() }}</td>
                    </tr>
                @endforeach

                @unless ($general->devuelto->esCero())
                    <tr class="renglon">
                        <td class="rotulo sangria">Devuelto a clientes</td>
                        <td>{{ $general->devuelto->formateado() }}</td>
                    </tr>
                @endunless
            @endif

            <tr class="subtotal">
                <td>Total de egresos</td>
                <td>{{ $general->salidas->formateado() }}</td>
            </tr>

            {{-- El resultado, dicho como en un estado de resultados: lo que
                 entró, lo que salió y lo que quedó. --}}
            <tr class="renglon">
                <td class="rotulo" style="padding-top: .75rem">Ingresos acumulados</td>
                <td style="padding-top: .75rem">{{ $general->cobrado->formateado() }}</td>
            </tr>
            <tr class="renglon">
                <td class="rotulo">Menos los egresos</td>
                <td>({{ $general->salidas->formateado() }})</td>
            </tr>

            <tr @class(['cierre', 'perdida' => $enPerdida])>
                <td>{{ $enPerdida ? 'Pérdida acumulada' : 'Utilidad acumulada' }}</td>
                <td>{{ $enPerdida ? $general->perdida->formateado() : $general->utilidad->formateado() }}</td>
            </tr>
        </tbody>
    </table>

    @if ($general->hayGastosDeSocios())
        <table style="margin-top: .75rem">
            <thead>
                <tr>
                    <th>Pagado por socios, de su bolsa</th>
                    <th class="cifra-col">Monto</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($general->puestoPorSocios as $puesto)
                    <tr class="renglon">
                        <td class="rotulo">{{ $puesto['nombre'] }}</td>
                        <td>{{ $puesto['monto']->formateado() }}</td>
                    </tr>
                @endforeach

                <tr class="subtotal">
                    <td>Total pagado por socios</td>
                    <td>{{ $general->puestoPorSociosTotal->formateado() }}</td>
                </tr>
            </tbody>
        </table>

        <p class="vacio-nota">
            Ya están en los egresos de arriba. No salieron de la caja: a cada socio se le devuelve lo que
            puso, encima de lo que le toca.
        </p>
    @endif

    {{-- ── 3 · Año por año ───────────────────────────────────────── --}}

    <h2><span class="numeral">3</span> Año por año</h2>

    @if ($general->anioPorAnio === [])
        <p class="vacio-nota">Todavía no hay movimientos que contar.</p>
    @else
        <div class="tabla-ancha">
            <table>
                <thead>
                    <tr>
                        <th>Año</th>
                        <th>Ingresos</th>
                        <th>Egresos</th>
                        <th>Resultado</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($general->anioPorAnio as $fila)
                        <tr @class([
                            'renglon',
                            'actual' => $fila['anio'] === $general->hoy->year,
                            'perdida' => ! $fila['perdida']->esCero(),
                        ])>
                            <td>{{ $fila['anio'] }}</td>
                            <td>{{ $fila['ingresos']->formateado() }}</td>
                            <td>{{ $fila['egresos']->formateado() }}</td>
                            <td>
                                {{ $fila['perdida']->esCero()
                                    ? $fila['utilidad']->formateado()
                                    : '('.$fila['perdida']->formateado().')' }}
                            </td>
                            <td class="con-barra" title="Ingresos {{ $fila['anio'] }}: {{ $fila['ingresos']->formateado() }}">
                                @unless ($fila['ingresos']->esCero())
                                    <span class="barrita ingresos" style="width: {{ $pct($fila['ingresos'], $mayorAnual, 1) }}%"></span>
                                @endunless
                            </td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr>
                        <td>Total</td>
                        <td>{{ $general->cobrado->formateado() }}</td>
                        <td>{{ $general->salidas->formateado() }}</td>
                        <td @class(['perdida' => $enPerdida])>
                            {{ $enPerdida ? '('.$general->perdida->formateado().')' : $general->utilidad->formateado() }}
                        </td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    @endif

    {{-- ── 4 · Los socios ────────────────────────────────────────── --}}

    <h2><span class="numeral">4</span> Los socios</h2>

    @if ($socios->partes === [] && $general->exSocios === [])
        <p class="vacio-nota">
            Este proyecto todavía no tiene socios cargados. Se agregan en el proyecto, pestaña «Socios», con
            el porcentaje de cada uno.
        </p>
    @else
        @unless ($socios->partes === [])
            <div class="tabla-ancha">
                <table class="reparto">
                    <thead>
                        <tr>
                            <th>Socio</th>
                            <th>Parte</th>
                            <th>Le toca</th>
                            @if ($general->hayGastosDeSocios())
                                <th>Puso</th>
                            @endif
                            @unless ($socios->cubierto()->esCero())
                                <th>Cubre</th>
                            @endunless
                            <th>Entregado</th>
                            <th>Por entregarle</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($socios->partes as $parte)
                            <tr @class(['renglon', 'sobregirado' => $parte->estaSobregirado()])>
                                <td>{{ $parte->nombre() }}</td>
                                <td>{{ $parte->porcentajeEscrito() }}</td>
                                <td>{{ $parte->leToca->formateado() }}</td>
                                @if ($general->hayGastosDeSocios())
                                    <td>{{ $parte->puso->formateado() }}</td>
                                @endif
                                @unless ($socios->cubierto()->esCero())
                                    <td>{{ $parte->cubre->formateado() }}</td>
                                @endunless
                                <td>{{ $parte->entregado->formateado() }}</td>
                                <td>
                                    @if ($parte->estaSobregirado())
                                        <span class="marca">{{ $parte->marcaDelSobregiro() }}</span>
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
                            <td>{{ $socios->leToca()->formateado() }}</td>
                            @if ($general->hayGastosDeSocios())
                                <td>{{ $socios->puesto()->formateado() }}</td>
                            @endif
                            @unless ($socios->cubierto()->esCero())
                                <td>{{ $socios->cubierto()->formateado() }}</td>
                            @endunless
                            <td>{{ $socios->entregado->formateado() }}</td>
                            <td>{{ $socios->porEntregar->formateado() }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <p class="vacio-nota">
                Sobre la utilidad de toda la vida del proyecto. Puede no coincidir con la suma de los
                estados mensuales: aquellos reparten mes a mes y no descuentan los meses en pérdida; este sí.
            </p>
        @endunless

        {{-- Cómo se le entregó a cada socio: la misma cuenta de dos entradas
             que la de quién recibió el dinero, del otro lado del mostrador. --}}
        @if ($general->entregadoATodos()->esCero())
            <p class="vacio-nota">Todavía no se ha anotado ninguna entrega a los socios.</p>
        @else
            <div class="franja tabla-ancha">
                <span class="titulo-franja">Lo que se le ha entregado a cada socio, y en qué forma</span>
                <table>
                    <thead>
                        <tr>
                            <th>Socio</th>
                            @foreach ($formasDeEntrega as $clave)
                                <th>{{ \App\Domain\Enums\FormaDePago::from($clave)->etiqueta() }}</th>
                            @endforeach
                            <th>Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($socios->partes as $parte)
                            @php $porForma = $general->entregadoA($parte->socio); @endphp
                            <tr>
                                <td>{{ $parte->nombre() }}</td>
                                @foreach ($formasDeEntrega as $clave)
                                    @if (isset($porForma[$clave]))
                                        <td>{{ $porForma[$clave]->formateado() }}</td>
                                    @else
                                        <td class="apagado">—</td>
                                    @endif
                                @endforeach
                                <td class="total">{{ $parte->entregado->formateado() }}</td>
                            </tr>
                        @endforeach
                        @foreach ($general->exSocios as $ex)
                            <tr>
                                <td>{{ $ex['nombre'] }} <span class="chico">· ya no participa</span></td>
                                @foreach ($formasDeEntrega as $clave)
                                    @if (isset($ex['porForma'][$clave]))
                                        <td>{{ $ex['porForma'][$clave]->formateado() }}</td>
                                    @else
                                        <td class="apagado">—</td>
                                    @endif
                                @endforeach
                                <td class="total">{{ $ex['entregado']->formateado() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @unless ($general->sinRepartir()->esCero())
            <div class="aviso">
                Quedan {{ $general->sinRepartir()->formateado() }} sin dueño: los porcentajes de los socios
                activos no suman 100 %. Se revisa en el proyecto, pestaña «Socios».
            </div>
        @endunless
    @endif

    {{-- ── 5 · Lo vendido y lo que falta cobrar ──────────────────── --}}

    <h2><span class="numeral">5</span> Lo vendido y lo que falta cobrar</h2>

    @if ($general->vendido->esCero() && $general->porCobrar->esCero())
        <p class="vacio-nota">Todavía no hay lotes vendidos en este proyecto.</p>
    @else
        @php
            $pagado = $general->yaPagadoDeLoVendido();
            $porVencer = $general->porVencer();
            $cartera = $general->carteraTotal();
        @endphp

        <div class="cartera" role="img" aria-label="Lo vendido: pagado, por vencer y atrasado">
            @unless ($pagado->esCero())
                <span style="flex: {{ $pct($pagado, $cartera, 2) }} 1 0; background: var(--pagado)"
                      title="Ya pagado: {{ $pagado->formateado() }}"></span>
            @endunless
            @unless ($porVencer->esCero())
                <span style="flex: {{ $pct($porVencer, $cartera, 2) }} 1 0; background: var(--por-vencer)"
                      title="Por vencer: {{ $porVencer->formateado() }}"></span>
            @endunless
            @unless ($general->atrasado->esCero())
                <span style="flex: {{ $pct($general->atrasado, $cartera, 2) }} 1 0; background: var(--atrasado)"
                      title="Atrasado: {{ $general->atrasado->formateado() }}"></span>
            @endunless
        </div>

        <div class="leyenda">
            <span><i class="muestra" style="background: var(--pagado)"></i>Ya pagado
                <b>{{ $pagado->formateado() }}</b> · {{ $pctTexto($pagado, $cartera) }} %</span>
            <span><i class="muestra" style="background: var(--por-vencer)"></i>Por vencer
                <b>{{ $porVencer->formateado() }}</b> · {{ $pctTexto($porVencer, $cartera) }} %</span>
            <span><i class="muestra" style="background: var(--atrasado)"></i>Atrasado
                <b>{{ $general->atrasado->formateado() }}</b> · {{ $pctTexto($general->atrasado, $cartera) }} %</span>
        </div>

        <div class="tabla-ancha">
            <table style="margin-top: .875rem">
                <thead>
                    <tr>
                        <th>Lo que falta cobrar, por año</th>
                        <th>Cuotas</th>
                        <th>Monto</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @unless ($general->atrasado->esCero())
                        <tr class="renglon atrasado-fila">
                            <td>Atrasado, ya vencido</td>
                            <td class="apagado">{{ $general->expedientesAtrasados }} exp.</td>
                            <td>{{ $general->atrasado->formateado() }}</td>
                            <td class="con-barra" title="Atrasado: {{ $general->atrasado->formateado() }}">
                                <span class="barrita atrasado" style="width: {{ $pct($general->atrasado, $mayorPorCobrar, 1) }}%"></span>
                            </td>
                        </tr>
                    @endunless
                    @forelse ($general->porCobrarPorAnio as $fila)
                        <tr class="renglon">
                            <td>{{ $fila['anio'] }}@if ($fila['anio'] === $general->hoy->year) <span class="chico">· lo que queda del año</span>@endif</td>
                            <td>{{ $fila['cuotas'] }}</td>
                            <td>{{ $fila['monto']->formateado() }}</td>
                            <td class="con-barra" title="{{ $fila['anio'] }}: {{ $fila['monto']->formateado() }}">
                                <span class="barrita vence" style="width: {{ $pct($fila['monto'], $mayorPorCobrar, 1) }}%"></span>
                            </td>
                        </tr>
                    @empty
                        <tr class="renglon">
                            <td class="apagado" colspan="4">No queda nada por vencer.</td>
                        </tr>
                    @endforelse
                </tbody>
                <tfoot>
                    <tr>
                        <td>Total por cobrar</td>
                        <td></td>
                        <td>{{ $general->porCobrar->formateado() }}</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    @endif

    @unless ($general->seniasEnCurso->esCero())
        <div class="aviso tranquilo">
            Además hay {{ $general->seniasEnCurso->formateado() }} de señas en {{ $general->apartadosEnCurso }}
            {{ $general->apartadosEnCurso === 1 ? 'apartado' : 'apartados' }} en curso. Entraron a la caja, pero
            no cuentan como ingreso hasta que el apartado se convierte en venta.
        </div>
    @endunless

    {{-- ── 6 · Los lotes ─────────────────────────────────────────── --}}

    <h2><span class="numeral">6</span> Los lotes</h2>

    @if ($general->lotes === [])
        <p class="vacio-nota">Este proyecto todavía no tiene lotes cargados.</p>
    @else
        <table>
            <thead>
                <tr>
                    <th>Estado</th>
                    <th>Lotes</th>
                    <th>Del total</th>
                    <th class="cifra-col">Valor de lista</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($general->lotes as $fila)
                    <tr class="renglon">
                        <td><i class="punto" style="background: {{ $fila['estado']->colorHex() }}"></i>{{ $fila['estado']->etiquetaInterna() }}</td>
                        <td>{{ $fila['cuantos'] }}</td>
                        <td class="apagado">{{ $general->parteDeLotes($fila['cuantos']) }} %</td>
                        <td>{{ $fila['valor']->formateado() }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td>Total</td>
                    <td>{{ $general->totalDeLotes() }}</td>
                    <td>100 %</td>
                    <td>{{ $general->valorDeLosLotes()->formateado() }}</td>
                </tr>
            </tfoot>
        </table>

        @if ($general->lotesSinPrecio() > 0)
            <div class="aviso">
                {{ $general->lotesSinPrecio() }} {{ $general->lotesSinPrecio() === 1 ? 'lote por vender no tiene' : 'lotes por vender no tienen' }}
                precio cargado: lo que falta vender sale más bajo de lo que es.
            </div>
        @endif
    @endif

    {{-- ── 7 · Hacia dónde va ────────────────────────────────────── --}}

    <h2><span class="numeral">7</span> Hacia dónde va el proyecto</h2>

    @if (! $general->huboMovimiento() && $general->vendido->esCero() && $general->lotesPorVender() === 0)
        <p class="vacio-nota">Sin cobros, ventas ni lotes por vender todavía: no hay nada que proyectar.</p>
    @else
    <table>
        <tbody>
            <tr @class(['renglon', 'perdida' => $enPerdida])>
                <td class="rotulo">{{ $enPerdida ? 'Pérdida a la fecha' : 'Utilidad a la fecha' }}</td>
                <td class="cifra-col">{{ $enPerdida ? '('.$general->perdida->formateado().')' : $general->utilidad->formateado() }}</td>
            </tr>
            <tr class="renglon">
                <td class="rotulo sangria">Más lo que falta cobrar de lo vendido</td>
                <td>{{ $general->porCobrar->formateado() }}</td>
            </tr>
            <tr @class(['subtotal', 'perdida' => ! $cobrarTodo['perdida']->esCero()])>
                <td>Si se cobra todo lo vendido</td>
                <td>
                    {{ $cobrarTodo['perdida']->esCero()
                        ? $cobrarTodo['utilidad']->formateado()
                        : '('.$cobrarTodo['perdida']->formateado().')' }}
                </td>
            </tr>
            <tr class="renglon">
                <td class="rotulo sangria">
                    @if ($general->lotesPorVender() === 0)
                        No quedan lotes por vender
                    @else
                        Más {{ $general->lotesPorVender() }} {{ $general->lotesPorVender() === 1 ? 'lote' : 'lotes' }} por vender, a precio de lista
                    @endif
                </td>
                <td>{{ $general->porVender()->formateado() }}</td>
            </tr>
            <tr @class(['cierre', 'perdida' => ! $venderTodo['perdida']->esCero()])>
                <td>Si además se vende todo</td>
                <td>
                    {{ $venderTodo['perdida']->esCero()
                        ? $venderTodo['utilidad']->formateado()
                        : '('.$venderTodo['perdida']->formateado().')' }}
                </td>
            </tr>
        </tbody>
    </table>

    <p class="vacio-nota">
        Es una proyección, no una promesa: no cuenta gastos que todavía no se hicieron, ni mora, ni descuentos
        futuros. Lo que falta vender va a su precio de lista de hoy.
    </p>
    @endif

    <div class="firmas">
        <div class="firma">Preparado por</div>
        <div class="firma">Revisado por</div>
    </div>

    <div class="notas">
        <b>Notas.</b> Los ingresos son todos los recibos del proyecto sin contar los anulados; los egresos,
        todos los gastos más lo devuelto a clientes. Son las mismas cuentas del estado mensual: la suma de
        los meses da estos totales. Una seña cuenta como ingreso cuando el apartado se convierte en venta.
        Un gasto que un socio pagó de su bolsa es egreso del proyecto y se le devuelve en el reparto. «Ya
        pagado» es lo vendido menos lo que falta cobrar: incluye prima, cuotas y abonos, y lo perdonado en
        un pronto pago. Lo que falta cobrar son las cuotas con saldo de expedientes vigentes, sin lotes
        rescindidos; «atrasado» es lo que venció antes de hoy. Un resultado entre paréntesis es pérdida.
    </div>

    <div class="pie">
        <span>{{ $emisor['residencial'] ?? '' }} · Estado general · {{ $general->fechaDeCorteEscrita() }}</span>
        <span>Emitido el {{ now()->format('d/m/Y H:i') }}</span>
    </div>

</div>

</body>
</html>
