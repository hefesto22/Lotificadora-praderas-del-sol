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
    <meta property="og:site_name" content="{{ $empresa['nombre'] ?? $plano['proyecto']['nombre'] }}">

    {{-- La miniatura de la tarjeta. Es lo unico de esta pagina cuyo trabajo
         es que alguien haga clic: en WhatsApp un link sin imagen llega como
         una linea de texto azul y no lo abre nadie.

         El dibujo del plano si el servidor lo puede generar; si no, el logo,
         que al menos ocupa el lugar. Ver PlanoImagenController. --}}
    @if ($imagen)
        <meta property="og:image" content="{{ $imagen }}">
        <meta property="og:image:type" content="image/png">
        <meta property="og:image:width" content="1200">
        <meta property="og:image:height" content="630">
        <meta property="og:image:alt" content="Plano de {{ $plano['proyecto']['nombre'] }}">
        <meta name="twitter:card" content="summary_large_image">
    @elseif ($logo)
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
         *
         * ⚠️ Y no están escritos acá: salen de `EstadoLote::relleno()` y
         * `borde()`, porque los mismos dos tonos los usan la leyenda de más
         * abajo y el PNG que ve WhatsApp. Tres listas que tienen que
         * coincidir terminan no coincidiendo.
         */
        .lote { transition: filter .12s ease; }

@foreach ($plano['colores'] as $tono)
        .lote.e-{{ $tono['estado'] }} { fill: {{ $tono['relleno'] }}; stroke: {{ $tono['borde'] }}; }
