@use('App\Domain\Enums\EstadoLote')

<x-filament-panels::page>
    @php
        $resumen = $plano['resumen'];
        $tarjetas = [
            ['etiqueta' => 'Lotes',       'valor' => array_sum($resumen),    'color' => null],
            ['etiqueta' => 'Disponibles', 'valor' => $resumen['disponible'], 'color' => EstadoLote::Disponible->colorHex()],
            ['etiqueta' => 'Apartados',   'valor' => $resumen['apartado'],   'color' => EstadoLote::Apartado->colorHex()],
            ['etiqueta' => 'Vendidos',    'valor' => $resumen['vendido'],    'color' => EstadoLote::Vendido->colorHex()],
            ['etiqueta' => 'Cancelados',  'valor' => $resumen['cancelado'],  'color' => EstadoLote::Cancelado->colorHex()],
            ['etiqueta' => 'Sin dibujar', 'valor' => $plano['sinDibujar'],   'color' => null],
        ];
    @endphp

    {{--
        CSS propio y no clases de Tailwind: el panel sirve su bundle
        compilado y NO carga resources/css/app.css (no hay ->viteTheme() en
        AdminPanelProvider), asi que las utilidades que Filament no usa
        internamente no existirian y esto saldria sin estilos.
    --}}
    <style>
        .plano-stats { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .75rem; }
        @media (min-width: 640px) { .plano-stats { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
        @media (min-width: 1024px) { .plano-stats { grid-template-columns: repeat(6, minmax(0, 1fr)); } }

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

        .plano-esquema {
            margin-top: 1rem; border-radius: .75rem; padding: .875rem 1rem;
            background: rgb(255 251 235); border: 1px solid rgb(253 230 138);
            color: rgb(120 53 15); font-size: .8125rem; line-height: 1.6;
        }
        .dark .plano-esquema { background: rgba(251, 191, 36, .1); border-color: rgba(251, 191, 36, .3); color: rgb(253 230 138); }

        .plano-grid { display: grid; gap: 1rem; grid-template-columns: 1fr; margin-top: 1rem; }
        /* 3fr/1fr y no 2fr/1fr: el plano es el trabajo, el panel es la ficha. */
        @media (min-width: 1024px) { .plano-grid { grid-template-columns: 3fr 1fr; } }
        @media (min-width: 1536px) { .plano-grid { grid-template-columns: 4fr 1fr; } }

        .plano-lienzo { padding: 0; position: relative; overflow: hidden; }
        .plano-lienzo svg {
            display: block; width: 100%; height: min(82vh, 1100px);
            touch-action: none; cursor: grab;
            user-select: none; -webkit-user-select: none;
        }

        /* Pantalla completa: el plano tapa todo y la ficha del lote pasa a
           flotar arriba a la izquierda. Es CSS y no la Fullscreen API del
           navegador porque asi los modales de Filament —apartar, vender—
           siguen apareciendo encima y no detras. */
        .plano-completo { position: fixed; inset: 0; z-index: 50; display: grid;
            grid-template-columns: 1fr; gap: 0; margin: 0; padding: 0; background: #fff; }
        .dark .plano-completo { background: rgb(24 24 27); }
        .plano-completo .plano-lienzo { border-radius: 0; border: 0; height: 100vh; }
        .plano-completo .plano-lienzo svg { height: 100vh; }
        .plano-completo .plano-ficha {
            position: absolute; top: .75rem; left: .75rem; width: min(22rem, calc(100vw - 1.5rem));
            max-height: calc(100vh - 1.5rem); overflow-y: auto; z-index: 10;
            box-shadow: 0 10px 30px rgba(0, 0, 0, .18);
        }
        @media (min-width: 1024px) { .plano-completo { grid-template-columns: 1fr; } }
        @media (min-width: 1536px) { .plano-completo { grid-template-columns: 1fr; } }
        .plano-lienzo svg.arrastrando { cursor: grabbing; }

        .plano-controles { position: absolute; top: .625rem; right: .625rem; display: flex; gap: .25rem; }
        .plano-boton {
            border: 1px solid rgb(228 228 231); background: rgba(255, 255, 255, .9);
            color: rgb(63 63 70); border-radius: .5rem; padding: .3125rem .625rem;
            font-size: .75rem; font-weight: 600; line-height: 1.2; cursor: pointer;
            backdrop-filter: blur(4px);
        }
        .plano-boton:hover { background: #fff; color: rgb(9 9 11); }
        .plano-boton.activo { border-color: rgb(161 161 170); background: rgb(39 39 42); color: #fff; }
        .dark .plano-boton { border-color: rgba(255, 255, 255, .15); background: rgba(24, 24, 27, .85); color: rgb(212 212 216); }
        .dark .plano-boton:hover { background: rgb(24 24 27); color: #fff; }
        .dark .plano-boton.activo { background: rgb(244 244 245); color: rgb(24 24 27); border-color: rgb(244 244 245); }

        /* El calco hereda currentColor: tinta oscura en claro, clara en
           oscuro. Sin esto el dibujo del topografo desaparece en el tema
           que no le toca. */
        .plano-calco { color: rgb(24 24 27); }
        .dark .plano-calco { color: rgb(228 228 231); }

        .plano-pista {
            position: absolute; left: .75rem; bottom: .625rem;
            font-size: .6875rem; color: rgb(161 161 170); pointer-events: none;
        }

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

        .plano-acciones { margin-top: 1rem; }
        .plano-botonera { display: flex; gap: .5rem; flex-wrap: wrap; }
        .plano-accion {
            flex: 1 1 auto; border: 1px solid rgb(228 228 231); background: #fff;
            color: rgb(63 63 70); border-radius: .5rem; padding: .5rem .75rem;
            font-size: .8125rem; font-weight: 600; cursor: pointer;
        }
        .plano-accion:hover { background: rgb(250 250 250); }
        .dark .plano-accion { border-color: rgba(255, 255, 255, .15); background: rgba(255, 255, 255, .05); color: rgb(228 228 231); }
        .dark .plano-accion:hover { background: rgba(255, 255, 255, .1); }
        .plano-accion-apartar { border-color: #d97706; color: #b45309; }
        .dark .plano-accion-apartar { border-color: rgba(217, 119, 6, .5); color: #fbbf24; }
        .plano-accion-vender { border-color: #2563eb; color: #1d4ed8; }
        .dark .plano-accion-vender { border-color: rgba(37, 99, 235, .5); color: #93c5fd; }

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

    @if ($plano['esquematico'] && $plano['hayGeometria'])
        <div class="plano-esquema">
            <strong>Este plano es un esquema, no el plano del topógrafo.</strong>
            Cada lote está dibujado con su área real, pero su posición es aproximada: salen en fila,
            en el orden de su código. No lo uses para mostrarle a un cliente dónde queda su lote.
        </div>
    @endif

    @if (! $plano['hayGeometria'])
        <div class="plano-vacio">
            <p class="plano-vacio-titulo">Todavía no hay nada dibujado en este proyecto.</p>
            <p class="plano-vacio-texto">
                Los {{ $plano['sinDibujar'] }} lotes cargados existen y se venden igual — el plano es una capa
                encima del negocio, no un requisito. Para dibujarlos, usá el botón «Acomodar plano» de arriba.
            </p>
        </div>
    @else
        <div
            x-data="{
                lotes: @js($plano['lotes']),
                base: @js($plano['viewBox']).split(' ').map(Number),
                vista: { x: 0, y: 0, w: 1, h: 1 },
                seleccionado: null,
                arrastrando: false,
                movio: false,
                inicio: { x: 0, y: 0, vx: 0, vy: 0 },

                /* Calco del plano original. Se pide aparte y no embebido en
                   la pagina porque pesa ~1.5 MB: asi lo cachea el navegador
                   y no viaja en cada render de Livewire. Si falla, no pasa
                   nada: los lotes se dibujan igual. */
                calco: { obra: '', rotulo: '', textos: [] },
                verCalco: true,
                completo: false,

                init() {
                    this.ajustar();

                    const url = @js($plano['calco']);

                    if (url) {
                        fetch(url)
                            .then((r) => r.ok ? r.json() : null)
                            .then((d) => { if (d) this.calco = d })
                            .catch(() => {});
                    }
                },

                /* El viewBox se escribe con setAttribute y no con :viewBox
                   porque el parser de HTML pasa los atributos a minusculas,
                   y en SVG 'viewbox' no es lo mismo que 'viewBox'. */
                get viewBox() {
                    return `${this.vista.x} ${this.vista.y} ${this.vista.w} ${this.vista.h}`;
                },

                get proporcion() { return this.base[3] / this.base[2] },

                ajustar() {
                    this.vista = { x: this.base[0], y: this.base[1], w: this.base[2], h: this.base[3] };
                },

                acercar(factor, clienteX = null, clienteY = null) {
                    const caja = this.$refs.lienzo.getBoundingClientRect();

                    /* Sin cursor (botones) se hace zoom al centro de lo que
                       se esta viendo, no al centro del proyecto. */
                    const px = clienteX === null
                        ? this.vista.x + this.vista.w / 2
                        : this.vista.x + ((clienteX - caja.left) / caja.width) * this.vista.w;
                    const py = clienteY === null
                        ? this.vista.y + this.vista.h / 2
                        : this.vista.y + ((clienteY - caja.top) / caja.height) * this.vista.h;

                    const minimo = this.base[2] / 60;
                    const maximo = this.base[2] * 2.5;
                    const ancho = Math.min(Math.max(this.vista.w / factor, minimo), maximo);
                    const real = this.vista.w / ancho;

                    this.vista = {
                        x: px - (px - this.vista.x) / real,
                        y: py - (py - this.vista.y) / real,
                        w: ancho,
                        h: ancho * this.proporcion,
                    };
                },

                alRodar(e) { this.acercar(e.deltaY < 0 ? 1.18 : 1 / 1.18, e.clientX, e.clientY) },

                alPresionar(e) {
                    this.arrastrando = true;
                    this.movio = false;
                    this.inicio = { x: e.clientX, y: e.clientY, vx: this.vista.x, vy: this.vista.y };
                    this.$refs.lienzo.setPointerCapture(e.pointerId);
                },

                alMover(e) {
                    if (! this.arrastrando) return;

                    const caja = this.$refs.lienzo.getBoundingClientRect();
                    const dx = e.clientX - this.inicio.x;
                    const dy = e.clientY - this.inicio.y;

                    /* Umbral de 3px: sin esto, el temblor de la mano al
                       hacer clic contaria como arrastre y el lote nunca se
                       seleccionaria. */
                    if (Math.abs(dx) > 3 || Math.abs(dy) > 3) this.movio = true;

                    this.vista = {
                        ...this.vista,
                        x: this.inicio.vx - dx * (this.vista.w / caja.width),
                        y: this.inicio.vy - dy * (this.vista.h / caja.height),
                    };
                },

                /*
                   La seleccion se resuelve al SOLTAR y no con un click.
                   Motivo: cancelar el pointerdown —que hace falta para que
                   arrastrar no seleccione texto ni arrastre la imagen—
                   suprime los eventos de compatibilidad del mouse, y el
                   click es uno de ellos: no llegaba nunca al poligono.

                   Al soltar se pregunta que elemento hay bajo el cursor.
                   Los numeros tienen pointer-events: none, asi que lo que
                   contesta es siempre el poligono o el fondo.
                */
                alSoltar(e) {
                    this.arrastrando = false;

                    if (e && this.$refs.lienzo.hasPointerCapture(e.pointerId)) {
                        this.$refs.lienzo.releasePointerCapture(e.pointerId);
                    }

                    // Un gesto cancelado —el navegador se lleva el puntero, o
                    // entra una llamada— no es un clic y no debe seleccionar.
                    if (this.movio || e.type !== 'pointerup') { this.movio = false; return }

                    const debajo = document.elementFromPoint(e.clientX, e.clientY);
                    const indice = debajo?.dataset?.indice;

                    this.seleccionar(indice === undefined ? null : Number(indice));
                },

                seleccionar(indice) {
                    // Soltar sobre el fondo deselecciona.
                    if (indice === null || Number.isNaN(indice) || ! this.lotes[indice]) {
                        this.seleccionado = null;

                        return;
                    }

                    const lote = this.lotes[indice];
                    this.seleccionado = this.seleccionado?.id === lote.id ? null : lote;
                },
            }"
            :class="completo ? 'plano-grid plano-completo' : 'plano-grid'"
        >
            <div class="plano-card plano-lienzo">
                <svg
                    x-ref="lienzo"
                    viewBox="{{ $plano['viewBox'] }}"
                    preserveAspectRatio="xMidYMid meet"
                    x-effect="$el.setAttribute('viewBox', viewBox)"
                    :class="arrastrando ? 'arrastrando' : ''"
                    x-on:keydown.escape.window="completo = false"
                    x-on:wheel.prevent="alRodar($event)"
                    x-on:pointerdown.prevent="alPresionar($event)"
                    x-on:pointermove="alMover($event)"
                    x-on:pointerup="alSoltar($event)"
                    x-on:pointercancel="alSoltar($event)"
                >
                    {{-- Las calles van primero: quedan debajo de los lotes. --}}
                    @foreach ($plano['calles'] as $calle)
                        @php
                            $tituloCalle = $calle['etiqueta'].($calle['nombre'] !== null ? ' — '.$calle['nombre'] : '');
                        @endphp

                        @if ($calle['esArea'])
                            {{-- Importada de un plano: la calle es su area. --}}
                            <polygon points="{{ $calle['puntos'] }}" fill="#d4d4d8" stroke="none">
                                <title>{{ $tituloCalle }}</title>
                            </polygon>
                        @else
                            {{-- Dibujada a mano: un eje pintado grueso. --}}
                            <polyline
                                points="{{ $calle['puntos'] }}"
                                fill="none"
                                stroke="#d4d4d8"
                                stroke-width="{{ $calle['ancho'] }}"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                            >
                                <title>{{ $tituloCalle }}</title>
                            </polyline>
                        @endif
                    @endforeach

                    {{-- non-scaling-stroke: el borde se mide en pixeles de
                         pantalla, asi que al acercarse no se convierte en
                         una franja gruesa que se come el lote. --}}
                    @foreach ($plano['lotes'] as $indice => $lote)
                        <polygon
                            points="{{ $lote['puntos'] }}"
                            fill="{{ $lote['color'] }}"
                            fill-opacity="0.78"
                            stroke-linejoin="round"
                            vector-effect="non-scaling-stroke"
                            class="lote"
                            data-indice="{{ $indice }}"
                            :stroke="seleccionado?.id === {{ $lote['id'] }} ? '#0f172a' : '#ffffff'"
                            :stroke-width="seleccionado?.id === {{ $lote['id'] }} ? 2.5 : 1"
                        >
                            <title>{{ $lote['codigo'] }} — {{ $lote['etiqueta'] }}</title>
                        </polygon>
                    @endforeach

                    {{-- El calco del plano del topografo, ENCIMA del color.

                         Va arriba y no abajo a proposito: asi se leen sus
                         cotas y sus numeros sobre el lote pintado, que es
                         como se lee un plano de ventas. El color queda de
                         tinte. pointer-events none: lo que se clickea son
                         los poligonos de la base, no el dibujo. --}}
                    <g class="plano-calco" x-show="verCalco" style="pointer-events: none;">
                        {{-- Los rotulos del plano que NO son de lote: nombres de
                             calle, areas verdes, el norte. Van como texto de
                             verdad porque el DXF nativo los trae como texto. --}}
                        <template x-for="t in calco.textos" :key="t.x + ',' + t.y + t.t">
                            <text
                                :x="t.x"
                                :y="t.y"
                                :font-size="t.h * 1.15"
                                :transform="`rotate(${t.r} ${t.x} ${t.y})`"
                                fill="currentColor"
                                fill-opacity="0.75"
                                x-text="t.t"
                            ></text>
                        </template>
                        <path
                            :d="calco.obra"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.3"
                            vector-effect="non-scaling-stroke"
                        />
                    </g>

                    {{-- Los rotulos al final, para que ningun poligono los tape.

                         Van con la letra del bloque pegada —12B— porque en
                         un plano de 24 manzanas un "12" solo no dice de cual
                         es, y el codigo entero (RPS-B-012) no entra.

                         Con el calco encendido se ocultan: el dibujo del
                         topografo ya trae escritos el numero y el area de
                         cada lote, y encimarle los nuestros deja el mapa
                         ilegible. --}}
                    <g x-show="!verCalco">
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
                            >{{ $lote['rotulo'] }}</text>
                        @endforeach
                    </g>
                </svg>

                <div class="plano-controles">
                    <button type="button" class="plano-boton" x-on:click="acercar(1.4)" title="Acercar">+</button>
                    <button type="button" class="plano-boton" x-on:click="acercar(1 / 1.4)" title="Alejar">−</button>
                    <button type="button" class="plano-boton" x-on:click="ajustar()" title="Ver el proyecto completo">Ajustar</button>
                    <button
                        type="button"
                        class="plano-boton"
                        x-on:click="completo = ! completo; $nextTick(() => ajustar())"
                        :class="completo ? 'activo' : ''"
                        :title="completo ? 'Salir de pantalla completa (Esc)' : 'Pantalla completa'"
                        x-text="completo ? 'Reducir' : 'Ampliar'"
                    ></button>
                    @if ($plano['calco'] !== null)
                        <button
                            type="button"
                            class="plano-boton"
                            x-on:click="verCalco = !verCalco"
                            :class="verCalco ? 'activo' : ''"
                            title="Mostrar u ocultar el dibujo del plano original"
                            x-text="verCalco ? 'Plano' : 'Lotes'"
                        ></button>
                    @endif
                </div>

                <div class="plano-pista" x-text="completo
                    ? 'Rueda para acercar · arrastrá para moverte · clic en un lote · Esc para salir'
                    : 'Rueda para acercar · arrastrá para moverte · clic en un lote · «Ampliar» para pantalla completa'"></div>
            </div>

            <div class="plano-card plano-ficha">
                <template x-if="seleccionado === null">
                    <p class="plano-detalle-vacio">Hacé clic en un lote del plano para ver su información.</p>
                </template>

                <template x-if="seleccionado !== null">
                    <div>
                        <div class="plano-detalle-cabecera">
                            <div>
                                <p class="plano-detalle-codigo" x-text="seleccionado.codigo"></p>
                                <p class="plano-detalle-sub">Lote <span x-text="seleccionado.rotulo"></span></p>
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
                            <template x-if="seleccionado.cliente">
                                <div class="plano-dato">
                                    <dt>Cliente</dt>
                                    <dd x-text="seleccionado.cliente"></dd>
                                </div>
                            </template>
                        </dl>

                        {{--
                            Los botones montan las acciones de Filament por
                            nombre. El estado del lote decide cuales tienen
                            sentido: no se aparta lo que ya esta apartado ni
                            se libera una venta.
                        --}}
                        <div class="plano-acciones">
                            <template x-if="seleccionado.estado === 'disponible'">
                                <div class="plano-botonera">
                                    <button type="button" class="plano-accion plano-accion-apartar"
                                        x-on:click="$wire.mountAction('apartarLote', { lote: seleccionado.id })">
                                        Apartar
                                    </button>
                                    <button type="button" class="plano-accion plano-accion-vender"
                                        x-on:click="$wire.mountAction('venderLote', { lote: seleccionado.id })">
                                        Vender
                                    </button>
                                </div>
                            </template>

                            <template x-if="seleccionado.estado === 'apartado'">
                                <div class="plano-botonera">
                                    <button type="button" class="plano-accion plano-accion-vender"
                                        x-on:click="$wire.mountAction('venderLote', { lote: seleccionado.id })">
                                        Convertir en venta
                                    </button>
                                    <button type="button" class="plano-accion"
                                        x-on:click="$wire.mountAction('liberarLote', { lote: seleccionado.id })">
                                        Liberar
                                    </button>
                                </div>
                            </template>

                            <template x-if="seleccionado.estado === 'vendido'">
                                <p class="plano-detalle-vacio">
                                    Lote vendido. Deshacer una venta es una rescisión y ese trámite
                                    todavía no está en el sistema.
                                </p>
                            </template>
                        </div>

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
