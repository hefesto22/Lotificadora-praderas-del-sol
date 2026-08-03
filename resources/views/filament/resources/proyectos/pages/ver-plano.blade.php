@use('App\Domain\Enums\EstadoLote')

<x-filament-panels::page>
    @php
        $resumen = $plano['resumen'];
        $tarjetas = [
            ['etiqueta' => 'Lotes',       'valor' => array_sum($resumen),    'color' => null],
            ['etiqueta' => 'Disponibles', 'valor' => $resumen['disponible'], 'color' => EstadoLote::Disponible->colorHex()],
            ['etiqueta' => 'Apartados',   'valor' => $resumen['apartado'],   'color' => EstadoLote::Apartado->colorHex()],
            ['etiqueta' => 'Vendidos',    'valor' => $resumen['vendido'],    'color' => EstadoLote::Vendido->colorHex()],
            ['etiqueta' => 'Sin dibujar', 'valor' => $plano['sinDibujar'],   'color' => null],
        ];
    @endphp

    {{--
        CSS propio y no clases de Tailwind: el panel de Filament sirve su
        bundle compilado y NO carga resources/css/app.css (no hay
        ->viteTheme() en AdminPanelProvider), asi que las utilidades que
        Filament no usa internamente no existirian y la pagina saldria
        sin estilos. Esto se sostiene solo, sin paso de build.

        Si algun dia se registra un tema propio de Filament, esto se puede
        reemplazar por clases y borrar el bloque entero.
    --}}
    <style>
        .plano-stats { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .75rem; }
        @media (min-width: 640px) { .plano-stats { grid-template-columns: repeat(5, minmax(0, 1fr)); } }

        .plano-card {
            border: 1px solid rgb(228 228 231); background: #fff;
            border-radius: .75rem; padding: 1rem;
        }
        .dark .plano-card { border-color: rgba(255, 255, 255, .1); background: rgb(24 24 27); }

        .plano-stat-label { display: flex; align-items: center; gap: .5rem; font-size: .75rem; font-weight: 500; color: rgb(113 113 122); }
        .dark .plano-stat-label { color: rgb(161 161 170); }
        .plano-stat-punto { width: .625rem; height: .625rem; border-radius: 9999px; flex-shrink: 0; }
        .plano-stat-valor { margin-top: .25rem; font-size: 1.5rem; font-weight: 600; font-variant-numeric: tabular-nums; color: rgb(9 9 11); }
        .dark .plano-stat-valor { color: #fff; }

        .plano-grid { display: grid; gap: 1rem; grid-template-columns: 1fr; margin-top: 1rem; }
        @media (min-width: 1024px) { .plano-grid { grid-template-columns: 2fr 1fr; } }

        .plano-lienzo { padding: .75rem; overflow: hidden; }
        .plano-lienzo svg { width: 100%; height: auto; display: block; }

        .lote { cursor: pointer; transition: fill-opacity .12s ease; }
        .lote:hover { fill-opacity: 1; }

        .plano-vacio {
            border: 1px dashed rgb(212 212 216); background: rgb(250 250 250);
            border-radius: .75rem; padding: 2rem; text-align: center; margin-top: 1rem;
        }
        .dark .plano-vacio { border-color: rgba(255, 255, 255, .2); background: rgba(255, 255, 255, .05); }
        .plano-vacio-titulo { font-size: .875rem; font-weight: 500; color: rgb(9 9 11); }
        .dark .plano-vacio-titulo { color: #fff; }
        .plano-vacio-texto { max-width: 34rem; margin: .5rem auto 0; font-size: .875rem; line-height: 1.6; color: rgb(113 113 122); }
        .dark .plano-vacio-texto { color: rgb(161 161 170); }

        .plano-detalle-vacio { font-size: .875rem; color: rgb(113 113 122); }
        .dark .plano-detalle-vacio { color: rgb(161 161 170); }
        .plano-detalle-cabecera { display: flex; align-items: flex-start; justify-content: space-between; gap: .75rem; }
        .plano-detalle-codigo { font-size: 1.125rem; font-weight: 600; color: rgb(9 9 11); }
        .dark .plano-detalle-codigo { color: #fff; }
        .plano-detalle-sub { font-size: .875rem; color: rgb(113 113 122); }
        .dark .plano-detalle-sub { color: rgb(161 161 170); }
        .plano-badge { flex-shrink: 0; border-radius: .375rem; padding: .25rem .5rem; font-size: .75rem; font-weight: 500; color: #fff; }

        .plano-datos { margin-top: 1rem; padding-top: 1rem; border-top: 1px solid rgb(244 244 245); font-size: .875rem; }
        .dark .plano-datos { border-top-color: rgba(255, 255, 255, .1); }
        .plano-dato { display: flex; align-items: baseline; justify-content: space-between; gap: 1rem; padding: .25rem 0; }
        .plano-dato dt { color: rgb(113 113 122); }
        .dark .plano-dato dt { color: rgb(161 161 170); }
        .plano-dato dd { font-variant-numeric: tabular-nums; color: rgb(9 9 11); margin: 0; }
        .dark .plano-dato dd { color: #fff; }

        .plano-aviso {
            margin-top: 1rem; border-radius: .5rem; padding: .75rem;
            background: rgb(255 251 235); color: rgb(120 53 15);
            font-size: .75rem; line-height: 1.6;
        }
        .dark .plano-aviso { background: rgba(251, 191, 36, .1); color: rgb(253 230 138); }
    </style>

    <div class="plano-stats">
        @foreach ($tarjetas as $tarjeta)
            <div class="plano-card">
                <div class="plano-stat-label">
                    @if ($tarjeta['color'] !== null)
                        <span class="plano-stat-punto" style="background: {{ $tarjeta['color'] }}"></span>
                    @endif
                    {{ $tarjeta['etiqueta'] }}
                </div>
                <div class="plano-stat-valor">{{ $tarjeta['valor'] }}</div>
            </div>
        @endforeach
    </div>

    @if (! $plano['hayGeometria'])
        <div class="plano-vacio">
            <p class="plano-vacio-titulo">Todavía no hay nada dibujado en este proyecto.</p>
            <p class="plano-vacio-texto">
                Los {{ $plano['sinDibujar'] }} lotes cargados existen y se venden igual — el plano es una capa
                encima del negocio, no un requisito. Para dibujarlos, generá los lotes de un bloque con el
                generador y volvé a esta página.
            </p>
        </div>
    @else
        <div
            x-data="{
                lotes: @js($plano['lotes']),
                seleccionado: null,
                seleccionar(indice) {
                    const lote = this.lotes[indice];
                    this.seleccionado = this.seleccionado?.id === lote.id ? null : lote;
                },
            }"
            class="plano-grid"
        >
            <div class="plano-card plano-lienzo">
                <svg viewBox="{{ $plano['viewBox'] }}" preserveAspectRatio="xMidYMid meet">
                    {{-- Las calles van primero: quedan debajo de los lotes. --}}
                    @foreach ($plano['calles'] as $calle)
                        <polyline
                            points="{{ $calle['puntos'] }}"
                            fill="none"
                            stroke="#d4d4d8"
                            stroke-width="{{ $calle['ancho'] }}"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        >
                            <title>{{ $calle['etiqueta'] }}{{ $calle['nombre'] !== null ? ' — '.$calle['nombre'] : '' }}</title>
                        </polyline>
                    @endforeach

                    @foreach ($plano['lotes'] as $indice => $lote)
                        <polygon
                            points="{{ $lote['puntos'] }}"
                            fill="{{ $lote['color'] }}"
                            fill-opacity="0.78"
                            stroke-linejoin="round"
                            class="lote"
                            x-on:click="seleccionar({{ $indice }})"
                            :stroke="seleccionado?.id === {{ $lote['id'] }} ? '#0f172a' : '#ffffff'"
                            :stroke-width="seleccionado?.id === {{ $lote['id'] }} ? 0.9 : 0.3"
                        >
                            <title>{{ $lote['codigo'] }} — {{ $lote['etiqueta'] }}</title>
                        </polygon>
                    @endforeach

                    {{-- Los numeros al final, para que ningun poligono los tape. --}}
                    @foreach ($plano['lotes'] as $lote)
                        <text
                            x="{{ $lote['centro'][0] }}"
                            y="{{ $lote['centro'][1] }}"
                            text-anchor="middle"
                            dominant-baseline="central"
                            font-size="2.4"
                            font-weight="600"
                            fill="#ffffff"
                            style="pointer-events: none; user-select: none;"
                        >{{ $lote['numero'] }}</text>
                    @endforeach
                </svg>
            </div>

            <div class="plano-card">
                <template x-if="seleccionado === null">
                    <p class="plano-detalle-vacio">Hacé clic en un lote del plano para ver su información.</p>
                </template>

                <template x-if="seleccionado !== null">
                    <div>
                        <div class="plano-detalle-cabecera">
                            <div>
                                <p class="plano-detalle-codigo" x-text="seleccionado.codigo"></p>
                                <p class="plano-detalle-sub">Lote <span x-text="seleccionado.numero"></span></p>
                            </div>
                            <span
                                class="plano-badge"
                                :style="`background: ${seleccionado.color}`"
                                x-text="seleccionado.etiqueta"
                            ></span>
                        </div>

                        <dl class="plano-datos">
                            <div class="plano-dato">
                                <dt>Área</dt>
                                <dd><span x-text="seleccionado.areaVaras"></span> v²</dd>
                            </div>
                            <div class="plano-dato">
                                <dt>Valor</dt>
                                <dd x-text="seleccionado.valorFormateado"></dd>
                            </div>
                        </dl>

                        <template x-if="seleccionado.desalineado">
                            <p class="plano-aviso">
                                El dibujo de este lote no coincide con su área cargada. Manda el área del plano
                                legal — el polígono solo está avisando que alguien tiene que mirarlo.
                            </p>
                        </template>
                    </div>
                </template>
            </div>
        </div>
    @endif
</x-filament-panels::page>