@endforeach

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

        /* ─── Ubicación del proyecto ─── */

        .llegar {
            flex: none; background: var(--papel);
            border-top: 1px solid var(--linea);
            padding: .875rem 1rem 1.125rem;
        }
        .llegar h2 {
            margin: 0 0 .625rem; font-size: .6875rem; font-weight: 700;
            text-transform: uppercase; letter-spacing: .07em; color: var(--suave);
        }
        .llegar .destinos {
            display: grid; gap: .5rem;
            grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr));
        }
        .llegar a {
            display: flex; align-items: center; justify-content: center; gap: .5rem;
            padding: .8125rem 1rem; border-radius: .875rem;
            font-weight: 700; font-size: .9375rem; text-decoration: none;
            transition: transform .12s ease;
        }
        .llegar a:active { transform: scale(.985); }
        .llegar a.maps { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }
        .llegar a.waze { background: #ecfeff; color: #0e7490; border: 1px solid #a5f3fc; }
        .llegar svg { flex: none; }

        /* Los dos botones del pie de la ficha, lado a lado cuando entran. */
        .acciones { display: grid; }
        @media (min-width: 30rem) { .acciones { grid-template-columns: 1fr 1fr; gap: .5rem; } }
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

    {{-- Los mismos tonos del plano, del mismo lugar. Cancelado no se
         explica: para el cliente solo significa «no está a la venta», y
         nombrarlo invita a preguntar por qué. --}}
    <div class="leyenda">
        @foreach ($plano['colores'] as $tono)
            @if ($tono['enLeyenda'])
                <span><i class="punto" style="background:{{ $tono['relleno'] }};box-shadow:0 0 0 2px {{ $tono['borde'] }}"></i> <b>{{ $tono['etiqueta'] }}</b></span>
            @endif
        @endforeach
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

@if ($plano['ubicacion'])
    {{-- La segunda pregunta que hace todo el mundo, después del precio.

         Los dos enlaces están en el formato que arranca la APLICACIÓN con la
         ruta puesta, no el que abre el sitio web adentro del navegador del
         teléfono. En un móvil eso es la diferencia entre «cómo llegar» y una
         dirección escrita. --}}
    <section class="llegar">
        <h2>Cómo llegar</h2>
        <div class="destinos">
            <a class="maps" href="{{ $plano['ubicacion']['googleMaps'] }}" target="_blank" rel="noopener noreferrer">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z" />
                    <circle cx="12" cy="10" r="3" />
                </svg>
                Abrir en Google Maps
            </a>
            <a class="waze" href="{{ $plano['ubicacion']['waze'] }}" target="_blank" rel="noopener noreferrer">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="m3 11 18-8-8 18-2-8-8-2Z" />
                </svg>
                Navegar con Waze
            </a>
        </div>
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

                {{-- Solo aparece si el lote tiene foto cargada. Fotografiar
                     301 lotes lleva meses: el resto se ve igual que siempre. --}}
                <button type="button" class="boton360" id="ficha-360" hidden>
                    <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true">
                        <circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="2"/>
                        <ellipse cx="12" cy="12" rx="9" ry="4" fill="none" stroke="currentColor" stroke-width="2"/>
                        <path d="M12 3v18" fill="none" stroke="currentColor" stroke-width="2"/>
                    </svg>
                    Ver el terreno en 360°
                </button>
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

        <div class="acciones">
            <button type="button" class="boton gris" id="copiar">Copiar el enlace de este lote</button>
            <button type="button" class="boton gris" id="cerrar-pie">Seguir viendo el plano</button>
        </div>
    </div>
</div>

{{--
    ═══ EL VISOR 360 ═══

    Vive fuera de la ficha y a pantalla completa: mirar alrededor con el pulgar
    dentro de un recuadro de ocho centímetros no es mirar alrededor.

    El <canvas> arranca vacío y sin contexto WebGL. Nada de esto cuesta un byte
    de red ni un milisegundo de CPU hasta que alguien toca «Ver el terreno en
    360°», que es la única forma de que una página con 301 lotes siga abriendo
    rápido.
--}}
<div id="visor360" hidden>
    <canvas id="visor360-lienzo"></canvas>
    {{-- Las marcas van en su propia capa, trazadas como vectores contra la
         resolución de la pantalla. Ver `dibujarMarcas`. --}}
    <canvas id="visor360-marcas"></canvas>

    <div class="visor360-barra">
        <span id="visor360-titulo">—</span>
        <button type="button" id="visor360-cerrar" aria-label="Cerrar la vista 360">✕</button>
    </div>

    <p class="visor360-ayuda" id="visor360-ayuda">Arrastrá para mirar alrededor</p>
    <p class="visor360-estado" id="visor360-estado" hidden>Cargando la foto…</p>
</div>

<style>
    .boton360 {
        display: flex; align-items: center; justify-content: center; gap: .5rem;
        width: 100%; margin-top: .75rem; padding: .7rem 1rem;
        border: 1px solid rgba(0, 0, 0, .12); border-radius: .75rem;
        background: #fff; color: #1f2937;
        font: inherit; font-weight: 600; font-size: .9rem;
        cursor: pointer; transition: background .15s, border-color .15s;
    }
    .boton360:hover { background: #f9fafb; border-color: rgba(0, 0, 0, .22); }
    .boton360 svg { flex: none; opacity: .75; }

    #visor360 {
        position: fixed; inset: 0; z-index: 60;
        background: #000;
        /* El dedo tiene que girar la foto, no arrastrar la página debajo. */
        touch-action: none; overscroll-behavior: contain;
    }
    #visor360[hidden] { display: none; }
    #visor360-lienzo, #visor360-marcas { position: absolute; inset: 0; width: 100%; height: 100%; display: block; }
    #visor360-lienzo { cursor: grab; }
    /* La capa solo dibuja: el dedo tiene que llegar al lienzo de abajo. */
    #visor360-marcas { pointer-events: none; }
    #visor360-lienzo:active { cursor: grabbing; }

    .visor360-barra {
        position: absolute; top: 0; left: 0; right: 0;
        display: flex; align-items: center; justify-content: space-between; gap: 1rem;
        padding: .9rem 1.1rem;
        color: #fff; font-weight: 600;
        /* Degradado y no una barra sólida: la foto sigue viéndose debajo. */
        background: linear-gradient(rgba(0, 0, 0, .55), rgba(0, 0, 0, 0));
        pointer-events: none;
    }
    .visor360-barra button {
        pointer-events: auto;
        width: 2.25rem; height: 2.25rem;
        display: flex; align-items: center; justify-content: center;
        border: 0; border-radius: 50%;
        background: rgba(0, 0, 0, .45); color: #fff;
        font-size: 1rem; line-height: 1; cursor: pointer;
    }
    .visor360-ayuda, .visor360-estado {
        position: absolute; left: 50%; transform: translateX(-50%);
        margin: 0; padding: .45rem .9rem;
        border-radius: 999px;
        background: rgba(0, 0, 0, .5); color: #fff;
        font-size: .8rem; pointer-events: none;
    }
    .visor360-ayuda { bottom: 1.5rem; transition: opacity .4s; }
    .visor360-estado { top: 50%; transform: translate(-50%, -50%); }
    .visor360-ayuda[hidden], .visor360-estado[hidden] { display: none; }
</style>

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
                /*
                 * ⚠️ El interes NO se desglosa en la vidriera, y no es un
                 * olvido: «L 43,020.56 de intereses» al lado de la cuota
                 * asusta a quien todavia no decidio nada, aunque sea el mismo
                 * dinero que ya esta adentro de la cuota que si se muestra.
                 *
                 * Que va incluido lo dice la nota de abajo, con todas las
                 * letras, y la tasa anual aparece debajo del plazo: quien
                 * quiere hacer la cuenta tiene con que, y quien solo quiere
                 * saber cuanto paga por mes ve un numero y no dos.
                 *
                 * Adentro del panel SI se desglosa. Ahi lo mira la
                 * administracion, que necesita ese numero para decidir.
                 */
                '<td class="cuota">' + (p.cuota ? p.cuota : '—') + '</td></tr>';
        });

        caja.innerHTML =
            '<table><thead><tr><th>Plazo</th><th>Precio del lote</th><th>Cuota mensual</th></tr></thead>' +
            '<tbody>' + filas + '</tbody></table>' +
            '<p class="nota">Cuota estimada <b>sin prima</b>; con prima baja.' +
            (hayInteres ? ' Los intereses van incluidos en la cuota.' : '') +
            ' Los precios pueden cambiar sin aviso.</p>';

        document.getElementById('campo-plazo').value = primero === null ? '' : primero;
    }


    /*
     * ═══════════════════════════════════════════════════════════════
     * EL VISOR 360
     * ═══════════════════════════════════════════════════════════════
     *
     * Sin librería. No es orgullo: pannellum son 50 KB y three.js son 600,
     * y esta página se abre desde un link de WhatsApp con datos móviles.
     * Todo esto son cuatro kilobytes y no baja NADA hasta que alguien toca
     * el botón.
     *
     * ═══ NO HAY ESFERA, HAY UN SHADER ═══
     *
     * Lo normal es envolver la foto en una esfera de triángulos y mirarla
     * desde adentro. Acá se dibuja un solo rectángulo que cubre la pantalla
     * y, POR CADA PÍXEL, se calcula hacia dónde apunta ese rayo y se lee el
     * color que le toca en la equirectangular.
     *
     * Sale más simple —veinte líneas de GLSL contra geometría y matrices— y
     * además mejor: una esfera de triángulos deja costuras en los polos, y
     * acá no hay triángulos que se noten.
     *
     * ═══ PRIMERO LA CHIQUITA ═══
     *
     * Se pinta la miniatura de 256×128 —ocho kilobytes, llega enseguida,
     * borrosa— y encima entra la de 4096 cuando termina de bajar. La
     * diferencia es entre «toqué y no pasa nada» y «ya estoy adentro».
     *
     * ═══ SIN MIPMAPS, A PROPÓSITO ═══
     *
     * Con mipmaps, el salto de la coordenada u al dar la vuelta hace que la
     * GPU elija el nivel más chico en esa columna y aparece una costura
     * vertical nítida. Con LINEAR puro no hay derivadas y no hay costura.
     */
    var Visor360 = {
        gl: null, prog: null, tex: null, esWebgl2: false,
        lon: 0, lat: 0, fov: 1.0,
        arrastrando: false, x: 0, y: 0, pinza: 0,
        pedido: 0,

        marcas: [],

        /*
         * ═══════════════════════════════════════════════════════════════
         * LAS MARCAS DEL LOTE: SU CONTORNO Y SUS ROTULOS
         * ═══════════════════════════════════════════════════════════════
         *
         * No vienen dentro de la foto. Vienen como ANGULOS sobre la esfera y se
         * trazan acá, en una capa 2D del tamaño real de la pantalla, en cada
         * cuadro.
         *
         * La diferencia no es de estilo. Una línea quemada en el JPG deja de
         * ser una línea: se reduce de 12000 a 6144, se realza, se comprime, y
         * al hacer zoom el cliente agranda sus píxeles. Trazada como vector es
         * nítida a cualquier zoom, la foto puede pesar lo que sea, y el rótulo
         * queda exactamente donde y como lo fijó quien lo puso.
         *
         * La cuenta es la misma que usa el editor 360, de los dos lados:
         * proyectar cada punto y partir los tramos siguiendo el arco de la
         * esfera. Si algún día una cambia, las dos tienen que cambiar.
         */
        dibujarMarcas: function () {
            var capa = document.getElementById('visor360-marcas');
            var g = capa.getContext('2d');
            var lienzo = document.getElementById('visor360-lienzo');

            g.setTransform(1, 0, 0, 1, 0, 0);
            g.clearRect(0, 0, capa.width, capa.height);

            if (!this.marcas.length) { return; }

            var escala = capa.width / Math.max(lienzo.clientWidth, 1);
            g.scale(escala, escala);
            g.lineJoin = 'round';
            g.lineCap = 'round';

            var visor = this;

            this.marcas.forEach(function (m) {
                if (m.tipo === 'rotulo') { visor.rotulo(g, m, escala); } else { visor.contorno(g, m); }
            });
        },

        contorno: function (g, m) {
            if (m.puntos.length < 2) { return; }

            var lista = m.puntos.slice();
            if (m.cerrada && m.puntos.length > 2) { lista.push(m.puntos[0]); }

            var ancho = Math.max(this.enPantalla(m.grosor * 0.003), 1);
            var visor = this;

            /*
             * Halo oscuro y después el color. Una línea amarilla sobre tierra
             * clara con sol de mediodía se pierde; el halo la despega del fondo.
             */
            [['rgba(0,0,0,.55)', ancho * 1.9], [m.color, ancho]].forEach(function (pasada) {
                g.strokeStyle = pasada[0];
                g.lineWidth = pasada[1];

                for (var i = 0; i < lista.length - 1; i++) {
                    var muestras = visor.arco(lista[i], lista[i + 1], 64);
                    var abierto = false;

                    g.beginPath();
                    for (var j = 0; j < muestras.length; j++) {
                        var s = visor.aPantalla(muestras[j]);

                        if (!s) {
                            if (abierto) { g.stroke(); g.beginPath(); abierto = false; }
                            continue;
                        }

                        if (abierto) { g.lineTo(s[0], s[1]); } else { g.moveTo(s[0], s[1]); abierto = true; }
                    }
                    if (abierto) { g.stroke(); }
                }
            });
        },

        rotulo: function (g, m, escala) {
            var alto = m.tamano * 0.0012;
            var P = this.direccion(m.puntos[0]);
            var e = this.ejes(m);

            var A = this.unitario([P[0] + e.der[0] * alto, P[1] + e.der[1] * alto, P[2] + e.der[2] * alto]);
            var B = this.unitario([P[0] + e.arr[0] * alto, P[1] + e.arr[1] * alto, P[2] + e.arr[2] * alto]);

            var s = this.aPantalla(m.puntos[0]);
            var a = this.aPantalla(this.aAngulos(A));
            var b = this.aPantalla(this.aAngulos(B));
            if (!s || !a || !b) { return; }

            var R = [a[0] - s[0], a[1] - s[1]];
            var U = [b[0] - s[0], b[1] - s[1]];
            var k = 100;

            /*
             * Se compone con fuente de 100 y la matriz lo lleva a su tamaño: el
             * lienzo transforma los CONTORNOS de las letras antes de rasterizar,
             * así que el texto sale nítido a cualquier zoom.
             */
            g.save();
            g.setTransform(escala, 0, 0, escala, 0, 0);
            g.transform(R[0] / k, R[1] / k, -U[0] / k, -U[1] / k, s[0], s[1]);
            g.font = '700 ' + k + 'px -apple-system, "Segoe UI", sans-serif';
            g.textAlign = 'center';
            g.textBaseline = 'middle';
            g.lineJoin = 'round';
            g.lineWidth = k / 5;
            g.strokeStyle = 'rgba(0,0,0,.75)';
            g.strokeText(m.texto, 0, 0);
            g.fillStyle = m.color;
            g.fillText(m.texto, 0, 0);
            g.restore();
        },

        /*
         * Cómo se para el rótulo. `plano` se ancla a la vista desde la que se
         * puso: un cartel que siempre mira a la cámara necesita una cámara, y
         * acá lo que hay es una dirección guardada.
         */
        ejes: function (m) {
            var p = m.puntos[0];
            var este = [Math.cos(p[0]), 0, Math.sin(p[0])];
            var base;

            if (m.orientacion === 'parado') {
                base = { der: este, arr: [0, 1, 0] };
            } else if (m.orientacion === 'suelo') {
                base = { der: este, arr: [Math.sin(p[0]), 0, -Math.cos(p[0])] };
            } else {
                var frente = this.direccion(m.vista);
                var der = [Math.cos(m.vista[0]), 0, Math.sin(m.vista[0])];

                // arriba = derecha × frente, EN ESE ORDEN. Al revés el rótulo
                // sale de cabeza, y ningún número lo delata.
                base = { der: der, arr: this.unitario([
                    der[1] * frente[2] - der[2] * frente[1],
                    der[2] * frente[0] - der[0] * frente[2],
                    der[0] * frente[1] - der[1] * frente[0],
                ]) };
            }

            if (!m.giro) { return base; }

            var t = m.giro * Math.PI / 180, c = Math.cos(t), n = Math.sin(t);

            return {
                der: [0, 1, 2].map(function (i) { return base.der[i] * c + base.arr[i] * n; }),
                arr: [0, 1, 2].map(function (i) { return base.arr[i] * c - base.der[i] * n; }),
            };
        },

        // ─── La geometría, igual que en el editor ──────────────────────

        direccion: function (p) {
            var cl = Math.cos(p[1]);
            return [Math.sin(p[0]) * cl, Math.sin(p[1]), -Math.cos(p[0]) * cl];
        },

        aAngulos: function (d) {
            return [Math.atan2(d[0], -d[2]), Math.asin(Math.max(-1, Math.min(1, d[1])))];
        },

        unitario: function (d) {
            var n = Math.hypot(d[0], d[1], d[2]) || 1;
            return [d[0] / n, d[1] / n, d[2] / n];
        },

        /** De ángulo a píxeles, con el zoom actual. El denominador es `fov`. */
        enPantalla: function (angulo) {
            var c = document.getElementById('visor360-lienzo');
            return angulo * (c.clientHeight / 2) / this.fov;
        },

        /** De un punto de la esfera a un píxel de la pantalla, o null si quedó atrás. */
        aPantalla: function (p) {
            var d = this.direccion(p);

            var co = Math.cos(this.lon), so = Math.sin(this.lon);
            d = [d[0] * co - d[2] * so, d[1], d[0] * so + d[2] * co];

            var cl = Math.cos(this.lat), sl = Math.sin(this.lat);
            d = [d[0], d[1] * cl + d[2] * sl, -d[1] * sl + d[2] * cl];

            if (d[2] >= -0.001) { return null; }

            var c = document.getElementById('visor360-lienzo');
            var razon = c.width / Math.max(c.height, 1);

            return [
                ((d[0] / -d[2]) / (razon * this.fov) + 1) / 2 * c.clientWidth,
                (1 - (d[1] / -d[2]) / this.fov) / 2 * c.clientHeight
            ];
        },

        /** El arco entre dos puntos, partido en pedacitos que lo siguen. */
        arco: function (a, b, pasos) {
            var d1 = this.direccion(a), d2 = this.direccion(b);
            var cos = Math.max(-1, Math.min(1, d1[0]*d2[0] + d1[1]*d2[1] + d1[2]*d2[2]));
            var ang = Math.acos(cos);
            var salida = [];

            for (var i = 0; i <= pasos; i++) {
                var t = i / pasos, d;

                if (ang < 1e-6) {
                    d = d1;
                } else {
                    var s1 = Math.sin((1 - t) * ang) / Math.sin(ang);
                    var s2 = Math.sin(t * ang) / Math.sin(ang);
                    d = [d1[0]*s1 + d2[0]*s2, d1[1]*s1 + d2[1]*s2, d1[2]*s1 + d2[2]*s2];
                }

                salida.push(this.aAngulos(d));
            }

            return salida;
        },

        abrir: function (lote) {
            var caja = document.getElementById('visor360');
            document.getElementById('visor360-titulo').textContent = lote.codigo;

            caja.hidden = false;
            this.lon = 0; this.lat = 0; this.fov = 1.0;
            this.marcas = Array.isArray(lote.foto360Marcas) ? lote.foto360Marcas : [];

            var ayuda = document.getElementById('visor360-ayuda');
            ayuda.hidden = false;
            ayuda.style.opacity = '1';
            setTimeout(function () { ayuda.style.opacity = '0'; }, 2600);

            if (!this.iniciar()) {
                this.mensaje('Este navegador no puede mostrar la vista 360.');
                return;
            }

            this.medir();

            // Un contador por apertura: si alguien cierra y abre otro lote
            // mientras la foto anterior venía en camino, la que llega tarde
            // no pisa la nueva.
            var mio = ++this.pedido;
            var visor = this;

            this.mensaje('Cargando la foto…');

            // Las marcas se ven desde el primer cuadro, sin esperar la foto.
            this.dibujarMarcas();

            if (lote.foto360Mini) {
                this.cargar(lote.foto360Mini, mio, false);
            }

            this.cargar(lote.foto360, mio, true);
        },

        cerrar: function () {
            this.pedido++;
            this.marcas = [];
            document.getElementById('visor360').hidden = true;
        },

        mensaje: function (texto) {
            var caja = document.getElementById('visor360-estado');
            if (texto === null) { caja.hidden = true; return; }
            caja.textContent = texto;
            caja.hidden = false;
        },

        cargar: function (url, mio, esGrande) {
            var visor = this;
            var img = new Image();
            img.crossOrigin = 'anonymous';

            img.onload = function () {
                if (mio !== visor.pedido) { return; }
                visor.subirTextura(img);
                if (esGrande) { visor.mensaje(null); }
                visor.dibujar();
            };

            img.onerror = function () {
                if (mio !== visor.pedido || !esGrande) { return; }
                visor.mensaje('No se pudo cargar la foto.');
            };

            img.src = url;
        },

        iniciar: function () {
            if (this.gl) { return true; }

            var lienzo = document.getElementById('visor360-lienzo');

            /*
             * WebGL 2 primero, y no por capricho: la foto es de 6144 de ancho,
             * que NO es potencia de dos, y WebGL 1 no deja envolver (`REPEAT`)
             * una textura asi — la esfera sale negra, sin error en consola.
             * WebGL 2 lo admite y lo tiene todo lo que sea de 2021 en adelante.
             * Para lo anterior hay respaldo en `subirTextura`.
             */
            var gl = lienzo.getContext('webgl2');
            this.esWebgl2 = !!gl;

            if (!gl) { gl = lienzo.getContext('webgl') || lienzo.getContext('experimental-webgl'); }
            if (!gl) { return false; }

            var vs =
                'attribute vec2 p; varying vec2 v;' +
                'void main(){ v = p; gl_Position = vec4(p, 0.0, 1.0); }';

            var fs =
                'precision highp float;' +
                'varying vec2 v;' +
                'uniform vec2 aspecto;' +
                'uniform float fov;' +
                'uniform vec2 ang;' +
                'uniform sampler2D foto;' +
                'const float PI = 3.14159265359;' +
                'void main(){' +
                '  vec3 d = normalize(vec3(v.x * aspecto.x * fov, v.y * aspecto.y * fov, -1.0));' +
                /* primero se inclina (mirar arriba/abajo), después se gira */
                '  float cl = cos(ang.y), sl = sin(ang.y);' +
                '  d = vec3(d.x, d.y * cl - d.z * sl, d.y * sl + d.z * cl);' +
                '  float co = cos(ang.x), so = sin(ang.x);' +
                '  d = vec3(d.x * co + d.z * so, d.y, -d.x * so + d.z * co);' +
                /* del rayo a la equirectangular: longitud y latitud */
                '  float u = atan(d.x, -d.z) / (2.0 * PI) + 0.5;' +
                '  float w = acos(clamp(d.y, -1.0, 1.0)) / PI;' +
                '  gl_FragColor = texture2D(foto, vec2(u, w));' +
                '}';

            var prog = gl.createProgram();
            [[gl.VERTEX_SHADER, vs], [gl.FRAGMENT_SHADER, fs]].forEach(function (par) {
                var sh = gl.createShader(par[0]);
                gl.shaderSource(sh, par[1]);
                gl.compileShader(sh);
                gl.attachShader(prog, sh);
            });
            gl.linkProgram(prog);
            gl.useProgram(prog);

            var buf = gl.createBuffer();
            gl.bindBuffer(gl.ARRAY_BUFFER, buf);
            gl.bufferData(gl.ARRAY_BUFFER, new Float32Array([-1, -1, 1, -1, -1, 1, 1, 1]), gl.STATIC_DRAW);
            var p = gl.getAttribLocation(prog, 'p');
            gl.enableVertexAttribArray(p);
            gl.vertexAttribPointer(p, 2, gl.FLOAT, false, 0, 0);

            this.gl = gl;
            this.prog = prog;
            this.tex = gl.createTexture();

            gl.bindTexture(gl.TEXTURE_2D, this.tex);
            gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.LINEAR);
            gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MAG_FILTER, gl.LINEAR);
            // Horizontal da la vuelta; vertical no, o el cielo se refleja
            // en el suelo.
            gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_S, gl.REPEAT);
            gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_T, gl.CLAMP_TO_EDGE);

            this.conectarGestos(lienzo);
            return true;
        },

        subirTextura: function (img) {
            var gl = this.gl;
            var fuente = img;

            /*
             * Dos techos, y hay que respetar el menor de los dos:
             *
             *   · `MAX_TEXTURE_SIZE`, lo que la GPU aguanta.
             *   · En WebGL 1, ademas, potencia de dos — o no hay `REPEAT`.
             *
             * Pasarse de cualquiera de los dos no da un error que se pueda
             * leer: da una esfera negra. Asi que en vez de confiar, se mide y
             * se achica acá mismo. El aparato viejo ve un poco menos detalle;
             * lo que no ve nadie es una pantalla en negro.
             */
            var techo = gl.getParameter(gl.MAX_TEXTURE_SIZE);
            var limite = this.esWebgl2 ? techo : Math.min(techo, 4096);

            if (img.width > limite) {
                var reducido = document.createElement('canvas');
                reducido.width = limite;
                reducido.height = Math.round(limite / 2);
                reducido.getContext('2d').drawImage(img, 0, 0, reducido.width, reducido.height);
                fuente = reducido;
            }

            gl.bindTexture(gl.TEXTURE_2D, this.tex);
            gl.pixelStorei(gl.UNPACK_FLIP_Y_WEBGL, false);
            gl.texImage2D(gl.TEXTURE_2D, 0, gl.RGB, gl.RGB, gl.UNSIGNED_BYTE, fuente);
        },

        medir: function () {
            var lienzo = document.getElementById('visor360-lienzo');
            // El límite de 2 no es estético: en una pantalla Retina grande,
            // pintar a 3× son cuatro millones de píxeles por cuadro y el
            // teléfono se calienta sin que se note ninguna mejora.
            var escala = Math.min(window.devicePixelRatio || 1, 2);
            lienzo.width = Math.round(lienzo.clientWidth * escala);
            lienzo.height = Math.round(lienzo.clientHeight * escala);

            var capa = document.getElementById('visor360-marcas');
            capa.width = lienzo.width; capa.height = lienzo.height;

            if (this.gl) { this.gl.viewport(0, 0, lienzo.width, lienzo.height); }
        },

        dibujar: function () {
            var gl = this.gl;
            if (!gl) { return; }

            var lienzo = gl.canvas;
            var razon = lienzo.width / Math.max(lienzo.height, 1);

            gl.uniform2f(gl.getUniformLocation(this.prog, 'aspecto'), razon, 1.0);
            gl.uniform1f(gl.getUniformLocation(this.prog, 'fov'), this.fov);
            gl.uniform2f(gl.getUniformLocation(this.prog, 'ang'), this.lon, this.lat);
            gl.drawArrays(gl.TRIANGLE_STRIP, 0, 4);

            this.dibujarMarcas();
        },

        girar: function (dx, dy) {
            this.lon -= dx * 0.005 * this.fov;
            // El tope evita darse vuelta por completo mirando hacia arriba,
            // que desorienta y no se puede deshacer con el pulgar.
            this.lat = Math.max(-1.45, Math.min(1.45, this.lat - dy * 0.005 * this.fov));
            this.dibujar();
        },

        acercar: function (factor) {
            this.fov = Math.max(0.35, Math.min(1.6, this.fov * factor));
            this.dibujar();
        },

        conectarGestos: function (lienzo) {
            var visor = this;

            lienzo.addEventListener('mousedown', function (e) {
                visor.arrastrando = true; visor.x = e.clientX; visor.y = e.clientY;
            });
            window.addEventListener('mouseup', function () { visor.arrastrando = false; });
            window.addEventListener('mousemove', function (e) {
                if (!visor.arrastrando) { return; }
                visor.girar(e.clientX - visor.x, e.clientY - visor.y);
                visor.x = e.clientX; visor.y = e.clientY;
            });

            lienzo.addEventListener('wheel', function (e) {
                e.preventDefault();
                visor.acercar(e.deltaY > 0 ? 1.1 : 0.9);
            }, { passive: false });

            lienzo.addEventListener('touchstart', function (e) {
                if (e.touches.length === 1) {
                    visor.x = e.touches[0].clientX; visor.y = e.touches[0].clientY;
                } else if (e.touches.length === 2) {
                    visor.pinza = visor.separacion(e.touches);
                }
            }, { passive: true });

            lienzo.addEventListener('touchmove', function (e) {
                e.preventDefault();

                if (e.touches.length === 1) {
                    visor.girar(e.touches[0].clientX - visor.x, e.touches[0].clientY - visor.y);
                    visor.x = e.touches[0].clientX; visor.y = e.touches[0].clientY;
                    return;
                }

                if (e.touches.length === 2 && visor.pinza > 0) {
                    var ahora = visor.separacion(e.touches);
                    visor.acercar(visor.pinza / Math.max(ahora, 1));
                    visor.pinza = ahora;
                }
            }, { passive: false });

            window.addEventListener('resize', function () {
                if (document.getElementById('visor360').hidden) { return; }
                visor.medir(); visor.dibujar();
            });
        },

        separacion: function (dedos) {
            var dx = dedos[0].clientX - dedos[1].clientX;
            var dy = dedos[0].clientY - dedos[1].clientY;
            return Math.sqrt(dx * dx + dy * dy);
        }
    };

    document.getElementById('visor360-cerrar').addEventListener('click', function () {
        Visor360.cerrar();
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !document.getElementById('visor360').hidden) {
            Visor360.cerrar();
        }
    });

    function prepararBoton360(lote) {
        var boton = document.getElementById('ficha-360');

        if (!lote.foto360) { boton.hidden = true; return; }

        boton.hidden = false;
        boton.onclick = function () { Visor360.abrir(lote); };
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
        prepararBoton360(lote);

        telon.setAttribute('data-abierto', '');
        recordar(lote);
    }

    function cerrar() {
        telon.removeAttribute('data-abierto');
        if (marcado) { marcado.classList.remove('activo'); marcado = null; }
        olvidar();
    }

    /*
     * ─── El enlace directo a un lote ──────────────────────────────────
     *
     * `.../praderas-del-sol#lote=12-A` abre la pagina con la ficha de ese
     * lote puesta y el plano centrado en el. Es para que un vendedor mande
     * «mira este» en vez de «abri el plano y busca el 12 de la A», que es
     * donde el cliente se pierde y cierra.
     *
     * La direccion se actualiza sola al abrir cualquier lote, asi que el que
     * copia de la barra del navegador se lleva el enlace correcto sin saber
     * que existe esta funcion.
     *
     * Se acepta el rotulo (12-A, 12A) y el codigo entero (RPS-A-012): los dos
     * circulan por WhatsApp y no hay razon para que uno sirva y el otro no.
     * Se compara solo letras y numeros, sin guiones ni mayusculas.
     */
    function normalizar(texto) {
        return String(texto || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
    }

    function etiquetaDe(lote) {
        return lote.numero + (lote.bloque ? '-' + lote.bloque : '');
    }

    function recordar(lote) {
        if (window.history && history.replaceState) {
            history.replaceState(null, '', '#lote=' + encodeURIComponent(etiquetaDe(lote)));
        }
    }

    function olvidar() {
        if (window.history && history.replaceState) {
            history.replaceState(null, '', location.pathname + location.search);
        }
    }

    function loteDelHash() {
        var hallado = /(?:^|[#&])lote=([^&]+)/.exec(location.hash || '');
        if (!hallado) { return null; }

        var busca = normalizar(decodeURIComponent(hallado[1]));
        if (!busca) { return null; }

        for (var id in porId) {
            if (!Object.prototype.hasOwnProperty.call(porId, id)) { continue; }

            var lote = porId[id];
            if (normalizar(lote.codigo) === busca || normalizar(lote.rotulo) === busca) {
                return lote;
            }
        }

        return null;
    }

    /*
     * El respaldo para copiar. `navigator.clipboard` solo existe en https o
     * en localhost: mientras el plano se sirva por http en una red local,
     * este es el unico camino que de verdad copia.
     */
    function copiarAMano(texto) {
        var caja = document.createElement('textarea');
        caja.value = texto;
        caja.setAttribute('readonly', '');
        caja.style.position = 'fixed';
        caja.style.left = '-9999px';
        document.body.appendChild(caja);
        caja.select();

        var listo = false;
        try { listo = document.execCommand('copy'); } catch (e) { listo = false; }

        document.body.removeChild(caja);
        return listo;
    }

    document.getElementById('cerrar').addEventListener('click', cerrar);
    document.getElementById('cerrar-pie').addEventListener('click', cerrar);
    telon.addEventListener('click', function (e) { if (e.target === telon) { cerrar(); } });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { cerrar(); } });

    var copiar = document.getElementById('copiar');

    copiar.addEventListener('click', function () {
        var texto = location.href;
        var original = copiar.textContent;

        var avisar = function (listo) {
            copiar.textContent = listo ? '¡Enlace copiado!' : 'Copialo de la barra de arriba';
            setTimeout(function () { copiar.textContent = original; }, 2500);
        };

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(texto).then(
                function () { avisar(true); },
                function () { avisar(copiarAMano(texto)); }
            );
            return;
        }

        avisar(copiarAMano(texto));
    });

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

    /*
     * El encuadre alrededor de un lote, para el enlace directo.
     *
     * ⚠️ El alto se deriva del ancho por la proporcion del encuadre inicial.
     * Un viewBox con otra proporcion que la del <svg> hace que
     * preserveAspectRatio="xMidYMid meet" agregue bandas, y el lote termina
     * corrido de donde uno lo puso.
     *
     * Y se deja aire alrededor a proposito —cinco veces el lote— porque un
     * lote solo a pantalla completa no dice en que parte del proyecto esta,
     * que es la mitad de lo que el cliente quiere saber.
     */
    function centrarEn(lote) {
        var p = puntosDe(lote.puntos);
        if (!p.length) { return; }

        var xs = p.map(function (q) { return q[0]; });
        var ys = p.map(function (q) { return q[1]; });
        var minX = Math.min.apply(null, xs), maxX = Math.max.apply(null, xs);
        var minY = Math.min.apply(null, ys), maxY = Math.max.apply(null, ys);

        var razon = inicial[3] / inicial[2];
        var ancho = Math.max(maxX - minX, (maxY - minY) / razon) * 5;

        // Los mismos topes que el zoom a dedo, o el plano quedaria en una
        // escala a la que ninguno de los botones puede volver.
        ancho = Math.min(Math.max(ancho, inicial[2] / 16), inicial[2] * 1.4);

        var alto = ancho * razon;

        vista = [(minX + maxX) / 2 - ancho / 2, (minY + maxY) / 2 - alto / 2, ancho, alto];
        aplicar();
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

    /*
     * Y lo ultimo: si el link venia con un lote, se abre solo.
     *
     * Va aca abajo y no arriba porque necesita `vista`, `inicial` y
     * `aplicar()`, que son del bloque del mapa. Centrar es cambiar el
     * encuadre del SVG, no hacer scroll de la pagina.
     */
    (function abrirElDelEnlace() {
        var lote = loteDelHash();

        if (!lote) { return; }

        var figura = mapa.querySelector('polygon[data-lote="' + lote.id + '"]');

        if (figura) {
            marcado = figura;
            figura.classList.add('activo');
        }

        centrarEn(lote);
        abrir(lote);

        // La pista de «pellizcá para acercar» sobra: el que llego por un
        // enlace directo ya tiene la ficha abierta encima.
        if (pista) { pista.style.opacity = '0'; }
    })();
})();
</script>
</body>
</html>
