{{--
    El plano que el vendedor manda por WhatsApp.

    ═══ CUATRO REGLAS QUE EXPLICAN COMO ESTA HECHO ═══

    1. **La pagina ES el plano.** Nada de tarjetas de resumen ni selectores
       arriba: quien abre el link viene a ver el terreno. Todo lo demas
       —medidas, precios, cuotas— vive adentro del modal del lote, que es
       donde el cliente ya decidio prestar atencion.

    2. **Un solo archivo, sin framework.** Nada de Alpine, nada de CDN. Esto
       se abre en Cucuyagua, en un telefono, con la señal que haya.

    3. **El telefono primero.** Llega por WhatsApp: la enorme mayoria de las
       aperturas van a ser en pantalla chica y en vertical.

    4. **Los datos no se interpolan adentro del JavaScript.** Van en un bloque
       de tipo application/json y el JS los lee de ahi. Mezclar Blade con JS
       es donde un apellido con comilla rompe la pagina entera.

    ⚠️ 🔴 NUNCA escribir una directiva de Blade —con arroba— adentro de un
    comentario como este. Blade corre compileStatements() ANTES que
    compileComments(), asi que la directiva se compila igual y el comentario
    no la protege. Costo dos 500 seguidos el 8-ago.

    ⚠️ El arreglo del bloque json se arma antes, en un bloque php, y se le
    pasa UNA variable: un array literal en varias lineas adentro de los
    parentesis tambien rompe el compilador.

    ⚠️ $plano viene de PlanoPublico, que arma su arreglo con lista blanca. NO
    cambiarlo para que reciba PlanoDelProyecto: ese trae el nombre del
    comprador y el valor al que se vendio cada lote.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=5">
    <meta name="robots" content="index, follow">

    <title>{{ $plano['proyecto']['nombre'] }} — Lotes disponibles</title>
    <meta name="description" content="{{ $plano['disponibles'] }} lotes disponibles en {{ $plano['proyecto']['nombre'] }}. Mirá el plano, las medidas y los precios.">

    <meta property="og:type" content="website">
    <meta property="og:title" content="{{ $plano['proyecto']['nombre'] }}">
    <meta property="og:description" content="{{ $plano['disponibles'] }} lotes disponibles. Mirá el plano, las medidas y los precios.">
    <meta property="og:url" content="{{ url()->current() }}">
    @if ($logo)
        <meta property="og:image" content="{{ $logo }}">
    @endif

    <style>
        /*
         * Todo el diseño vive acá, en un solo bloque, sin hoja externa: es un
         * viaje menos al servidor en un teléfono con mala señal. Y por eso
         * tampoco hay tipografía descargada — la del sistema ya está en el
         * aparato y se pinta en el primer cuadro.
         */
        *, *::before, *::after { box-sizing: border-box; }

        :root {
            --tinta:  #0f172a;
            --suave:  #64748b;
            --linea:  #e2e8f0;
            --papel:  #ffffff;
            --marca:  #f59e0b;
            --libre:  #16a34a;
            --cota:   #dc2626;
            --sombra: 0 1px 2px rgba(15,23,42,.06), 0 8px 24px -8px rgba(15,23,42,.18);
        }

        html, body { height: 100%; }

        body {
            margin: 0;
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            color: var(--tinta);
            line-height: 1.5;
            display: flex;
            flex-direction: column;
            -webkit-text-size-adjust: 100%;
            -webkit-font-smoothing: antialiased;
        }

        /* ─── La barra de arriba ─── */

        header {
            display: flex; align-items: center; gap: .75rem;
            padding: .75rem 1rem; flex: none;
            background: linear-gradient(180deg, #ffffff 0%, #fafafa 100%);
            border-bottom: 1px solid var(--linea);
            box-shadow: 0 1px 12px rgba(15,23,42,.05);
            position: relative; z-index: 5;
        }
        header img { height: 2.25rem; width: auto; border-radius: .375rem; }
        header h1 {
            margin: 0; font-size: 1rem; line-height: 1.2;
            font-weight: 800; letter-spacing: -.015em;
        }
        header p {
            margin: .0625rem 0 0; font-size: .75rem; color: var(--suave);
            display: flex; align-items: center; gap: .3125rem;
        }
        header p::before {
            content: ''; width: .3125rem; height: .3125rem; border-radius: 50%;
            background: var(--marca); flex: none;
        }

        /* El contador, en píldora: es el número que vende. */
        .libres {
            margin-left: auto; display: flex; align-items: baseline; gap: .375rem;
            background: #dcfce7; color: #14532d;
            padding: .375rem .75rem; border-radius: 999px;
            white-space: nowrap; font-size: .6875rem; font-weight: 600;
            box-shadow: inset 0 0 0 1px #bbf7d0;
        }
        .libres b { font-size: 1.125rem; font-weight: 800; letter-spacing: -.02em; }

        /* ─── El plano ─── */

        /*
         * ═══ EL FONDO ES PAPEL DE PLANO ═══
         *
         * Una cuadrícula tenue, un tinte verde de terreno y luz que cae desde
         * arriba. La retícula no es decoración por decorar: es lo que hace
         * que esto se lea como el plano del topógrafo y no como una captura
         * de pantalla pegada sobre un gris.
         *
         * Todo con degradados de CSS, sin una sola imagen: son cero pedidos
         * al servidor, que es lo único que no se negocia en un teléfono con
         * mala señal.
         *
         * La viñeta va como `box-shadow: inset` en vez de un elemento encima,
         * así no intercepta los toques sobre los lotes.
         */
        .lienzo {
            position: relative; flex: 1 1 auto; overflow: hidden; touch-action: none;
            background-color: #f6f7f9;
            background-image:
                radial-gradient(110% 75% at 50% -12%, rgba(255,255,255,1) 0%, rgba(255,255,255,0) 62%),
                linear-gradient(rgba(100,116,139,.09) 1px, transparent 1px),
                linear-gradient(90deg, rgba(100,116,139,.09) 1px, transparent 1px),
                linear-gradient(rgba(100,116,139,.05) 1px, transparent 1px),
                linear-gradient(90deg, rgba(100,116,139,.05) 1px, transparent 1px),
                linear-gradient(165deg, #fbfcfd 0%, #f1f3f6 55%, #e9edf2 100%);
            /* La retícula gruesa cada 6 cuadros, como el papel milimetrado. */
            background-size:
                100% 100%,
                9rem 9rem, 9rem 9rem,
                1.5rem 1.5rem, 1.5rem 1.5rem,
                100% 100%;
            box-shadow: inset 0 0 9rem rgba(15,23,42,.13);
        }

        /*
         * El plano proyecta sombra para despegarse del papel. `drop-shadow`
         * sobre el <svg> entero y NO sobre cada polígono: con 301 lotes, una
         * sombra por lote es lo que hace que el dedo arrastre a los saltos.
         */
        .lienzo svg {
            display: block; width: 100%; height: 100%;
            filter: drop-shadow(0 4px 10px rgba(15,23,42,.10));
        }

        /*
         * ═══ CADA ESTADO SE VE DESDE LEJOS ═══
         *
         * Los colores se pintan por CLASE y no por el atributo `fill` del
         * SVG: un atributo de presentación pierde contra cualquier regla de
         * CSS, así que esto manda sin necesidad de `!important`. El `fill`
         * del HTML queda igual como respaldo por si el CSS no cargara.
         *
         * Son colores PLENOS, sin transparencia. Con opacidad, el verde del
         * disponible y el naranja del apartado se lavaban contra el fondo y
         * a un metro de distancia el plano se veía todo del mismo color — que
         * es justo lo que el cliente mira primero: qué está libre.
         *
         * ⚠️ Estos tonos son los de esta página, NO los del panel. Adentro
         * del sistema mandan los de `EstadoLote::colorHex()`, que están
         * pensados para leerse junto a tablas y formularios, no para llamar
         * la atención en un teléfono.
         */
        /*
         * ═══ RELLENO PASTEL, BORDE SATURADO ═══
         *
         * Cada estado lleva DOS tonos: el relleno claro y un borde del mismo
         * color pero más fuerte. Es lo que hace que un pastel se lea — sin el
         * borde, doscientos verdes claros pegados se ven como una mancha; con
         * el borde, cada lote tiene su contorno y el plano se puede recorrer
         * con la vista.
         *
         * Antes esto iba con colores plenos y el resultado era una pared de
         * verde fosforescente: llamaba la atención, sí, pero cansaba a los
         * diez segundos y el número del lote encima no se leía.
         *
         * El borde deja de ser blanco por lo mismo: el blanco separa pero no
         * dice de qué estado es el lote.
         *
         * ⚠️ Estos tonos son los de esta página, NO los del panel. Adentro
         * del sistema mandan los de `EstadoLote::colorHex()`, pensados para
         * leerse junto a tablas, no para una vidriera.
         */
        .lote { transition: filter .12s ease; }

        .lote.e-disponible { fill: #b8ead0; stroke: #4eb37e; }
        .lote.e-apartado   { fill: #fbdcab; stroke: #dfa04a; }
        .lote.e-vendido    { fill: #f7b8b3; stroke: #e0736a; }
        .lote.e-cancelado  { fill: #e4e4e7; stroke: #a1a1aa; }

        .lote.libre { cursor: pointer; }
        .lote.libre:hover { filter: brightness(.96) saturate(1.25); }
        .lote.activo { stroke: #0f172a; stroke-width: 1.4; filter: saturate(1.35); }

        /* Sobre relleno claro el número va OSCURO, con un halo blanco para
           que no se pierda contra el borde de color. */
        .rotulos text {
            fill: #334155 !important;
            paint-order: stroke;
            stroke: rgba(255,255,255,.85);
            stroke-width: .55;
            stroke-linejoin: round;
        }

        .zoom { position: absolute; right: .75rem; bottom: 3.75rem; display: flex; flex-direction: column; gap: .5rem; }
        .zoom button {
            width: 2.75rem; height: 2.75rem; border-radius: 50%;
            border: 1px solid rgba(15,23,42,.06); background: rgba(255,255,255,.97);
            font: inherit; font-size: 1.25rem; cursor: pointer; color: var(--tinta);
            box-shadow: var(--sombra); transition: transform .12s ease;
            display: flex; align-items: center; justify-content: center;
        }
        .zoom button:active { transform: scale(.92); }

        .leyenda {
            position: absolute; left: 0; right: 0; bottom: 0;
            display: flex; flex-wrap: wrap; gap: .5rem; justify-content: center;
            padding: .625rem .875rem;
            background: linear-gradient(180deg, rgba(255,255,255,0) 0%, rgba(255,255,255,.92) 45%);
            font-size: .75rem; font-weight: 600; color: var(--suave);
        }
        .leyenda span {
            display: inline-flex; align-items: center; gap: .375rem;
            background: var(--papel); padding: .3125rem .625rem; border-radius: 999px;
            box-shadow: 0 1px 2px rgba(15,23,42,.08);
        }
        .punto { width: .625rem; height: .625rem; border-radius: 50%; display: inline-block; }
        .leyenda b { color: var(--tinta); }

        .pista {
            position: absolute; top: .875rem; left: 50%; transform: translateX(-50%);
            background: rgba(15,23,42,.88); color: #fff; padding: .4375rem 1rem;
            border-radius: 999px; font-size: .75rem; font-weight: 500;
            pointer-events: none; transition: opacity .5s; white-space: nowrap;
            box-shadow: 0 6px 18px -6px rgba(15,23,42,.5);
            backdrop-filter: blur(4px);
        }

        /* ─── El modal del lote ─── */

        .telon {
            position: fixed; inset: 0; background: rgba(15,23,42,.55);
            display: none; align-items: flex-end; justify-content: center; z-index: 40;
            backdrop-filter: blur(3px);
        }
        .telon[data-abierto] { display: flex; animation: aparecer .18s ease; }
        @keyframes aparecer { from { opacity: 0; } to { opacity: 1; } }

        .panel {
            background: var(--papel); width: 100%; max-width: 46rem;
            border-radius: 1.5rem 1.5rem 0 0; max-height: 92vh;
            overflow-y: auto; padding: 1.25rem;
            box-shadow: 0 -8px 40px rgba(15,23,42,.25);
            animation: subir .24s cubic-bezier(.16,1,.3,1);
        }
        @keyframes subir { from { transform: translateY(2rem); } to { transform: none; } }

        @media (min-width: 46rem) {
            .telon { align-items: center; padding: 1rem; }
            .panel { border-radius: 1.5rem; }
            @keyframes subir { from { transform: scale(.96); } to { transform: none; } }
        }

        /* La agarradera del cajón: le dice al pulgar que esto se desliza. */
        .panel::before {
            content: ''; display: block; width: 2.25rem; height: .25rem;
            background: var(--linea); border-radius: 999px; margin: -.375rem auto .875rem;
        }
        @media (min-width: 46rem) { .panel::before { display: none; } }

        .panel-cabecera { display: flex; justify-content: space-between; align-items: flex-start; gap: .75rem; }
        .panel h2 { margin: 0; font-size: 1.5rem; font-weight: 800; letter-spacing: -.025em; }
        .panel-cabecera p { margin: .125rem 0 0; color: var(--suave); font-size: .8125rem; font-weight: 500; }
        .estado {
            font-size: .6875rem; font-weight: 700; padding: .25rem .625rem;
            border-radius: 999px; color: #fff; white-space: nowrap;
            box-shadow: 0 2px 8px -2px currentColor;
        }
        .cerrar {
            border: 0; background: #f1f5f9; border-radius: 50%;
            width: 2.125rem; height: 2.125rem; font-size: 1rem; cursor: pointer;
            color: var(--suave); transition: background .12s ease;
        }
        .cerrar:hover { background: var(--linea); }

        .cuerpo { display: grid; gap: 1.125rem; margin-top: 1.125rem; }
        @media (min-width: 46rem) { .cuerpo { grid-template-columns: .9fr 1.1fr; align-items: start; } }

        .dibujo {
            background: linear-gradient(160deg, #f8fafc 0%, #eef2f7 100%);
            border-radius: 1rem; padding: .875rem;
            box-shadow: inset 0 0 0 1px rgba(15,23,42,.04);
        }
        .dibujo svg { display: block; width: 100%; height: auto; }
        .escala { margin: .5rem 0 0; font-size: .6875rem; color: var(--suave); text-align: center; }

        .area {
            display: flex; justify-content: space-between; align-items: baseline;
            margin: 0 0 .25rem; padding: .75rem .875rem;
            background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%);
            border-radius: .875rem;
        }
        .area dt { color: #92400e; font-size: .8125rem; font-weight: 600; }
        .area dd { margin: 0; font-size: 1.375rem; font-weight: 800; color: #78350f; letter-spacing: -.02em; }

        table { width: 100%; border-collapse: collapse; margin-top: .875rem; font-size: .875rem; }
        thead th {
            text-align: right; font-size: .625rem; text-transform: uppercase;
            letter-spacing: .06em; color: var(--suave); font-weight: 700;
            padding: 0 .25rem .5rem;
        }
        thead th:first-child { text-align: left; }
        tbody td { padding: .625rem .25rem; border-top: 1px solid var(--linea); text-align: right; }
        tbody td:first-child { text-align: left; font-weight: 700; }
        tbody tr:hover { background: #f8fafc; }
        tbody tr.contado td { color: var(--suave); }
        .cuota { font-weight: 800; white-space: nowrap; color: var(--libre); letter-spacing: -.015em; }
        .cuota small { display: block; font-weight: 500; font-size: .625rem; color: var(--suave); letter-spacing: 0; }

        .nota { margin: .875rem 0 0; font-size: .75rem; color: var(--suave); line-height: 1.45; }

        .no-cotiza {
            margin-top: 1rem; padding: 1.25rem; background: #f8fafc;
            border-radius: 1rem; color: var(--suave); font-size: .875rem; text-align: center;
        }

        .campos { display: grid; gap: .625rem; margin-top: 1.125rem; }
        @media (min-width: 30rem) { .campos { grid-template-columns: 1fr 1fr; } }
        .campos label { font-size: .75rem; font-weight: 700; color: var(--suave); }
        .campos input {
            width: 100%; margin-top: .25rem; padding: .6875rem .875rem;
            border: 1px solid var(--linea); border-radius: .75rem;
            font: inherit; font-size: 1rem; background: #f8fafc; color: var(--tinta);
            transition: border-color .12s ease, background .12s ease;
        }
        .campos input:focus {
            outline: none; border-color: var(--marca); background: var(--papel);
            box-shadow: 0 0 0 3px rgba(245,158,11,.15);
        }
        .trampa { position: absolute; left: -9999px; width: 1px; height: 1px; overflow: hidden; }

        .boton {
            display: flex; align-items: center; justify-content: center; gap: .5rem;
            width: 100%; margin-top: .875rem; padding: .875rem 1rem;
            border: 0; border-radius: .875rem; font: inherit; font-size: 1rem;
            font-weight: 700; cursor: pointer; text-align: center;
            transition: transform .12s ease, box-shadow .12s ease;
        }
        .boton:active { transform: scale(.985); }
        .boton.verde {
            background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%); color: #fff;
            box-shadow: 0 6px 18px -6px rgba(22,163,74,.65);
        }
        .boton.gris { background: transparent; color: var(--suave); font-weight: 600; box-shadow: none; }

        .gracias {
            position: fixed; top: 1rem; left: 50%; transform: translateX(-50%);
            background: #dcfce7; border: 1px solid #86efac; color: #14532d;
            padding: .75rem 1.125rem; border-radius: .875rem; font-size: .875rem;
            font-weight: 600; z-index: 50; box-shadow: var(--sombra);
        }

        /* ─── Servicios e infraestructura ─── */

        .servicios {
            flex: none; background: var(--papel);
            border-top: 1px solid var(--linea);
            padding: .875rem 1rem;
        }
        .servicios h2 {
            margin: 0 0 .625rem; font-size: .6875rem; font-weight: 700;
            text-transform: uppercase; letter-spacing: .07em; color: var(--suave);
        }
        .servicios ul {
            list-style: none; margin: 0; padding: 0;
            display: grid; gap: .5rem;
            grid-template-columns: repeat(auto-fit, minmax(9.5rem, 1fr));
        }
        .servicios li {
            display: flex; align-items: center; gap: .5rem;
            background: #f0fdf4; border: 1px solid #bbf7d0;
            border-radius: .625rem; padding: .5rem .625rem;
            font-size: .8125rem; font-weight: 600; color: #14532d;
        }
        .servicios svg { flex: none; color: #16a34a; }
    </style>
</head>
<body>

@if (session('gracias'))
    <div class="gracias">{{ session('gracias') }}</div>
@endif

<header>
    @if ($logo)
        <img src="{{ $logo }}" alt="">
    @endif
    <div>
        <h1>{{ $plano['proyecto']['nombre'] }}</h1>
        <p>
            @if ($plano['proyecto']['municipio'])
                {{ $plano['proyecto']['municipio'] }}@if ($plano['proyecto']['departamento']), {{ $plano['proyecto']['departamento'] }}@endif
            @else
                {{ $empresa['nombre'] ?? '' }}
            @endif
        </p>
    </div>
    <div class="libres">
        <b>{{ $plano['disponibles'] }}</b>
        disponibles de {{ $plano['total'] }}
    </div>
</header>

<div class="lienzo" id="lienzo">
    @if ($plano['hayGeometria'])
        <svg id="mapa" viewBox="{{ $plano['viewBox'] }}" preserveAspectRatio="xMidYMid meet"
             role="img" aria-label="Plano de {{ $plano['proyecto']['nombre'] }}">
            @if ($plano['calco'])
                <image href="{{ $plano['calco'] }}" x="0" y="0" opacity=".5" style="pointer-events:none" />
            @endif

            @foreach ($plano['calles'] as $calle)
                @if ($calle['esArea'])
                    <polygon points="{{ $calle['puntos'] }}" fill="#e4e4e7" stroke="none" />
                @else
                    <polyline points="{{ $calle['puntos'] }}" fill="none" stroke="#e4e4e7"
                              stroke-width="{{ $calle['ancho'] }}" stroke-linecap="round" />
                @endif
            @endforeach

            @foreach ($plano['lotes'] as $lote)
                <polygon
                    class="lote e-{{ $lote['estado'] }} {{ $lote['seCotiza'] ? 'libre' : '' }}"
                    points="{{ $lote['puntos'] }}"
                    fill="{{ $lote['color'] }}"
                    stroke-width=".45"
                    data-lote="{{ $lote['id'] }}"
                ><title>{{ $lote['rotulo'] }} — {{ $lote['etiqueta'] }}</title></polygon>
            @endforeach

            {{-- Los rótulos van DESPUES de todos los polígonos, en su propia
                 pasada: dentro del bucle, el lote siguiente se dibuja encima
                 del texto del anterior y las etiquetas del borde quedan
                 tapadas a medias.

                 El tamaño está en varas, igual que las coordenadas, así que
                 el texto crece con el zoom: de lejos es una marca gris, de
                 cerca se lee. Y no recibe clics — el que manda es el lote. --}}
            <g class="rotulos" id="rotulos" pointer-events="none">
                @foreach ($plano['lotes'] as $lote)
                    <text x="{{ $lote['centro'][0] }}" y="{{ $lote['centro'][1] }}"
                          data-puntos="{{ $lote['puntos'] }}"
                          text-anchor="middle" dominant-baseline="central"
                          font-size="2" font-weight="600"
                          fill="{{ $lote['seCotiza'] ? '#14532d' : '#3f3f46' }}"
                          fill-opacity=".75">{{ $lote['numero'] }}@if ($lote['bloque'])-{{ $lote['bloque'] }}@endif</text>
                @endforeach
            </g>
        </svg>

        <p class="pista" id="pista">Pellizcá para acercar · tocá un lote verde para ver su precio</p>

        <div class="zoom">
            <button type="button" id="mas" aria-label="Acercar">+</button>
            <button type="button" id="menos" aria-label="Alejar">−</button>
            <button type="button" id="ajustar" aria-label="Ver todo">⤢</button>
        </div>
    @else
        <p style="padding:2rem;text-align:center;color:var(--suave)">El plano de este proyecto todavía no está dibujado.</p>
    @endif

    <div class="leyenda">
        <span><i class="punto" style="background:#b8ead0;box-shadow:0 0 0 2px #4eb37e"></i> <b>Disponible</b></span>
        <span><i class="punto" style="background:#fbdcab;box-shadow:0 0 0 2px #dfa04a"></i> <b>Apartado</b></span>
        <span><i class="punto" style="background:#f7b8b3;box-shadow:0 0 0 2px #e0736a"></i> <b>Vendido</b></span>
    </div>
</div>

@if ($plano['servicios'] !== [])
    {{-- Lo que el desarrollo YA tiene. Va DEBAJO del plano y no arriba: el
         cliente entra a mirar el terreno, y esto es lo que termina de
         convencerlo cuando ya vio el lote que le gusta. --}}
    <section class="servicios">
        <h2>Servicios e infraestructura</h2>
        <ul>
            @foreach ($plano['servicios'] as $servicio)
                <li>
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="1.8"
                         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="{{ $servicio['trazo'] }}" />
                    </svg>
                    {{ $servicio['etiqueta'] }}
                </li>
            @endforeach
        </ul>
    </section>
@endif

{{-- ─── La ficha del lote ─── --}}
<div class="telon" id="telon">
    <div class="panel" role="dialog" aria-modal="true" aria-labelledby="ficha-codigo">
        <div class="panel-cabecera">
            <div>
                <h2 id="ficha-codigo">—</h2>
                <p id="ficha-ubicacion">—</p>
            </div>
            <div style="display:flex;align-items:center;gap:.5rem">
                <span class="estado" id="ficha-estado">—</span>
                <button type="button" class="cerrar" id="cerrar" aria-label="Cerrar">✕</button>
            </div>
        </div>

        <div class="cuerpo">
            <div>
                <div class="dibujo">
                    <svg id="ficha-dibujo" preserveAspectRatio="xMidYMid meet" viewBox="0 0 100 100"></svg>
                </div>
                <p class="escala">Medidas en varas, tomadas del plano del topógrafo.</p>
            </div>

            <div>
                <dl class="area">
                    <dt>Área</dt>
                    <dd id="ficha-area">—</dd>
                </dl>

                <div id="ficha-precios"></div>
            </div>
        </div>

        <form method="POST" action="{{ route('plano.interes', ['slug' => $proyecto->getAttribute('slug')]) }}" id="ficha-form">
            @csrf
            <input type="hidden" name="lote_id" id="campo-lote">
            <input type="hidden" name="plazo" id="campo-plazo">

            <div class="trampa" aria-hidden="true">
                <label>No llenar<input type="text" name="sitio_web" tabindex="-1" autocomplete="off"></label>
            </div>

            <div class="campos">
                <label>Tu nombre
                    <input type="text" name="nombre" required minlength="3" maxlength="120"
                           autocomplete="name" placeholder="Nombre y apellido">
                </label>
                <label>Tu teléfono
                    <input type="tel" name="telefono" required minlength="8" maxlength="20"
                           autocomplete="tel" placeholder="9999-9999" inputmode="tel">
                </label>
            </div>

            <button type="submit" class="boton verde">
                @if ($whatsapp)
                    <svg width="19" height="19" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                        <path d="M17.47 14.38c-.3-.15-1.76-.87-2.03-.97-.27-.1-.47-.15-.67.15-.2.3-.77.97-.94 1.17-.17.2-.35.22-.65.07-.3-.15-1.25-.46-2.38-1.47-.88-.79-1.48-1.75-1.65-2.05-.17-.3-.02-.46.13-.61.13-.13.3-.35.45-.52.15-.17.2-.3.3-.5.1-.2.05-.37-.02-.52-.08-.15-.67-1.61-.92-2.21-.24-.58-.49-.5-.67-.51h-.57c-.2 0-.52.07-.79.37s-1.04 1.01-1.04 2.48 1.06 2.87 1.21 3.07c.15.2 2.1 3.2 5.08 4.49.71.3 1.26.49 1.69.63.71.22 1.36.19 1.87.12.57-.09 1.76-.72 2.01-1.41.25-.7.25-1.29.17-1.42-.07-.13-.27-.2-.57-.35Z"/>
                        <path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.46 1.32 4.96L2 22l5.25-1.38a9.87 9.87 0 0 0 4.79 1.22h.01c5.46 0 9.91-4.45 9.91-9.91S17.5 2 12.04 2Zm0 18.15h-.01a8.2 8.2 0 0 1-4.18-1.15l-.3-.18-3.12.82.83-3.04-.2-.31a8.19 8.19 0 0 1-1.26-4.38c0-4.54 3.7-8.24 8.24-8.24 2.2 0 4.27.86 5.83 2.41a8.19 8.19 0 0 1 2.41 5.83c0 4.54-3.7 8.24-8.24 8.24Z"/>
                    </svg>
                    Preguntar por este lote
                @else
                    Que me contacten
                @endif
            </button>
        </form>

        <button type="button" class="boton gris" id="cerrar-pie">Seguir viendo el plano</button>
    </div>
</div>

@php
    $paraElNavegador = [
        'lotes'   => $plano['lotes'],
        'precios' => $plano['precios'],
        'planes'  => $plano['planes'],
    ];
@endphp
<script type="application/json" id="datos">@json($paraElNavegador)</script>

<script>
(function () {
    'use strict';

    var datos = JSON.parse(document.getElementById('datos').textContent);
    var porId = {};
    datos.lotes.forEach(function (lote) { porId[lote.id] = lote; });

    var telon   = document.getElementById('telon');
    var marcado = null;

    function puntosDe(texto) {
        return texto.trim().split(/\s+/).map(function (par) {
            var xy = par.split(',');
            return [parseFloat(xy[0]), parseFloat(xy[1])];
        });
    }

    /*
     * El dibujo del lote con sus lados acotados.
     *
     * Se arma acá y no en el servidor porque son cuatro cuentas de geometría
     * sobre puntos que YA viajaron para pintar el plano grande: mandar el
     * mismo polígono dos veces, una con cotas, sería duplicar el peso de la
     * página para 301 lotes de los que se abre uno.
     *
     * La cota se corre hacia AFUERA —en la dirección que va del centro del
     * lote al medio del lado— porque adentro chocaría con el área escrita en
     * el medio, que es el número que más se mira.
     */
    function dibujarLote(lote) {
        var svg = document.getElementById('ficha-dibujo');
        var puntos = puntosDe(lote.puntos);

        var xs = puntos.map(function (p) { return p[0]; });
        var ys = puntos.map(function (p) { return p[1]; });
        var minX = Math.min.apply(null, xs), maxX = Math.max.apply(null, xs);
        var minY = Math.min.apply(null, ys), maxY = Math.max.apply(null, ys);

        var ancho = Math.max(maxX - minX, 0.001);
        var alto  = Math.max(maxY - minY, 0.001);
        var aire  = Math.max(ancho, alto) * 0.30;
        var fuente = Math.max(ancho, alto) * 0.055;

        /*
         * 🔴 Los lados MUY cortos no se acotan.
         *
         * Un lote irregular tiene esquinas recortadas de dos o tres varas
         * entre lados de cincuenta. Sus cotas caen casi en el mismo punto y
         * se montan una encima de otra: se lee «10.88 V2.593.61 V» y no se
         * entiende ninguna de las tres.
         *
         * El umbral es relativo al lado más largo, no absoluto: un lote de
         * 12 varas y uno de 50 no tienen la misma idea de «corto».
         */
        var lados = [];
        var mayor = 0;

        for (var k = 0; k < puntos.length; k++) {
            var p1 = puntos[k], p2 = puntos[(k + 1) % puntos.length];
            var d = Math.sqrt(Math.pow(p2[0] - p1[0], 2) + Math.pow(p2[1] - p1[1], 2));
            lados.push(d);
            if (d > mayor) { mayor = d; }
        }

        var minimo = Math.max(mayor * 0.14, 0.5);

        svg.setAttribute('viewBox',
            (minX - aire) + ' ' + (minY - aire) + ' ' + (ancho + aire * 2) + ' ' + (alto + aire * 2));

        var cx = (minX + maxX) / 2;
        var cy = (minY + maxY) / 2;

        var partes = [
            '<polygon points="' + lote.puntos + '" fill="' + lote.color +
            '" fill-opacity=".2" stroke="' + lote.color +
            '" stroke-width="' + (fuente * 0.14) + '" stroke-linejoin="round" />'
        ];

        for (var i = 0; i < puntos.length; i++) {
            var a = puntos[i];
            var b = puntos[(i + 1) % puntos.length];
            var largo = lados[i];

            // Ver el docblock de arriba: los lados cortos se dibujan pero no
            // se acotan. La línea roja sigue estando; lo que se saca es el
            // número que se montaba sobre el del vecino.
            if (largo < minimo) { continue; }

            var mx = (a[0] + b[0]) / 2;
            var my = (a[1] + b[1]) / 2;

            var dx = mx - cx, dy = my - cy;
            var norma = Math.sqrt(dx * dx + dy * dy) || 1;
            var fx = mx + (dx / norma) * fuente * 1.9;
            var fy = my + (dy / norma) * fuente * 1.9;

            // El texto se endereza: una cota escrita de cabeza no se lee.
            var giro = Math.atan2(b[1] - a[1], b[0] - a[0]) * 180 / Math.PI;
            if (giro > 90) { giro -= 180; } else if (giro < -90) { giro += 180; }

            partes.push(
                '<line x1="' + a[0] + '" y1="' + a[1] + '" x2="' + b[0] + '" y2="' + b[1] +
                '" stroke="var(--cota)" stroke-width="' + (fuente * 0.07) + '" />' +
                '<text x="' + fx + '" y="' + fy + '" fill="var(--cota)" font-size="' + fuente +
                '" text-anchor="middle" dominant-baseline="central" transform="rotate(' +
                giro + ' ' + fx + ' ' + fy + ')">' + largo.toFixed(2) + ' V</text>'
            );
        }

        /*
         * El área NO se escribe adentro del dibujo: ya está en grande a la
         * derecha, que es donde el ojo la busca, y acá solo se montaba con
         * las cotas de los lados largos.
         */
        svg.innerHTML = partes.join('');
    }

    /*
     * La tabla de plazos, entera. No un selector: el cliente quiere comparar
     * —«¿cuánto es a 12 y cuánto a 48?»— y esconder cuatro filas detrás de un
     * botón lo obliga a ir y volver para hacer esa cuenta de cabeza.
     */
    function dibujarPrecios(lote) {
        var caja = document.getElementById('ficha-precios');
        var form = document.getElementById('ficha-form');
        var porMedida = lote.seCotiza ? datos.precios[lote.clave] : null;

        if (!porMedida) {
            caja.innerHTML = '<p class="no-cotiza">' + (lote.seCotiza
                ? 'Consultá el precio de este lote.'
                : 'Este lote ya no está disponible.') + '</p>';
            form.style.display = lote.seCotiza ? '' : 'none';
            document.getElementById('campo-plazo').value = '';
            return;
        }

        form.style.display = '';

        var filas = '';
        var hayInteres = false;
        var primero = null;

        datos.planes.forEach(function (plan) {
            var p = porMedida[plan.meses];
            if (!p) { return; }
            if (primero === null) { primero = plan.meses; }
            if (p.interes) { hayInteres = true; }

            filas +=
                '<tr' + (plan.meses === 0 ? ' class="contado"' : '') + '>' +
                '<td>' + plan.nombre + (plan.tasa ? '<br><small style="font-weight:400;color:var(--suave)">' + plan.tasa + '</small>' : '') + '</td>' +
                '<td>' + p.valor + '</td>' +
                '<td class="cuota">' + (p.cuota ? p.cuota : '—') +
                (p.interes ? '<small>+' + p.interes + ' de intereses</small>' : '') +
                '</td></tr>';
        });

        caja.innerHTML =
            '<table><thead><tr><th>Plazo</th><th>Precio del lote</th><th>Cuota mensual</th></tr></thead>' +
            '<tbody>' + filas + '</tbody></table>' +
            '<p class="nota">Cuota estimada <b>sin prima</b>; con prima baja.' +
            (hayInteres ? ' Los intereses van incluidos en la cuota.' : '') +
            ' Los precios pueden cambiar sin aviso.</p>';

        document.getElementById('campo-plazo').value = primero === null ? '' : primero;
    }

    function abrir(lote) {
        document.getElementById('ficha-codigo').textContent = lote.codigo;
        document.getElementById('ficha-ubicacion').textContent =
            (lote.bloque ? 'Bloque ' + lote.bloque + ' · ' : '') + lote.rotulo;
        document.getElementById('ficha-area').textContent = lote.areaFormateada;

        var estado = document.getElementById('ficha-estado');
        estado.textContent = lote.etiqueta;
        estado.style.background = lote.color;

        document.getElementById('campo-lote').value = lote.id;

        dibujarLote(lote);
        dibujarPrecios(lote);

        telon.setAttribute('data-abierto', '');
    }

    function cerrar() {
        telon.removeAttribute('data-abierto');
        if (marcado) { marcado.classList.remove('activo'); marcado = null; }
    }

    document.getElementById('cerrar').addEventListener('click', cerrar);
    document.getElementById('cerrar-pie').addEventListener('click', cerrar);
    telon.addEventListener('click', function (e) { if (e.target === telon) { cerrar(); } });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { cerrar(); } });

    // ─── El mapa ──────────────────────────────────────────────────────

    var mapa = document.getElementById('mapa');
    if (!mapa) { return; }

    var pista = document.getElementById('pista');
    setTimeout(function () { if (pista) { pista.style.opacity = '0'; } }, 6000);

    /*
     * Zoom y arrastre sobre el viewBox, no con `transform: scale()`.
     *
     * Con transform, el trazo de las calles y el borde de los lotes se
     * engrosan al acercar: en un plano de 301 lotes los bordes se comen a los
     * lotes chicos y deja de distinguirse uno de otro.
     */
    var inicial = mapa.getAttribute('viewBox').split(/[\s,]+/).map(Number);
    var vista = inicial.slice();

    var rotulos = document.getElementById('rotulos');

    /*
     * 🔴 CADA rótulo se mide contra SU lote, una sola vez al cargar.
     *
     * Con un tamaño igual para los 301, el número se sale de los lotes chicos
     * y se monta sobre el vecino — que es exactamente lo que se veía feo. Y
     * esconderlos hasta cierto zoom tampoco servía: lo primero que el cliente
     * quiere saber es CUÁL lote está mirando.
     *
     * La cuenta es el lado corto del lote dividido por los caracteres del
     * rótulo, con un tope para que un lote enorme no lleve un número gigante.
     * Se hace en el navegador y no en el servidor porque los puntos YA
     * viajaron para pintar el plano: mandarlos otra vez, medidos, sería
     * duplicar el peso de la página.
     */
    (function medirRotulos() {
        if (!rotulos) { return; }

        var textos = rotulos.querySelectorAll('text');

        for (var i = 0; i < textos.length; i++) {
            var texto = textos[i];
            var pts = texto.getAttribute('data-puntos');
            if (!pts) { continue; }

            var p = puntosDe(pts);
            var xs = p.map(function (q) { return q[0]; });
            var ys = p.map(function (q) { return q[1]; });
            var w = Math.max.apply(null, xs) - Math.min.apply(null, xs);
            var h = Math.max.apply(null, ys) - Math.min.apply(null, ys);

            var letras = Math.max((texto.textContent || '').trim().length, 1);

            /*
             * El rótulo es una REFERENCIA, no un título: tiene que dejar ver
             * el lote debajo. Con letra grande el plano se lee como una tabla
             * de números y se pierde de vista la forma del terreno, que es
             * para lo que el cliente abrió la página.
             *
             * 1.15 es el ancho de un carácter respecto de su altura; el 0.34
             * deja aire arriba y abajo. El tope de 2.4 varas evita que un lote
             * grande lleve un número desproporcionado.
             */
            var cabe = Math.min(Math.min(w, h) * 0.34, (w / letras) * 1.15);

            texto.setAttribute('font-size', Math.max(Math.min(cabe, 2.4), 0.7).toFixed(2));
        }
    })();

    function aplicar() { mapa.setAttribute('viewBox', vista.join(' ')); }

    function escalar(factor, px, py) {
        var nuevo = vista[2] * factor;
        if (nuevo > inicial[2] * 1.4 || nuevo < inicial[2] / 16) { return; }
        vista[0] += (px - vista[0]) * (1 - factor);
        vista[1] += (py - vista[1]) * (1 - factor);
        vista[2] = nuevo;
        vista[3] = vista[3] * factor;
        aplicar();
    }

    function enPlano(cx, cy) {
        var caja = mapa.getBoundingClientRect();
        return [
            vista[0] + (cx - caja.left) / caja.width * vista[2],
            vista[1] + (cy - caja.top) / caja.height * vista[3]
        ];
    }

    document.getElementById('mas').addEventListener('click', function () {
        escalar(0.7, vista[0] + vista[2] / 2, vista[1] + vista[3] / 2);
    });
    document.getElementById('menos').addEventListener('click', function () {
        escalar(1 / 0.7, vista[0] + vista[2] / 2, vista[1] + vista[3] / 2);
    });
    document.getElementById('ajustar').addEventListener('click', function () {
        vista = inicial.slice();
        aplicar();
    });

    mapa.addEventListener('wheel', function (e) {
        e.preventDefault();
        var p = enPlano(e.clientX, e.clientY);
        escalar(e.deltaY > 0 ? 1.12 : 1 / 1.12, p[0], p[1]);
    }, { passive: false });

    var dedos = {};
    var separacion = null;
    var desde = null;
    var movio = false;

    /*
     * 🔴 El lote se guarda en el `pointerdown` y NO se busca en el `click`.
     *
     * `setPointerCapture()` redirige todos los eventos siguientes —el `click`
     * incluido— al elemento que capturó, o sea al <svg>. Ahí `e.target` deja
     * de ser el polígono y `closest('polygon[data-lote]')` devuelve null: se
     * puede tocar un lote todo el día y no pasa nada.
     *
     * En el `pointerdown` el target todavía es el polígono, así que ese es el
     * único momento en que se sabe qué se tocó.
     */
    var objetivo = null;

    mapa.addEventListener('pointerdown', function (e) {
        dedos[e.pointerId] = [e.clientX, e.clientY];
        movio = false;
        desde = enPlano(e.clientX, e.clientY);
        objetivo = e.target.closest ? e.target.closest('polygon[data-lote]') : null;
        mapa.setPointerCapture(e.pointerId);
    });

    mapa.addEventListener('pointermove', function (e) {
        if (!(e.pointerId in dedos)) { return; }
        dedos[e.pointerId] = [e.clientX, e.clientY];

        var ids = Object.keys(dedos);

        // Dos dedos: pellizco para acercar.
        if (ids.length === 2) {
            var a = dedos[ids[0]], b = dedos[ids[1]];
            var ahora = Math.hypot(a[0] - b[0], a[1] - b[1]);

            if (separacion !== null && ahora > 0) {
                var centro = enPlano((a[0] + b[0]) / 2, (a[1] + b[1]) / 2);
                escalar(separacion / ahora, centro[0], centro[1]);
            }

            separacion = ahora;
            movio = true;
            return;
        }

        if (!desde) { return; }

        var p = enPlano(e.clientX, e.clientY);
        var dx = desde[0] - p[0];
        var dy = desde[1] - p[1];

        if (Math.abs(dx) > vista[2] / 180 || Math.abs(dy) > vista[3] / 180) { movio = true; }

        vista[0] += dx;
        vista[1] += dy;
        aplicar();
    });

    function soltar(e) {
        delete dedos[e.pointerId];
        if (Object.keys(dedos).length < 2) { separacion = null; }
        if (Object.keys(dedos).length === 0) { desde = null; }
    }

    mapa.addEventListener('pointerup', soltar);
    mapa.addEventListener('pointercancel', soltar);

    mapa.addEventListener('click', function () {
        var figura = objetivo;
        objetivo = null;

        // Sin esto, arrastrar el plano abriría la ficha del lote donde se
        // levantó el dedo.
        if (movio) { movio = false; return; }

        if (!figura || !figura.dataset || !porId[figura.dataset.lote]) { return; }

        if (marcado) { marcado.classList.remove('activo'); }
        marcado = figura;
        figura.classList.add('activo');

        abrir(porId[figura.dataset.lote]);
    });
})();
</script>
</body>
</html>
