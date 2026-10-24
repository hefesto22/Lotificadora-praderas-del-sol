{{--
    La pantalla «Caja» — 24-oct-2026.

    El cálculo vive en App\Domain\Reportes\CajaPorDia y las etiquetas en la
    clase de la página: acá solo se imprime texto que ya viene armado.

    Sin utilidades de Tailwind, por lo mismo que el resto del chasis: el CSS
    de Filament se compila aparte y una clase que Vite no vio no existe en el
    panel. Los estilos de esta pantalla van en el style de abajo; la tabla
    reusa .olympo-tabla y .olympo-scroll, que inyecta el panel en el head.
--}}
<x-filament-panels::page>
    <style>
        .olympo-caja-cifras {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr));
            gap: .75rem;
        }
        .olympo-caja-cifra {
            display: flex;
            flex-direction: column;
            gap: .25rem;
            min-width: 0;
            padding: .875rem 1rem;
            border: 1px solid rgb(228 230 235);
            border-radius: .75rem;
            background: rgb(250 250 251);
        }
        .olympo-caja-cifra.total { border-color: rgba(22, 163, 74, .32); background: rgba(240, 253, 244, .8); }
        .olympo-caja-rotulo {
            font-size: .6875rem;
            font-weight: 600;
            letter-spacing: .055em;
            text-transform: uppercase;
            color: rgb(107 114 128);
        }
        .olympo-caja-monto {
            font-size: 1.375rem;
            font-weight: 700;
            line-height: 1.2;
            white-space: nowrap;
            font-variant-numeric: tabular-nums;
            color: rgb(9 9 11);
        }
        .olympo-caja-nota { font-size: .75rem; line-height: 1.5; color: rgb(107 114 128); }

        /* Los renglones de la tabla de afuera, y solo esos: la tabla del
           detalle va adentro y también es .olympo-tabla. */
        .olympo-caja-tabla > thead > tr > th:first-child,
        .olympo-caja-tabla > tbody > tr > td:first-child,
        .olympo-caja-tabla > tfoot > tr > td:first-child { padding-left: .5rem; }
        .olympo-caja-tabla > thead > tr > th:last-child,
        .olympo-caja-tabla > tbody > tr > td:last-child,
        .olympo-caja-tabla > tfoot > tr > td:last-child { padding-right: .5rem; }

        .olympo-caja-dia { cursor: pointer; }
        .olympo-caja-dia:hover > td { background: rgb(248 250 252); }
        .olympo-caja-dia:focus-visible { outline: 2px solid rgb(148 163 184); outline-offset: -2px; }
        .olympo-caja-dia.abierto > td { background: rgb(241 245 249); color: rgb(9 9 11); }
        .olympo-caja-flecha {
            display: inline-block;
            width: 1rem;
            color: rgb(161 161 170);
            transition: transform .15s ease;
        }
        .olympo-caja-dia.abierto .olympo-caja-flecha { transform: rotate(90deg); }

        .olympo-caja-tabla > tbody > tr.olympo-caja-detalle > td {
            padding: 0 .5rem 1rem;
            white-space: normal;
            background: rgb(241 245 249);
        }
        .olympo-caja-adentro {
            padding: .75rem 1rem;
            text-align: left;
            border: 1px solid rgb(228 230 235);
            border-radius: .625rem;
            background: #fff;
        }
        .olympo-caja-quienes {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: .375rem .5rem;
            margin-bottom: .75rem;
        }
        .olympo-caja-pastilla {
            display: inline-flex;
            gap: .375rem;
            padding: .25rem .625rem;
            border: 1px solid rgb(228 230 235);
            border-radius: 9999px;
            font-size: .75rem;
            white-space: nowrap;
            color: rgb(63 63 70);
        }
        .olympo-caja-pastilla strong { font-weight: 600; font-variant-numeric: tabular-nums; color: rgb(9 9 11); }
        .olympo-tabla th.olympo-caja-texto,
        .olympo-tabla td.olympo-caja-texto { text-align: left; }
        .olympo-caja-folio {
            font-weight: 600;
            color: rgb(9 9 11);
            text-decoration: underline;
            text-decoration-color: rgb(212 212 216);
            text-underline-offset: 3px;
        }
        .olympo-caja-folio:hover { text-decoration-color: currentColor; }

        .olympo-caja-tabla > tfoot > tr > td {
            padding-top: .75rem;
            border-top: 1px solid rgb(212 212 216);
            border-bottom: 0;
            font-weight: 700;
            color: rgb(9 9 11);
        }

        .dark .olympo-caja-cifra { border-color: rgba(255, 255, 255, .10); background: rgba(255, 255, 255, .03); }
        .dark .olympo-caja-cifra.total { border-color: rgba(34, 197, 94, .35); background: rgba(34, 197, 94, .08); }
        .dark .olympo-caja-rotulo, .dark .olympo-caja-nota { color: rgb(148 154 165); }
        .dark .olympo-caja-monto { color: rgb(244 244 245); }
        .dark .olympo-caja-dia:hover > td { background: rgba(255, 255, 255, .04); }
        .dark .olympo-caja-dia.abierto > td,
        .dark .olympo-caja-tabla > tbody > tr.olympo-caja-detalle > td { background: rgba(255, 255, 255, .06); color: #fff; }
        .dark .olympo-caja-adentro { border-color: rgba(255, 255, 255, .10); background: rgb(24 24 27); }
        .dark .olympo-caja-pastilla { border-color: rgba(255, 255, 255, .12); color: rgb(212 212 216); }
        .dark .olympo-caja-pastilla strong,
        .dark .olympo-caja-folio,
        .dark .olympo-caja-tabla > tfoot > tr > td { color: #fff; }
        .dark .olympo-caja-tabla > tfoot > tr > td { border-top-color: rgba(255, 255, 255, .14); }
    </style>

    {{ $this->form }}

    @php
        $caja = $this->caja();
        $periodo = $this->periodo();
        $formas = $caja->formas();
        $mejor = $caja->elDiaQueMasEntro();
        $columnas = count($formas) + 3;
    @endphp

    <x-filament::section
        :heading="$this->soloLoMio() ? 'Lo que recibiste' : 'Lo que entró'"
        :description="$this->periodoEscrito($caja)"
    >
        <div class="olympo-caja-cifras">
            <div class="olympo-caja-cifra total">
                <span class="olympo-caja-rotulo">Total</span>
                <span class="olympo-caja-monto">{{ $caja->total->formateado() }}</span>
                <span class="olympo-caja-nota">{{ $this->cuantosRecibos($caja) }}</span>
            </div>

            <div class="olympo-caja-cifra">
                <span class="olympo-caja-rotulo">En efectivo</span>
                <span class="olympo-caja-monto">{{ $caja->efectivo()->formateado() }}</span>
                <span class="olympo-caja-nota">Lo que entró en billetes</span>
            </div>

            <div class="olympo-caja-cifra">
                <span class="olympo-caja-rotulo">Por banco y tarjeta</span>
                <span class="olympo-caja-monto">{{ $caja->porBanco()->formateado() }}</span>
                <span class="olympo-caja-nota">{{ $this->desgloseDelBanco($caja) }}</span>
            </div>

            @if ($mejor !== null && count($caja->dias) > 1)
                <div class="olympo-caja-cifra">
                    <span class="olympo-caja-rotulo">El día que más entró</span>
                    <span class="olympo-caja-monto">{{ $mejor->total->formateado() }}</span>
                    <span class="olympo-caja-nota">{{ $this->nombreDelDia($caja, $mejor) }}</span>
                </div>
            @endif
        </div>

        @if ($this->soloLoMio())
            <p class="olympo-nota">Aquí se ve solo lo que recibiste vos. La caja completa la ve la administración.</p>
        @endif

        @if ($periodo['recortado'])
            <p class="olympo-nota">Se muestra un año como mucho: el rango se recortó desde la fecha «hasta» hacia atrás.</p>
        @endif
    </x-filament::section>

    <x-filament::section heading="Día por día">
        @if (! $caja->huboMovimiento())
            <p class="olympo-vacio">No entró dinero en esos días. Probá con otras fechas o con otros proyectos.</p>
        @else
            <div class="olympo-scroll">
                <table class="olympo-tabla olympo-caja-tabla">
                    <thead>
                        <tr>
                            <th>Día</th>
                            <th>Recibos</th>
                            @foreach ($formas as $forma)
                                <th>{{ $forma->etiqueta() }}</th>
                            @endforeach
                            <th>Total</th>
                        </tr>
                    </thead>

                    <tbody>
                        @foreach ($caja->dias as $dia)
                            @php
                                $clave = $dia->fecha->toDateString();
                                $abierto = $this->diaAbierto === $clave;
                            @endphp

                            <tr
                                wire:key="dia-{{ $clave }}"
                                wire:click="abrir('{{ $clave }}')"
                                wire:keydown.enter="abrir('{{ $clave }}')"
                                role="button"
                                tabindex="0"
                                aria-expanded="{{ $abierto ? 'true' : 'false' }}"
                                @class(['olympo-caja-dia', 'abierto' => $abierto])
                            >
                                <td class="lote"><span class="olympo-caja-flecha" aria-hidden="true">›</span>{{ $this->nombreDelDia($caja, $dia) }}</td>
                                <td>{{ $dia->recibos }}</td>
                                @foreach ($formas as $forma)
                                    @php
                                        $monto = $dia->deLaForma($forma);
                                    @endphp
                                    <td @class(['apagado' => $monto->esCero()])>{{ $monto->esCero() ? '—' : $monto->formateado() }}</td>
                                @endforeach
                                <td class="fuerte">{{ $dia->total->formateado() }}</td>
                            </tr>

                            @if ($abierto)
                                @php
                                    $detalle = $this->detalleDelDia($dia);
                                @endphp

                                <tr wire:key="detalle-{{ $clave }}" class="olympo-caja-detalle">
                                    <td colspan="{{ $columnas }}">
                                        <div class="olympo-caja-adentro">
                                            @if ($detalle['quienes'] !== [])
                                                <div class="olympo-caja-quienes">
                                                    <span class="olympo-caja-rotulo">Lo recibió</span>
                                                    @foreach ($detalle['quienes'] as $quien)
                                                        <span class="olympo-caja-pastilla">{{ $quien['nombre'] }} <strong>{{ $quien['total']->formateado() }}</strong></span>
                                                    @endforeach
                                                </div>
                                            @endif

                                            <div class="olympo-scroll">
                                                <table class="olympo-tabla">
                                                    <thead>
                                                        <tr>
                                                            <th>Recibo</th>
                                                            <th class="olympo-caja-texto">Recibí de</th>
                                                            <th class="olympo-caja-texto">Lote</th>
                                                            <th class="olympo-caja-texto">Concepto</th>
                                                            <th class="olympo-caja-texto">Forma</th>
                                                            <th class="olympo-caja-texto">Lo recibió</th>
                                                            <th>Monto</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        @foreach ($detalle['recibos'] as $recibo)
                                                            <tr wire:key="recibo-{{ $clave }}-{{ $loop->index }}">
                                                                <td><a href="{{ $recibo['url'] }}" class="olympo-caja-folio">{{ $recibo['folio'] }}</a></td>
                                                                <td class="olympo-caja-texto">{{ $recibo['nombre'] }}</td>
                                                                <td class="olympo-caja-texto">{{ $recibo['lotes'] }}</td>
                                                                <td class="olympo-caja-texto">{{ $recibo['concepto'] }}</td>
                                                                <td class="olympo-caja-texto">{{ $recibo['forma'] }}</td>
                                                                <td class="olympo-caja-texto">{{ $recibo['recibio'] }}</td>
                                                                <td class="fuerte">{{ $recibo['monto'] }}</td>
                                                            </tr>
                                                        @endforeach
                                                    </tbody>
                                                </table>
                                            </div>

                                            @if ($detalle['faltan'] > 0)
                                                <p class="olympo-nota">Y {{ $detalle['faltan'] }} {{ $detalle['faltan'] === 1 ? 'recibo' : 'recibos' }} más de ese día: la lista completa está en Recibos.</p>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>

                    <tfoot>
                        <tr>
                            <td>Total</td>
                            <td>{{ $caja->recibos }}</td>
                            @foreach ($formas as $forma)
                                <td>{{ $caja->deLaForma($forma)->formateado() }}</td>
                            @endforeach
                            <td>{{ $caja->total->formateado() }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
