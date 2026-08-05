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

        /*
        | Los planes de financiamiento salen de config y NO del plano: son
        | del negocio, no de la geometría. Mientras la lista de precios por
        | plazo no esté cargada esto viene vacío y el modal lo dice, en vez
        | de inventar una cuota que un vendedor podría cotizarle a alguien.
        */
        $planes = array_values(array_map(
            static fn (array $plan): array => [
                'meses'      => (int) $plan['meses'],
                'precioVara' => (string) $plan['precio_vara'],
            ],
            config('lotificadora.financiamiento.planes', []),
        ));
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

        /* Una sola columna: la ficha lateral se fue al modal (5-ago-2026),
           asi que el plano se queda con TODO el ancho de la pantalla. */
        .plano-grid { margin-top: 1rem; }

        .plano-lienzo { padding: 0; position: relative; overflow: hidden; }
        .plano-lienzo svg {
            display: block; width: 100%; height: min(82vh, 1100px);
            touch-action: none; cursor: grab;
            user-select: none; -webkit-user-select: none;
        }

        /* Pantalla completa: el plano tapa todo. Es CSS y no la Fullscreen
           API del navegador porque asi los modales de Filament —apartar,
           vender— siguen apareciendo encima y no detras. */
        .plano-completo { position: fixed; inset: 0; z-index: 50; margin: 0; padding: 0; background: #fff; }
        .dark .plano-completo { background: rgb(24 24 27); }
        .plano-completo .plano-lienzo { border-radius: 0; border: 0; height: 100vh; }
        .plano-completo .plano-lienzo svg { height: 100vh; }
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

        /* ── El modal del lote ────────────────────────────────────────────
           z-index 50, el mismo tope que usa Filament. No es un empate al
           azar: el modal de Filament se monta al final del <body>, o sea
           DESPUES de este en el orden del documento, y con igual z-index
           gana el ultimo. Asi apartar y vender siguen saliendo encima.
           Aparte, al montar una accion este se cierra solo. */
        .plano-modal {
            position: fixed; inset: 0; z-index: 50; display: flex;
            align-items: center; justify-content: center; padding: 1rem;
            background: rgba(9, 9, 11, .55); backdrop-filter: blur(2px);
        }
        .plano-modal-caja {
            width: min(66rem, 100%); max-height: min(92vh, 54rem);
            display: flex; flex-direction: column; overflow: hidden;
            background: #fff; border-radius: 1rem;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, .35);
        }
        .dark .plano-modal-caja { background: rgb(24 24 27); }

        .plano-modal-cabecera {
            display: flex; align-items: flex-start; justify-content: space-between;
            gap: 1rem; padding: 1rem 1.25rem;
            border-bottom: 1px solid rgb(244 244 245);
        }
        .dark .plano-modal-cabecera { border-bottom-color: rgba(255, 255, 255, .1); }
        .plano-modal-codigo { font-size: 1.25rem; font-weight: 600; color: rgb(9 9 11); }
        .dark .plano-modal-codigo { color: #fff; }
        .plano-modal-sub { margin-top: .125rem; font-size: .8125rem; color: rgb(113 113 122); }
        .dark .plano-modal-sub { color: rgb(161 161 170); }
        .plano-modal-cerrar {
            border: 0; background: transparent; cursor: pointer; line-height: 1;
            font-size: 1.5rem; padding: 0 .25rem; color: rgb(113 113 122);
        }
        .plano-modal-cerrar:hover { color: rgb(9 9 11); }
        .dark .plano-modal-cerrar:hover { color: #fff; }

        .plano-modal-cuerpo {
            display: grid; grid-template-columns: 1fr; gap: 1.25rem;
            padding: 1.25rem; overflow-y: auto;
        }
        @media (min-width: 900px) { .plano-modal-cuerpo { grid-template-columns: 1.2fr 1fr; } }

        .plano-modal-dibujo {
            display: block; width: 100%; height: min(48vh, 24rem);
            background: rgb(250 250 250); border: 1px solid rgb(244 244 245);
            border-radius: .75rem;
        }
        .dark .plano-modal-dibujo { background: rgba(255, 255, 255, .04); border-color: rgba(255, 255, 255, .1); }
        .plano-modal-escala { margin-top: .5rem; font-size: .6875rem; color: rgb(161 161 170); text-align: center; }

        .plano-badge { flex-shrink: 0; border-radius: .375rem; padding: .25rem .5rem; font-size: .75rem; font-weight: 500; color: #fff; }

        .plano-datos { font-size: .875rem; }
        .plano-dato { display: flex; align-items: baseline; justify-content: space-between; gap: 1rem; padding: .3125rem 0; border-bottom: 1px solid rgb(250 250 250); }
        .dark .plano-dato { border-bottom-color: rgba(255, 255, 255, .06); }
        .plano-dato dt { color: rgb(113 113 122); }
        .dark .plano-dato dt { color: rgb(161 161 170); }
        .plano-dato dd { font-variant-numeric: tabular-nums; color: rgb(9 9 11); margin: 0; font-weight: 500; }
        .dark .plano-dato dd { color: #fff; }
        .plano-dato-fuerte dd { font-size: 1.125rem; font-weight: 600; }

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

        .plano-detalle-vacio { font-size: .875rem; color: rgb(113 113 122); }
        .dark .plano-detalle-vacio { color: rgb(161 161 170); }

        .plano-aviso {
            margin-top: 1rem; border-radius: .5rem; padding: .75rem;
            background: rgb(255 251 235); color: rgb(120 53 15);
            font-size: .75rem; line-height: 1.6;
        }
        .dark .plano-aviso { background: rgba(251, 191, 36, .1); color: rgb(253 230 138); }

        .plano-planes { margin-top: 1.25rem; border-top: 1px solid rgb(244 244 245); padding-top: 1rem; }
        .dark .plano-planes { border-top-color: rgba(255, 255, 255, .1); }
        .plano-planes-titulo { font-size: .8125rem; font-weight: 600; color: rgb(9 9 11); }
        .dark .plano-planes-titulo { color: #fff; }
        .plano-prima { display: flex; align-items: center; gap: .5rem; margin: .625rem 0; font-size: .8125rem; color: rgb(113 113 122); }
        .plano-prima input {
            width: 9rem; border: 1px solid rgb(228 228 231); border-radius: .5rem;
            padding: .3125rem .5rem; font-size: .8125rem; text-align: right;
            font-variant-numeric: tabular-nums; background: #fff; color: rgb(9 9 11);
        }
        .dark .plano-prima input { border-color: rgba(255, 255, 255, .15); background: rgba(255, 255, 255, .05); color: #fff; }
        .plano-tabla { width: 100%; border-collapse: collapse; font-size: .8125rem; font-variant-numeric: tabular-nums; }
        .plano-tabla th {
            text-align: right; font-weight: 500; color: rgb(113 113 122);
            padding: .375rem .5rem; border-bottom: 1px solid rgb(228 228 231);
        }
        .dark .plano-tabla th { color: rgb(161 161 170); border-bottom-color: rgba(255, 255, 255, .1); }
        .plano-tabla th:first-child, .plano-tabla td:first-child { text-align: left; }
        .plano-tabla td { padding: .375rem .5rem; border-bottom: 1px solid rgb(250 250 250); text-align: right; color: rgb(9 9 11); }
        .dark .plano-tabla td { border-bottom-color: rgba(255, 255, 255, .06); color: rgb(228 228 231); }
        .plano-tabla td.cuota { font-weight: 600; }
        .plano-planes-nota { margin-top: .5rem; font-size: .6875rem; line-height: 1.6; color: rgb(161 161 170); }
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
                planes: @js($planes),
                base: @js($plano['viewBox']).split(' ').map(Number),
                vista: { x: 0, y: 0, w: 1, h: 1 },
                seleccionado: null,
                abierto: false,
                prima: '',
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

                    this.seleccionar(indice === undefined ? null : Number(indice), debajo);
                },

                /*
                   El estado, el color y el cliente NO se leen del arreglo
                   `lotes`: se leen de los data-* del poligono.

                   Motivo: `lotes` se serializo una sola vez, cuando se
                   pinto la pagina, y Alpine no vuelve a evaluar x-data
                   cuando Livewire re-renderiza. Los atributos del poligono
                   si los actualiza el morph. Sin esto, apartar un lote y
                   volver a abrirlo lo seguiria mostrando disponible.
                */
                seleccionar(indice, elemento = null) {
                    // Soltar sobre el fondo cierra y deselecciona.
                    if (indice === null || Number.isNaN(indice) || ! this.lotes[indice]) {
                        this.seleccionado = null;
                        this.abierto = false;

                        return;
                    }

                    const datos = elemento?.dataset ?? {};

                    this.seleccionado = {
                        ...this.lotes[indice],
                        estado: datos.estado ?? this.lotes[indice].estado,
                        etiqueta: datos.etiqueta ?? this.lotes[indice].etiqueta,
                        color: datos.color ?? this.lotes[indice].color,
                        cliente: datos.cliente ? datos.cliente : null,
                    };

                    this.prima = '';
                    this.abierto = true;
                },

                cerrar() { this.abierto = false },

                /*
                   La geometria del lote para dibujarlo solo, en grande.

                   Las coordenadas ya vienen en VARAS y sin transformar
                   (ver PlanoDelProyecto: el sistema es el de SVG y no se
                   invierte ningun eje), asi que el largo de cada lado es
                   directamente la medida del lote. No hay que escalar nada.
                */
                get figura() {
                    const lote = this.seleccionado;

                    if (! lote || ! lote.puntos) return null;

                    const puntos = String(lote.puntos).trim().split(/\s+/)
                        .map((par) => par.split(',').map(Number))
                        .filter((p) => p.length === 2 && Number.isFinite(p[0]) && Number.isFinite(p[1]));

                    if (puntos.length < 3) return null;

                    const xs = puntos.map((p) => p[0]);
                    const ys = puntos.map((p) => p[1]);
                    const minX = Math.min(...xs);
                    const maxX = Math.max(...xs);
                    const minY = Math.min(...ys);
                    const maxY = Math.max(...ys);
                    const cx = xs.reduce((a, b) => a + b, 0) / xs.length;
                    const cy = ys.reduce((a, b) => a + b, 0) / ys.length;

                    const diagonal = Math.hypot(maxX - minX, maxY - minY) || 1;
                    const separacion = diagonal * 0.055;
                    const fuente = diagonal * 0.05;

                    const lados = [];

                    for (let i = 0; i < puntos.length; i++) {
                        const a = puntos[i];
                        const b = puntos[(i + 1) % puntos.length];
                        const dx = b[0] - a[0];
                        const dy = b[1] - a[1];
                        const largo = Math.hypot(dx, dy);

                        // Un lado de menos de 2% de la diagonal es ruido del
                        // trazado, no una medida que alguien vaya a cotar.
                        if (largo < diagonal * 0.02) continue;

                        const mx = (a[0] + b[0]) / 2;
                        const my = (a[1] + b[1]) / 2;

                        // Normal unitaria, girada hacia AFUERA: si apunta
                        // hacia el centro del lote se invierte.
                        let nx = -dy / largo;
                        let ny = dx / largo;

                        if ((mx - cx) * nx + (my - cy) * ny < 0) { nx = -nx; ny = -ny }

                        // El texto se lee siempre de izquierda a derecha:
                        // pasados los 90 grados se voltea media vuelta.
                        let angulo = Math.atan2(dy, dx) * 180 / Math.PI;

                        if (angulo > 90) angulo -= 180;
                        if (angulo < -90) angulo += 180;

                        lados.push({ a, b, mx, my, nx, ny, largo, angulo, extra: 0 });
                    }

                    /*
                       Dos linderos cortos y seguidos —esquinas recortadas,
                       lotes en cuña— dejaban sus cotas una encima de la
                       otra y no se leia ninguna.

                       El choque NO se mide de centro a centro: «12.59 V» es
                       cinco veces mas ancho que alto, y dos textos con los
                       centros lejos igual se cruzan. Se muestrea cada
                       etiqueta a lo largo de su propia linea de texto —que
                       va girada con el lado— y se compara punto contra
                       punto. Al chocar se empuja hacia afuera la del lado
                       MAS CORTO, que es la que menos lugar propio tiene.
                    */
                    const muestrasDe = (lado) => {
                        const distancia = separacion + fuente * 0.85 + lado.extra;
                        const x = lado.mx + lado.nx * distancia;
                        const y = lado.my + lado.ny * distancia;

                        // 0.55 em por caracter: el ancho tipico de un digito
                        // en las fuentes de palo seco que usa el panel.
                        const ancho = (lado.largo.toFixed(2).length + 2) * fuente * 0.55;
                        const radianes = lado.angulo * Math.PI / 180;
                        const ux = Math.cos(radianes);
                        const uy = Math.sin(radianes);
                        const puntos = [];

                        for (let k = -2; k <= 2; k++) {
                            puntos.push([x + ux * ancho * k / 4, y + uy * ancho * k / 4]);
                        }

                        return puntos;
                    };

                    const tope = fuente * 8;

                    for (let paso = 0; paso < 12; paso++) {
                        let choco = false;

                        for (let i = 0; i < lados.length; i++) {
                            for (let j = i + 1; j < lados.length; j++) {
                                const unos = muestrasDe(lados[i]);
                                const otros = muestrasDe(lados[j]);
                                let cerca = Infinity;

                                for (const p of unos) {
                                    for (const q of otros) {
                                        cerca = Math.min(cerca, Math.hypot(p[0] - q[0], p[1] - q[1]));
                                    }
                                }

                                const minima = fuente * 1.15;

                                if (cerca >= minima) continue;

                                const corto = lados[i].largo <= lados[j].largo ? lados[i] : lados[j];

                                if (corto.extra >= tope) continue;

                                corto.extra += Math.max(minima - cerca, fuente * 0.25);
                                choco = true;
                            }
                        }

                        if (! choco) break;
                    }

                    const empujado = Math.max(0, ...lados.map((l) => l.extra));
                    const margen = separacion + fuente * 2.6 + empujado;

                    return {
                        lados,
                        cx,
                        cy,
                        fuente,
                        separacion,
                        viewBox: `${minX - margen} ${minY - margen} ${maxX - minX + margen * 2} ${maxY - minY + margen * 2}`,
                    };
                },

                /*
                   Las cotas se construyen con createElementNS y no con un
                   x-for. Es a proposito: <template> dentro de <svg> no es
                   un template de verdad —el namespace SVG no define
                   .content— y Alpine lo ignora en silencio. Ya paso una vez
                   con los rotulos del calco.
                */
                dibujarCotas(g) {
                    const NS = 'http://www.w3.org/2000/svg';
                    const figura = this.figura;

                    while (g.firstChild) g.removeChild(g.firstChild);

                    if (! figura) return;

                    for (const lado of figura.lados) {
                        // `extra` mueve la cota ENTERA —linea, patitas y
                        // texto— para que el numero nunca quede flotando
                        // lejos de la linea que esta acotando.
                        const sep = figura.separacion + lado.extra;

                        const linea = document.createElementNS(NS, 'line');
                        linea.setAttribute('x1', lado.a[0] + lado.nx * sep);
                        linea.setAttribute('y1', lado.a[1] + lado.ny * sep);
                        linea.setAttribute('x2', lado.b[0] + lado.nx * sep);
                        linea.setAttribute('y2', lado.b[1] + lado.ny * sep);
                        linea.setAttribute('stroke', '#dc2626');
                        linea.setAttribute('stroke-width', '1.2');
                        linea.setAttribute('vector-effect', 'non-scaling-stroke');
                        g.appendChild(linea);

                        // Las dos patitas que amarran la cota al vertice.
                        for (const punta of [lado.a, lado.b]) {
                            const pata = document.createElementNS(NS, 'line');
                            pata.setAttribute('x1', punta[0]);
                            pata.setAttribute('y1', punta[1]);
                            pata.setAttribute('x2', punta[0] + lado.nx * sep * 1.25);
                            pata.setAttribute('y2', punta[1] + lado.ny * sep * 1.25);
                            pata.setAttribute('stroke', '#fca5a5');
                            pata.setAttribute('stroke-width', '1');
                            pata.setAttribute('vector-effect', 'non-scaling-stroke');
                            g.appendChild(pata);
                        }

                        const tx = lado.mx + lado.nx * (sep + figura.fuente * 0.85 + lado.extra);
                        const ty = lado.my + lado.ny * (sep + figura.fuente * 0.85 + lado.extra);

                        const texto = document.createElementNS(NS, 'text');
                        texto.setAttribute('x', tx);
                        texto.setAttribute('y', ty);
                        texto.setAttribute('text-anchor', 'middle');
                        texto.setAttribute('dominant-baseline', 'central');
                        texto.setAttribute('font-size', figura.fuente);
                        texto.setAttribute('font-weight', '600');
                        texto.setAttribute('fill', '#b91c1c');
                        texto.setAttribute('transform', `rotate(${lado.angulo} ${tx} ${ty})`);
                        texto.textContent = `${lado.largo.toFixed(2)} V`;
                        g.appendChild(texto);
                    }
                },

                /*
                   El cuadro de plazos. Se calcula EN EL MOMENTO y con
                   centavos enteros, no con decimales de punto flotante.

                   Es un estimado para cotizar de pie frente al cliente: el
                   plan que se firma lo arma PlanDeCuotas del lado del
                   servidor, que es el que reparte el residuo del redondeo
                   en la ultima cuota.
                */
                get planesCalculados() {
                    const lote = this.seleccionado;

                    if (! lote || this.planes.length === 0) return [];

                    const area = Number(String(lote.areaVaras).replace(/,/g, ''));

                    if (! Number.isFinite(area) || area <= 0) return [];

                    const prima = Math.max(0, Math.round((Number(this.prima) || 0) * 100));

                    return this.planes.map((plan) => {
                        const total = Math.round(area * Number(plan.precioVara) * 100);
                        const saldo = Math.max(total - prima, 0);

                        return {
                            meses: plan.meses,
                            etiqueta: plan.meses > 0 ? `${plan.meses} meses` : 'Contado',
                            precioVara: this.lempiras(Math.round(Number(plan.precioVara) * 100)),
                            total: this.lempiras(total),
                            cuota: plan.meses > 0 ? this.lempiras(Math.round(saldo / plan.meses)) : '—',
                        };
                    });
                },

                /* Dos decimales y separador de miles, para todo lo que se
                   mira: el area viene de la base con cuatro decimales
                   (1200.5700) y asi no se le enseña a nadie. */
                numero(valor, decimales = 2) {
                    const partido = Number(valor).toFixed(decimales).split('.');
                    const miles = partido[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');

                    return partido.length > 1 ? `${miles}.${partido[1]}` : miles;
                },

                lempiras(centavos) { return `L ${this.numero(centavos / 100)}` },

                get areaFormateada() {
                    const crudo = String(this.seleccionado?.areaVaras ?? '').replace(/,/g, '');
                    const area = Number(crudo);

                    return Number.isFinite(area) ? this.numero(area) : crudo;
                },
            }"
            :class="completo ? 'plano-grid plano-completo' : 'plano-grid'"
            x-on:keydown.escape.window="if (abierto) { cerrar() } else { completo = false }"
        >
            <div class="plano-card plano-lienzo">
                <svg
                    x-ref="lienzo"
                    viewBox="{{ $plano['viewBox'] }}"
                    preserveAspectRatio="xMidYMid meet"
                    x-effect="$el.setAttribute('viewBox', viewBox)"
                    :class="arrastrando ? 'arrastrando' : ''"
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
                         una franja gruesa que se come el lote.

                         Los data-* son la fuente FRESCA del estado: los
                         escribe el servidor y los actualiza el morph de
                         Livewire despues de apartar, vender o liberar. Ver
                         el comentario de seleccionar(). --}}
                    @foreach ($plano['lotes'] as $indice => $lote)
                        <polygon
                            points="{{ $lote['puntos'] }}"
                            fill="{{ $lote['color'] }}"
                            fill-opacity="0.78"
                            stroke-linejoin="round"
                            vector-effect="non-scaling-stroke"
                            class="lote"
                            data-indice="{{ $indice }}"
                            data-estado="{{ $lote['estado'] }}"
                            data-etiqueta="{{ $lote['etiqueta'] }}"
                            data-color="{{ $lote['color'] }}"
                            data-cliente="{{ $lote['cliente'] }}"
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
                        {{-- Los rotulos del topografo —CALLE PUBLICA, BLOQUE X,
                             las areas verdes— NO se dibujan (5-ago-2026).

                             Vienen en el JSON del calco y estuvieron rotos
                             desde el principio por un <template x-for> dentro
                             del <svg>, que en contexto SVG no es un template
                             de verdad. Al arreglarlo se vieron por primera
                             vez, y la conclusion fue que estorban: se
                             amontonan sobre los lotes, compiten con nuestros
                             propios numeros y no dicen nada que la persona no
                             sepa mirando el dibujo.

                             El dato sigue en el JSON (`calco.textos`) por si
                             algun dia se quieren, por ejemplo solo a partir
                             de cierto acercamiento. Lo que se dibuja es el
                             TRAZO del topografo, que es lo que sirve de
                             referencia. --}}
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

            {{--
                La ficha del lote, en modal.

                Antes era un panel fijo al costado que se comia un cuarto de
                pantalla estuviera vacio o no. Ahora el plano usa el ancho
                completo y el lote se abre encima, con su dibujo en grande y
                las medidas de cada lindero — que es como se le enseña un
                lote a un cliente.
            --}}
            <template x-if="abierto && seleccionado !== null">
                <div class="plano-modal" x-on:click.self="cerrar()">
                    <div class="plano-modal-caja" x-transition>
                        <div class="plano-modal-cabecera">
                            <div>
                                <p class="plano-modal-codigo" x-text="seleccionado.codigo"></p>
                                <p class="plano-modal-sub">
                                    Bloque <span x-text="seleccionado.bloque"></span> ·
                                    lote <span x-text="seleccionado.numero"></span>
                                </p>
                            </div>

                            <div style="display: flex; align-items: center; gap: .75rem;">
                                <span
                                    class="plano-badge"
                                    :style="`background: ${seleccionado.color}`"
                                    x-text="seleccionado.etiqueta"
                                ></span>
                                <button type="button" class="plano-modal-cerrar" x-on:click="cerrar()" title="Cerrar (Esc)">&times;</button>
                            </div>
                        </div>

                        <div class="plano-modal-cuerpo">
                            <div>
                                {{-- Mismo truco del viewBox que en el plano
                                     grande: el parser baja los atributos a
                                     minusculas y 'viewbox' no existe en SVG. --}}
                                <svg
                                    class="plano-modal-dibujo"
                                    preserveAspectRatio="xMidYMid meet"
                                    x-effect="$el.setAttribute('viewBox', figura ? figura.viewBox : '0 0 100 100')"
                                >
                                    <polygon
                                        :points="seleccionado.puntos"
                                        :fill="seleccionado.color"
                                        fill-opacity="0.22"
                                        :stroke="seleccionado.color"
                                        stroke-width="1.8"
                                        stroke-linejoin="round"
                                        vector-effect="non-scaling-stroke"
                                    />

                                    {{-- Solo el area en el centro. El numero
                                         del lote NO va: ya esta arriba en el
                                         encabezado del modal —el codigo y el
                                         "Bloque Q · lote 2"— y repetirlo
                                         adentro del dibujo solo estorba. --}}
                                    <text
                                        x-show="figura"
                                        :x="figura?.cx"
                                        :y="figura?.cy"
                                        text-anchor="middle"
                                        dominant-baseline="central"
                                        :font-size="figura ? figura.fuente * 1.35 : 1"
                                        font-weight="600"
                                        fill="#3f3f46"
                                        x-text="`${areaFormateada} v²`"
                                    ></text>

                                    <g x-effect="dibujarCotas($el)"></g>
                                </svg>

                                <p class="plano-modal-escala">Medidas en varas, tomadas del plano del topógrafo.</p>
                            </div>

                            <div>
                                <dl class="plano-datos">
                                    <div class="plano-dato">
                                        <dt>Área</dt>
                                        <dd><span x-text="areaFormateada"></span> v²</dd>
                                    </div>
                                    <div class="plano-dato plano-dato-fuerte">
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
                                    Los botones montan las acciones de Filament
                                    por nombre y cierran este modal en el acto:
                                    el de Filament se monta al final del <body>
                                    y no tendria por que pelear con este por
                                    quien va encima.
                                --}}
                                <div class="plano-acciones">
                                    <template x-if="seleccionado.estado === 'disponible'">
                                        <div class="plano-botonera">
                                            <button type="button" class="plano-accion plano-accion-apartar"
                                                x-on:click="$wire.mountAction('apartarLote', { lote: seleccionado.id }); abierto = false">
                                                Apartar
                                            </button>
                                            <button type="button" class="plano-accion plano-accion-vender"
                                                x-on:click="$wire.mountAction('venderLote', { lote: seleccionado.id }); abierto = false">
                                                Vender
                                            </button>
                                        </div>
                                    </template>

                                    <template x-if="seleccionado.estado === 'apartado'">
                                        <div class="plano-botonera">
                                            <button type="button" class="plano-accion plano-accion-vender"
                                                x-on:click="$wire.mountAction('venderLote', { lote: seleccionado.id }); abierto = false">
                                                Convertir en venta
                                            </button>
                                            <button type="button" class="plano-accion"
                                                x-on:click="$wire.mountAction('liberarLote', { lote: seleccionado.id }); abierto = false">
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

                                {{--
                                    Planes de pago.

                                    La lista de precios por plazo vive en
                                    config/lotificadora.php y hoy viene vacía:
                                    el precio de la vara no es el mismo a 1 año
                                    que a 4, y ese cuadro todavía no lo tenemos
                                    por escrito. Mientras no esté, acá no sale
                                    ninguna cuota — un número inventado en esta
                                    pantalla es un número que alguien le cotiza
                                    a un cliente.
                                --}}
                                <div class="plano-planes">
                                    <p class="plano-planes-titulo">Planes de pago</p>

                                    <template x-if="planes.length === 0">
                                        <p class="plano-planes-nota">
                                            Falta cargar el precio por vara² de cada plazo. En cuanto esté,
                                            este cuadro calcula la cuota de cada plan sobre este lote.
                                        </p>
                                    </template>

                                    <template x-if="planes.length > 0">
                                        <div>
                                            <label class="plano-prima">
                                                Prima
                                                <input type="number" min="0" step="0.01" placeholder="0.00" x-model="prima">
                                                L
                                            </label>

                                            <table class="plano-tabla">
                                                <thead>
                                                    <tr>
                                                        <th>Plazo</th>
                                                        <th>Precio v²</th>
                                                        <th>Valor</th>
                                                        <th>Cuota</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <template x-for="plan in planesCalculados" :key="plan.meses">
                                                        <tr>
                                                            <td x-text="plan.etiqueta"></td>
                                                            <td x-text="plan.precioVara"></td>
                                                            <td x-text="plan.total"></td>
                                                            <td class="cuota" x-text="plan.cuota"></td>
                                                        </tr>
                                                    </template>
                                                </tbody>
                                            </table>

                                            <p class="plano-planes-nota">
                                                Estimado para cotizar. El plan que se firma se arma al registrar
                                                la venta: ahí el residuo del redondeo va a la última cuota.
                                            </p>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </template>
        </div>
    @endif
</x-filament-panels::page>
