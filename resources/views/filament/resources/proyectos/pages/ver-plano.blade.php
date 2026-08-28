@use('App\Domain\Enums\EstadoLote')
@use('App\Domain\ValueObjects\Monto')

<x-filament-panels::page>
    @php
        $resumen = $plano['resumen'];

        /*
        | 🔴 LAS OCHO TARJETAS SE FUERON — pedido de Mauricio, 23-ago-2026:
        | «no son necesarias esas cards ya que todo se ve en el plano».
        |
        | Y es cierto de los CONTEOS: el plano ya dice cuántos hay de cada
        | color, mirándolo. Ocupaban media pantalla arriba del mapa, que es
        | justo lo que uno vino a ver.
        |
        | ⚠️ Pero las tarjetas cargaban ADEMAS la leyenda de colores, y eso no
        | está en ninguna otra parte: sin ella, verde/azul/morado no significan
        | nada para quien abre el plano por primera vez. Por eso queda una
        | tira fina con el punto, la palabra y el número — la misma
        | información en una línea en vez de en dos filas de cajas.
        |
        | Y solo salen los estados QUE EXISTEN en este desarrollo: en Praderas
        | son tres, no ocho. Un contador en cero es un renglón que no informa.
        */
        $leyenda = array_values(array_filter([
            ['etiqueta' => 'Disponibles', 'valor' => $resumen['disponible'], 'color' => EstadoLote::Disponible->colorHex()],
            ['etiqueta' => 'Apartados',   'valor' => $resumen['apartado'],   'color' => EstadoLote::Apartado->colorHex()],
            ['etiqueta' => 'Vendidos',    'valor' => $resumen['vendido'],    'color' => EstadoLote::Vendido->colorHex()],
            ['etiqueta' => 'Reservados',  'valor' => $resumen['reservado'],  'color' => EstadoLote::Reservado->colorHex()],
            ['etiqueta' => 'Donados',     'valor' => $resumen['donado'],     'color' => EstadoLote::Donado->colorHex()],
            ['etiqueta' => 'Cancelados',  'valor' => $resumen['cancelado'],  'color' => EstadoLote::Cancelado->colorHex()],
        ], static fn (array $renglon): bool => $renglon['valor'] > 0));

        /*
        | Los tres numeros de R14, para proponerlos en el panel de apartar en
        | vez de preguntarlos en blanco. El monto pasa por Monto y no por
        | number_format: hasta para mostrarlo, el dinero no toca un float.
        */
        $diasDeApartado = (int) config('lotificadora.apartados.dias_de_vigencia', 15);
        $montoDeApartado = new Monto((string) config('lotificadora.apartados.monto', '0.00'));

        $apartado = [
            'monto'           => $montoDeApartado->redondeado(),
            'montoFormateado' => $montoDeApartado->formateado(),
            'dias'            => $diasDeApartado,
            'vence'           => today()->addDays($diasDeApartado)->format('d/m/Y'),
            'venceIso'        => today()->addDays($diasDeApartado)->toDateString(),
        ];
    @endphp

    {{--
        CSS propio y no clases de Tailwind: el panel sirve su bundle
        compilado y NO carga resources/css/app.css (no hay ->viteTheme() en
        AdminPanelProvider), asi que las utilidades que Filament no usa
        internamente no existirian y esto saldria sin estilos.
    --}}
    <style>
        .plano-card {
            border: 1px solid rgb(228 228 231); background: #fff;
            border-radius: .75rem; padding: 1rem;
        }
        .dark .plano-card { border-color: rgba(255, 255, 255, .1); background: rgb(24 24 27); }

        .plano-stat-punto { width: .625rem; height: .625rem; border-radius: 9999px; flex-shrink: 0; }

        /* ── La barra que reemplazo a las ocho tarjetas ───────────── */
        .plano-barra {
            display: flex; flex-wrap: wrap; align-items: center; gap: .75rem 1.25rem;
            justify-content: space-between;
        }

        .plano-buscar { position: relative; flex: 0 1 30rem; display: flex; align-items: center; }

        /* La lupa: sin ella el campo se leia como una barra decorativa. */
        .plano-buscar-lupa {
            position: absolute; left: .75rem; width: 1.05rem; height: 1.05rem;
            color: rgb(113 113 122); pointer-events: none;
        }
        .dark .plano-buscar-lupa { color: rgb(161 161 170); }

        .plano-buscar input {
            width: 100%; border: 1px solid rgb(212 212 216); background: #fff;
            border-radius: .625rem; padding: .5625rem 5rem .5625rem 2.25rem;
            font-size: .875rem; color: rgb(9 9 11);
            box-shadow: 0 1px 2px rgba(9, 9, 11, .06), inset 0 1px 0 rgba(9, 9, 11, .02);
        }
        .plano-buscar input::placeholder { color: rgb(113 113 122); }
        /* La ✕ nativa de type="search" se encimaba con «Limpiar». */
        .plano-buscar input::-webkit-search-cancel-button { -webkit-appearance: none; appearance: none; }
        .plano-buscar input:hover { border-color: rgb(161 161 170); }
        .plano-buscar input:focus {
            outline: none; border-color: rgb(13 148 136);
            box-shadow: 0 0 0 3px rgba(20, 184, 166, .18);
        }
        .dark .plano-buscar input {
            background: rgb(24 24 27); border-color: rgba(255, 255, 255, .18); color: #fff;
            box-shadow: none;
        }
        .dark .plano-buscar input::placeholder { color: rgb(161 161 170); }

        .plano-buscar button {
            position: absolute; right: .375rem; border: 0; background: transparent;
            font-size: .75rem; font-weight: 500; color: rgb(113 113 122);
            padding: .25rem .5rem; border-radius: .375rem; cursor: pointer;
        }
        .plano-buscar button:hover { background: rgb(244 244 245); color: rgb(9 9 11); }
        .dark .plano-buscar button:hover { background: rgba(255, 255, 255, .08); color: #fff; }

        .plano-leyenda { font-size: .75rem; color: rgb(113 113 122); }
        .dark .plano-leyenda { color: rgb(161 161 170); }
        .plano-chips { display: flex; flex-wrap: wrap; align-items: center; gap: .25rem 1rem; }
        .plano-chip { display: inline-flex; align-items: center; gap: .375rem; white-space: nowrap; }
        .plano-chip strong, .plano-hallazgo strong {
            font-variant-numeric: tabular-nums; color: rgb(9 9 11); font-weight: 600;
        }
        .dark .plano-chip strong, .dark .plano-hallazgo strong { color: #fff; }
        .plano-hallazgo { white-space: nowrap; }

        [x-cloak] { display: none !important; }

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
        /* El velo tapa de verdad: con .55 y 2px de desenfoque se seguian
           leyendo las tarjetas de arriba y el plano de abajo, y el modal
           parecia flotando sobre ruido. */
        .plano-modal {
            position: fixed; inset: 0; z-index: 50; display: flex;
            align-items: center; justify-content: center; padding: 1.5rem;
            background: rgba(9, 9, 11, .78); backdrop-filter: blur(8px);
        }
        .plano-modal-caja {
            width: min(74rem, 100%); max-height: min(92vh, 56rem);
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
        .plano-modal-codigo { font-size: 1.5rem; font-weight: 600; letter-spacing: -.01em; color: rgb(9 9 11); }
        .dark .plano-modal-codigo { color: #fff; }
        .plano-modal-sub { margin-top: .1875rem; font-size: .875rem; color: rgb(113 113 122); }
        .dark .plano-modal-sub { color: rgb(161 161 170); }
        .plano-modal-cerrar {
            border: 0; background: transparent; cursor: pointer; line-height: 1;
            font-size: 1.5rem; padding: 0 .25rem; color: rgb(113 113 122);
        }
        .plano-modal-cerrar:hover { color: rgb(9 9 11); }
        .dark .plano-modal-cerrar:hover { color: #fff; }

        .plano-modal-cuerpo {
            display: grid; grid-template-columns: 1fr; gap: 1.75rem;
            padding: 1.5rem; overflow-y: auto;
        }
        /* 1fr : 1.05fr y no 1.2 : 1 — el cuadro de planes tiene cuatro
           columnas de numeros y con menos lugar partia "Plan a 12 Meses" y
           los montos en dos renglones. */
        @media (min-width: 900px) { .plano-modal-cuerpo { grid-template-columns: 1fr 1.05fr; } }

        .plano-modal-dibujo {
            display: block; width: 100%; height: min(52vh, 26rem);
            background: rgb(250 250 250); border: 1px solid rgb(244 244 245);
            border-radius: .75rem;
        }
        .dark .plano-modal-dibujo { background: rgba(255, 255, 255, .04); border-color: rgba(255, 255, 255, .1); }
        .plano-modal-escala { margin-top: .625rem; font-size: .75rem; color: rgb(161 161 170); text-align: center; }

        .plano-badge { flex-shrink: 0; border-radius: .375rem; padding: .25rem .5rem; font-size: .75rem; font-weight: 500; color: #fff; }

        .plano-datos { font-size: .9375rem; }
        .plano-dato { display: flex; align-items: baseline; justify-content: space-between; gap: 1rem; padding: .5rem 0; border-bottom: 1px solid rgb(244 244 245); }
        .dark .plano-dato { border-bottom-color: rgba(255, 255, 255, .06); }
        .plano-dato dt { color: rgb(113 113 122); }
        .dark .plano-dato dt { color: rgb(161 161 170); }
        .plano-dato dd { font-variant-numeric: tabular-nums; color: rgb(9 9 11); margin: 0; font-weight: 500; }
        .dark .plano-dato dd { color: #fff; }
        .plano-dato-fuerte dd { font-size: 1.375rem; font-weight: 700; }

        .plano-acciones { margin-top: 1rem; }
        .plano-botonera { display: flex; gap: .5rem; flex-wrap: wrap; }
        .plano-accion {
            flex: 1 1 auto; border: 1px solid rgb(228 228 231); background: #fff;
            color: rgb(63 63 70); border-radius: .5rem; padding: .6875rem 1rem;
            font-size: .9375rem; font-weight: 600; cursor: pointer;
        }
        .plano-accion:hover { background: rgb(250 250 250); }
        .dark .plano-accion { border-color: rgba(255, 255, 255, .15); background: rgba(255, 255, 255, .05); color: rgb(228 228 231); }
        .dark .plano-accion:hover { background: rgba(255, 255, 255, .1); }
        .plano-accion-apartar { border-color: #d97706; color: #b45309; }
        .dark .plano-accion-apartar { border-color: rgba(217, 119, 6, .5); color: #fbbf24; }
        .plano-accion-vender { border-color: #2563eb; color: #1d4ed8; }
        .dark .plano-accion-vender { border-color: rgba(37, 99, 235, .5); color: #93c5fd; }
        /* El mismo verde azulado de EstadoLote::Donado, con borde punteado:
           donar no es el gesto de todos los dias y el boton no tiene por que
           pesar lo mismo que Vender. */
        .plano-accion-donar { border-style: dashed; border-color: #0d9488; color: #0f766e; }
        .dark .plano-accion-donar { border-color: rgba(13, 148, 136, .55); color: #5eead4; }
        /* Cobrar es el boton de todos los dias del lote vendido: lleno y no
           punteado, al reves que donar. Verde como la accion de Filament que
           monta, para que el color del boton y el del modal coincidan. */
        .plano-accion-cobrar { border-color: #16a34a; background: #f0fdf4; color: #15803d; font-weight: 600; }
        .dark .plano-accion-cobrar { border-color: rgba(22, 163, 74, .55); background: rgba(22, 163, 74, .12); color: #86efac; }

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
        .plano-prima { display: flex; align-items: center; gap: .5rem; margin: .75rem 0; font-size: .875rem; color: rgb(113 113 122); }
        .plano-prima input {
            width: 9rem; border: 1px solid rgb(228 228 231); border-radius: .5rem;
            padding: .3125rem .5rem; font-size: .8125rem; text-align: right;
            font-variant-numeric: tabular-nums; background: #fff; color: rgb(9 9 11);
        }
        .dark .plano-prima input { border-color: rgba(255, 255, 255, .15); background: rgba(255, 255, 255, .05); color: #fff; }
        .plano-tabla { width: 100%; border-collapse: collapse; font-size: .875rem; font-variant-numeric: tabular-nums; }
        .plano-tabla th {
            text-align: right; font-weight: 500; color: rgb(113 113 122);
            padding: .5rem .375rem; border-bottom: 1px solid rgb(228 228 231);
            white-space: nowrap;
        }
        .dark .plano-tabla th { color: rgb(161 161 170); border-bottom-color: rgba(255, 255, 255, .1); }
        .plano-tabla th:first-child, .plano-tabla td:first-child { text-align: left; }
        .plano-tabla td { padding: .5rem .375rem; border-bottom: 1px solid rgb(250 250 250); text-align: right; color: rgb(9 9 11); white-space: nowrap; }
        .dark .plano-tabla td { border-bottom-color: rgba(255, 255, 255, .06); color: rgb(228 228 231); }
        .plano-tabla td.cuota { font-weight: 600; }
        .plano-tabla td.cuota small {
            display: block; font-weight: 400; font-size: .6875rem;
            color: rgb(161 161 170); letter-spacing: 0; line-height: 1.35;
            /* 🔴 El `td` va en `nowrap` para que los números no se partan, y
               el mensaje HEREDA eso: uno largo estira la tabla hasta que el
               modal se desborda a la derecha y tapa el área y el valor. Acá
               se le devuelve el wrap y se le pone techo. */
            white-space: normal; max-width: 15rem; margin-left: auto;
        }
        .plano-planes-nota { margin-top: .625rem; font-size: .75rem; line-height: 1.6; color: rgb(161 161 170); }

        /* El mismo conmutador que las pestañas de la ficha del proyecto. */
        /* width: fit-content + margin auto = la pastilla centrada sin
           estirarse. Con inline-flex a secas se pegaba a la izquierda. */
        .plano-toggle {
            display: flex; gap: .25rem; width: fit-content;
            margin: .25rem auto 1rem;
            padding: .25rem; border-radius: .625rem;
            background: rgb(244 244 245); border: 1px solid rgb(228 228 231);
        }
        .dark .plano-toggle { background: rgba(255, 255, 255, .05); border-color: rgba(255, 255, 255, .1); }
        .plano-toggle button {
            border: 0; background: transparent; cursor: pointer;
            border-radius: .5rem; padding: .5rem 1.5rem; min-width: 9rem;
            font-size: .875rem; font-weight: 500; color: rgb(113 113 122);
        }
        .plano-toggle button:hover { color: rgb(9 9 11); }
        .dark .plano-toggle button:hover { color: #fff; }
        .plano-toggle button.activo {
            background: #fff; color: rgb(180 83 9); font-weight: 600;
            box-shadow: 0 1px 2px rgba(0, 0, 0, .06);
        }
        .dark .plano-toggle button.activo { background: rgb(39 39 42); color: #fbbf24; }

        .plano-panel { margin-top: .25rem; }

        /* Campos con la etiqueta ARRIBA y la caja completa como zona de
           foco: dos <label> sueltos en fila quedaban de anchos distintos y
           se leian como un formulario a medio hacer. */
        .plano-campos { display: grid; grid-template-columns: 1fr 1fr; gap: .875rem; }
        .plano-campo { display: flex; flex-direction: column; gap: .375rem; }
        .plano-campo > span { font-size: .8125rem; font-weight: 500; color: rgb(113 113 122); }
        .dark .plano-campo > span { color: rgb(161 161 170); }
        .plano-campo-caja {
            display: flex; align-items: center; gap: .375rem;
            border: 1px solid rgb(228 228 231); border-radius: .5rem;
            padding: 0 .625rem; background: #fff;
        }
        .dark .plano-campo-caja { border-color: rgba(255, 255, 255, .15); background: rgba(255, 255, 255, .05); }
        .plano-campo-caja:focus-within { border-color: #d97706; box-shadow: 0 0 0 3px rgba(217, 119, 6, .12); }
        .plano-campo-prefijo { font-size: .875rem; color: rgb(161 161 170); }
        .plano-campo-caja input {
            flex: 1; min-width: 0; border: 0; outline: none; background: transparent;
            padding: .5625rem 0; font-size: .9375rem; color: rgb(9 9 11);
            font-variant-numeric: tabular-nums;
        }
        .dark .plano-campo-caja input { color: #fff; }

        .plano-terminos {
            margin: 1rem 0 0; padding: .875rem 1rem .875rem 2rem;
            border-radius: .625rem; background: rgb(250 250 250);
            font-size: .8125rem; line-height: 1.7; color: rgb(82 82 91);
        }
        .dark .plano-terminos { background: rgba(255, 255, 255, .04); color: rgb(212 212 216); }
        .plano-terminos li { margin: 0; }
        .plano-terminos strong { color: rgb(9 9 11); font-weight: 600; }
        .dark .plano-terminos strong { color: #fff; }
        .plano-accion-ancha { display: block; width: 100%; margin-top: 1rem; }
        .plano-fila-plan { cursor: pointer; }
        .plano-fila-plan:hover td { background: rgb(250 250 250); }
        .dark .plano-fila-plan:hover td { background: rgba(255, 255, 255, .04); }
        .plano-fila-plan.elegido td { background: rgb(239 246 255); }
        .dark .plano-fila-plan.elegido td { background: rgba(59, 130, 246, .12); }
        .plano-precio {
            width: 6.75rem; border: 1px solid rgb(228 228 231); border-radius: .375rem;
            padding: .3125rem .5rem; font-size: .875rem; text-align: right;
            font-variant-numeric: tabular-nums; background: #fff; color: rgb(9 9 11);
        }
        .plano-precio.tocado { border-color: #d97706; color: #b45309; font-weight: 600; }
        .dark .plano-precio { border-color: rgba(255, 255, 255, .15); background: rgba(255, 255, 255, .05); color: #fff; }

        /* La barra del contrato en armado. Flota abajo a la derecha del
           lienzo: no tapa el centro —que es donde estan los lotes— y se lee
           sin sacar la vista del mapa. */
        .plano-carrito {
            position: absolute; right: .75rem; bottom: .75rem; z-index: 5;
            display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;
            max-width: min(40rem, calc(100% - 1.5rem));
            border: 1px solid #f59e0b; border-radius: .75rem;
            background: rgba(255, 255, 255, .96); backdrop-filter: blur(6px);
            padding: .75rem .875rem; box-shadow: 0 10px 30px rgba(9, 9, 11, .18);
        }
        .dark .plano-carrito { border-color: rgba(245, 158, 11, .55); background: rgba(24, 24, 27, .94); }
        .plano-carrito-titulo { font-size: .875rem; font-weight: 700; color: rgb(9 9 11); }
        .dark .plano-carrito-titulo { color: #fff; }
        .plano-carrito-detalle { font-size: .8125rem; color: rgb(82 82 91); font-variant-numeric: tabular-nums; }
        .dark .plano-carrito-detalle { color: rgb(212 212 216); }
        .plano-carrito-codigos {
            font-size: .6875rem; color: rgb(113 113 122); margin-top: .125rem; max-width: 24rem;
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }
        .dark .plano-carrito-codigos { color: rgb(161 161 170); }
        .plano-carrito-botones { display: flex; gap: .5rem; margin-left: auto; }
        .plano-carrito-botones .plano-accion { flex: 0 0 auto; white-space: nowrap; }
        .plano-marcado { border-color: #f59e0b; color: #b45309; }
        .dark .plano-marcado { border-color: rgba(245, 158, 11, .55); color: #fbbf24; }
        .plano-nota-contrato {
            margin-top: 1rem; border: 1px dashed #f59e0b; border-radius: .625rem;
            padding: .625rem .75rem; font-size: .8125rem; line-height: 1.6;
            color: rgb(146 64 14); background: rgba(245, 158, 11, .08);
        }
        .dark .plano-nota-contrato { color: #fcd34d; }

        /* La escalera de cuotas. Cada renglon es un tramo de meses que se
           pagan igual; con todos los lotes al mismo plazo hay uno solo. */
        .plano-tramos { margin-top: .375rem; display: grid; gap: .0625rem; }
        .plano-tramos li {
            display: flex; justify-content: space-between; gap: 1.25rem;
            font-size: .8125rem; color: rgb(82 82 91); font-variant-numeric: tabular-nums;
        }
        .dark .plano-tramos li { color: rgb(212 212 216); }
        .plano-tramos strong { color: rgb(9 9 11); font-weight: 700; }
        .dark .plano-tramos strong { color: #fff; }

        .plano-accion:disabled { opacity: .45; cursor: not-allowed; }
        .plano-accion:disabled:hover { background: #fff; }
        .dark .plano-accion:disabled:hover { background: rgba(255, 255, 255, .05); }

    </style>

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

                /* En que unidad se muestran las medidas de ESTE proyecto.
                   Los poligonos estan siempre en varas: `factor` es 1 o
                   los metros que mide la vara del desarrollo. */
                medidas: @js($plano['medidas']),
                donaciones: @js($plano['donaciones']),
                herencia: @js($plano['herencia']),

                base: @js($plano['viewBox']).split(' ').map(Number),
                vista: { x: 0, y: 0, w: 1, h: 1 },
                seleccionado: null,
                abierto: false,
                prima: '',

                /* ── El buscador del plano (23-ago-2026) ──────────────
                   Se busca contra CUATRO campos porque son las cuatro
                   maneras en que alguien nombra un lote de memoria: por el
                   codigo (RPS-Q-003), por el rotulo del plano (Q-3), por
                   quien lo tiene, y por el numero de contrato. */
                busqueda: '',

                get hayBusqueda() {
                    return this.busqueda.trim() !== '';
                },


                /* 🔴 Ni mayusculas ni tildes — pedido de Mauricio, 23-ago-2026.
                   Los nombres de la cartera vieja vienen del cuaderno y estan
                   escritos como salio: MARIA, María, MARÍA. Quien busca teclea
                   como se le ocurre. NFD parte cada letra acentuada en letra +
                   marca, y la marca se borra: «María» y «MARIA» se vuelven la
                   misma cadena antes de comparar. */
                sinAdornos(texto) {
                    return texto
                        .normalize('NFD')
                        .replace(/[\u0300-\u036f]/g, '')
                        .toLowerCase()
                        .trim();
                },

                coincide(lote) {
                    const q = this.sinAdornos(this.busqueda);

                    if (q === '') {
                        return true;
                    }

                    return [lote.codigo, lote.rotulo, lote.cliente, lote.cartera?.contrato]
                        .some((campo) => typeof campo === 'string' && this.sinAdornos(campo).includes(q));
                },

                /* Los que NO coinciden se ATENUAN, no se esconden. Esconderlos
                   dejaria tres poligonos flotando en blanco y el plano perderia
                   lo unico que un plano aporta: DONDE queda cada uno. Asi se ve
                   que los tres lotes del señor estan pegados en el bloque Q. */
                atenuado(lote) {
                    return this.hayBusqueda && ! this.coincide(lote);
                },

                get encontrados() {
                    return this.lotes.filter((lote) => this.coincide(lote)).length;
                },

                /* Lo que se cotiza en el modal viaja al formulario de venta:
                   el plazo elegido y, si se toco, el precio de esa fila. */
                plazoElegido: null,
                preciosTocados: {},
                tasasTocadas: {},

                /* El cuadro de plazos, tal como lo devolvio el servidor.
                   Ya no se calcula aca: ver el comentario de recalcular(). */
                filas: [],
                cotizando: false,
                pedido: 0,
                reloj: null,

                /* El contrato en armado: los lotes marcados para firmarse
                   JUNTOS, en un solo expediente. Se guarda lo minimo que la
                   barra necesita —id, codigo y area—; el estado fresco de
                   cada lote sigue viniendo de los data-* del poligono. */
                carrito: [],

                /* Que pestaña del modal se esta mirando: 'vender' o
                   'apartar'. Se reinicia con cada lote. */
                modo: 'vender',
                senia: '',
                venceEl: '',

                /* Los terminos de R14, para proponerlos en vez de dejar los
                   campos en blanco. Editables: si un dia se recibe otra
                   cantidad, se anota la que se recibio. */
                apartado: @js($apartado),
                arrastrando: false,
                movio: false,
                inicio: { x: 0, y: 0, vx: 0, vy: 0 },

                /* Calco del plano original. Se pide aparte y no embebido en
                   la pagina porque pesa ~1.5 MB: asi lo cachea el navegador
                   y no viaja en cada render de Livewire. Si falla, no pasa
                   nada: los lotes se dibujan igual. */
                calco: { obra: '', rotulo: '', textos: [] },

                /* 🔴 Arranca segun SI HAY calco, no en true a secas.
                   Mordio el 13-ago-2026 con El Bambu: el boton que apaga
                   el calco solo se dibuja cuando el proyecto TIENE calco
                   —ver la condicion de mas abajo, al lado de «Ampliar»—,
                   asi que en un proyecto sin calco verCalco se quedaba en
                   true para siempre y los rotulos de los 84 lotes —que
                   estan dibujados, con display:none— no habia forma de
                   encenderlos. Sin calco no hay nada que tapar: los
                   numeros se ven de entrada.

                   ⚠️ Y ojo con lo que se escribe ACA ADENTRO: este
                   comentario es de JavaScript, pero el archivo pasa
                   primero por Blade. Una directiva citada de ejemplo se
                   COMPILA igual, y una condicional sin su cierre revienta
                   la vista entera con «unexpected end of file». Mordio en
                   el mismo cambio: 30 tests en rojo por una cita. */
                verCalco: @js($plano['calco'] !== null),
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

                    this.modo = 'vender';

                    /*
                       ═══ CADA LOTE TIENE SUS PROPIAS CONDICIONES ═══

                       Un contrato puede llevar el primer lote a 12 meses, el
                       segundo a 24 y el tercero a 48. Asi que el plazo, el
                       precio y la prima son DEL LOTE, no de la pantalla: al
                       abrir uno ya marcado se restaura lo que se le puso, y al
                       abrir uno nuevo se arranca en blanco.

                       Y NADA viene preseleccionado. Un plazo por defecto es un
                       plazo que alguien puede firmar sin haberlo mirado, y de
                       ese plazo depende el precio de la vara².
                    */
                    const marcado = this.carrito.find((l) => l.id === this.seleccionado.id);

                    this.plazoElegido = marcado ? marcado.plazo : null;
                    this.prima = marcado ? marcado.prima : '';
                    this.preciosTocados = marcado && marcado.precio !== null
                        ? { [marcado.plazo]: marcado.precio }
                        : {};
                    this.tasasTocadas = marcado && marcado.tasa
                        ? { [marcado.plazo]: marcado.tasa }
                        : {};

                    this.filas = [];
                    this.recalcular();

                    /* La seña y el vencimiento SI son del tramite entero: los
                       tres los fijo la contratante y son iguales para todos
                       los lotes (R14). */
                    if (this.carrito.length === 0) {
                        this.senia = this.apartado.monto;
                        this.venceEl = this.apartado.venceIso;
                    }

                    this.abierto = true;
                },

                cerrar() { this.abierto = false },

                enCarrito(id) { return this.carrito.some((l) => l.id === id) },

                /* Sumar o quitar el lote abierto. Cierra el modal: el gesto
                   siguiente es marcar otro lote, no quedarse mirando este. */
                alternarCarrito() {
                    const lote = this.seleccionado;

                    if (! lote) return;

                    if (this.enCarrito(lote.id)) {
                        this.carrito = this.carrito.filter((l) => l.id !== lote.id);
                        this.abierto = false;

                        return;
                    }

                    /* Sin plazo elegido no hay nada que marcar: el precio de la
                       vara² DEPENDE del plazo, asi que un lote sin plazo es un
                       lote sin precio. El boton esta deshabilitado, esto es el
                       cinturon. */
                    if (! this.hayPlan) return;

                    const plan = this.planes.find((p) => p.meses === this.plazoElegido);

                    this.carrito.push({
                        id: lote.id,
                        codigo: lote.codigo,
                        area: Number(String(lote.areaVaras).replace(/,/g, '')) || 0,
                        plazo: this.plazoElegido,
                        etiqueta: plan ? (plan.etiqueta || (plan.meses > 0 ? `${plan.meses} meses` : 'Contado')) : '—',
                        precio: plan ? this.precioDe(plan).toFixed(2) : null,
                        /* Vacio es «la del plan»: se guarda solo lo que se
                           negocio, no lo que ya estaba. */
                        tasa: plan ? this.tasaDe(plan) : '',
                        prima: this.prima || '',
                    });

                    this.abierto = false;
                },

                /* ¿Hay con qué armar un contrato? (24-ago-2026)
                   🔴 Un plan con precio CERO no es un plan: es el estado en
                   el que queda un desarrollo al que todavía no le cargaron la
                   lista de precios. Contarlo como plan hacía que el cuadro
                   dibujara la tabla con las cinco filas en L 0.00 y el mismo
                   error repetido en cada una — y ese mensaje largo, con los
                   `td` en `nowrap`, estiraba la tabla hasta partir el modal.
                   Con esto, un lote sin precio se ve igual que uno sin planes:
                   el aviso de qué falta y el botón apagado. */
                get hayPrecio() {
                    return this.planes.some((p) => Number(p.precioVara) > 0);
                },

                /* ¿Hay un plazo elegido para el lote abierto? */
                get hayPlan() {
                    return this.hayPrecio && this.plazoElegido !== null;
                },

                vaciarCarrito() { this.carrito = [] },

                /* Lo que suma el contrato, con el plan que quedo marcado.
                   RENGLON POR RENGLON y en centavos enteros: es la misma
                   cuenta que hace RegistroDeVentas::congelarPrecios() del
                   lado del servidor. Sumar primero las areas da un centavo
                   distinto en cuanto dos lotes tengan fracciones. */
                get resumenCarrito() {
                    let area = 0;
                    let centavos = 0;
                    let primas = 0;

                    for (const lote of this.carrito) {
                        area += lote.area;
                        centavos += Math.round(lote.area * Number(lote.precio || 0) * 100);
                        primas += Math.max(0, Math.round((Number(lote.prima) || 0) * 100));
                    }

                    const tramos = this.tramos;

                    return {
                        lotes: this.carrito.length,
                        area: this.numero(area),
                        total: this.lempiras(centavos),
                        prima: this.lempiras(primas),
                        tramos,
                        // La primera es la mas alta: es lo que paga mientras
                        // todos los lotes siguen vivos.
                        primeraCuota: tramos.length > 0 ? tramos[0].monto : null,
                        plazoMaximo: this.carrito.reduce((mayor, l) => Math.max(mayor, l.plazo || 0), 0),
                    };
                },

                /*
                   ═══ LOS TRAMOS ═══

                   Con un lote a 12 meses, otro a 24 y otro a 48, la pregunta
                   «¿cuanto pago por mes?» NO tiene una sola respuesta: paga los
                   tres juntos hasta el mes 12, dos hasta el 24 y uno hasta el
                   48. Contestar con el primer numero a secas seria exacto por
                   doce meses y falso por treinta y seis.

                   Se agrupan los meses consecutivos que se pagan igual. Es el
                   mismo calculo que hace PlanDelContrato del lado del servidor,
                   que es el que despues persiste.
                */
                get tramos() {
                    /* La cuota de cada lote, en centavos. El residuo del
                       redondeo lo reparte PlanDeCuotas en la ULTIMA cuota; aca
                       se muestra la primera, que es la que se repite. */
                    const cuotas = this.carrito.map((lote) => {
                        const meses = Number(lote.plazo) || 0;

                        if (meses <= 0) return { meses: 0, cuota: 0 };

                        const valor = Math.round(lote.area * Number(lote.precio || 0) * 100);
                        const prima = Math.max(0, Math.round((Number(lote.prima) || 0) * 100));
                        const saldo = Math.max(valor - prima, 0);

                        return { meses, cuota: Math.round(saldo / meses) };
                    });

                    const plazo = cuotas.reduce((mayor, c) => Math.max(mayor, c.meses), 0);

                    if (plazo === 0) return [];

                    const delMes = (mes) => cuotas.reduce((suma, c) => suma + (mes <= c.meses ? c.cuota : 0), 0);

                    const tramos = [];
                    let desde = 1;
                    let monto = delMes(1);

                    for (let mes = 2; mes <= plazo; mes++) {
                        const ahora = delMes(mes);

                        if (ahora === monto) continue;

                        tramos.push({ desde, hasta: mes - 1, monto: this.lempiras(monto), centavos: monto });
                        desde = mes;
                        monto = ahora;
                    }

                    tramos.push({ desde, hasta: plazo, monto: this.lempiras(monto), centavos: monto });

                    return tramos;
                },

                /* Lo mismo, para apartar los marcados. La seña es POR LOTE:
                   son N compromisos, cada uno con el suyo, y los N cuentan
                   despues como parte de la prima. */
                get reservaDelGrupo() {
                    const senia = Number(this.senia);

                    return {
                        lote: this.carrito[0].id,
                        extra: this.carrito.slice(1).map((l) => l.id),
                        senia: Number.isFinite(senia) && senia > 0 ? senia.toFixed(2) : null,
                        vence: this.venceEl || null,
                    };
                },

                /* La seña de todos los marcados, para que el vendedor sepa
                   cuanto esta por cobrar antes de apretar. */
                get seniaDelGrupo() {
                    const senia = Math.max(0, Math.round((Number(this.senia) || 0) * 100));

                    return this.lempiras(senia * this.carrito.length);
                },

                /* El contrato entero para la accion de vender: encabeza el
                   primero que se marco —es el que va a abrir el expediente—
                   y el resto viaja en `extra`. */
                get contrato() {
                    return {
                        lote: this.carrito[0].id,
                        // Cada lote con LO SUYO. El servidor arma un plan de
                        // cuotas por renglon y los suma en tramos.
                        condiciones: this.carrito.map((l) => ({
                            lote: l.id,
                            plazo: l.plazo,
                            precio: l.precio,
                            prima: Number(l.prima) > 0 ? Number(l.prima).toFixed(2) : '0.00',
                        })),
                    };
                },

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

                    /*
                       El centro es el CENTROIDE DE AREA, no el promedio de
                       los vertices, y es la misma cuenta que hace
                       GeometriaPlana::centroide() del lado de PHP.

                       Un promedio pondera por CUANTOS vertices hay, no por
                       donde estan: la pared curva de un lote de esquina
                       llega teselada en decenas de vertices y se lleva el
                       promedio con ella. De ahi salian las dos cosas que se
                       veian mal el 25-ago-2026: el area escrita contra un
                       lindero en vez del medio, y las normales de las cotas
                       -que se orientan mirando este punto- apuntando para
                       adentro del lote.
                    */
                    let doble = 0;
                    let sumaX = 0;
                    let sumaY = 0;

                    for (let i = 0; i < puntos.length; i++) {
                        const [x1, y1] = puntos[i];
                        const [x2, y2] = puntos[(i + 1) % puntos.length];
                        const cruz = (x1 * y2) - (x2 * y1);

                        doble += cruz;
                        sumaX += (x1 + x2) * cruz;
                        sumaY += (y1 + y2) * cruz;
                    }

                    // Sin area no hay centro de masa: ahi vale el promedio,
                    // que al menos es un punto del dibujo.
                    const sinArea = Math.abs(doble) < 1e-9;
                    const cx = sinArea ? xs.reduce((a, b) => a + b, 0) / xs.length : sumaX / (3 * doble);
                    const cy = sinArea ? ys.reduce((a, b) => a + b, 0) / ys.length : sumaY / (3 * doble);

                    const diagonal = Math.hypot(maxX - minX, maxY - minY) || 1;
                    const separacion = diagonal * 0.055;
                    const fuente = diagonal * 0.05;

                    /*
                       UN LADO CURVO ES UN LADO, NO TREINTA Y CUATRO.

                       ⚠️ Todo esto vive adentro del ATRIBUTO x-data, que
                       va entre comillas dobles. Por eso ni un comentario
                       puede llevar una: cierra el atributo y la pagina sale
                       en blanco. Para citar algo se usan « ».

                       El contorno llega con los arcos ya teselados a 3 grados
                       por segmento -GeometriaPlana::GRADOS_POR_SEGMENTO-, asi
                       que la esquina redondeada de radio 20 de un lote de
                       Altamira son ~35 segmentos de 91 cm. Cotados uno por
                       uno tapaban el croquis con decenas de «0.91 m» que
                       ademas se salian del recuadro (lote RAL-E-008,
                       25-ago-2026).

                       Los segmentos que giran menos de 10 grados entre si son
                       el MISMO lado: se juntan en un tramo y se cota su
                       DESARROLLO, que es el numero que el topografo escribe
                       sobre un arco. Medido sobre los dos planos: el peor
                       lote de Altamira pasa de 34 cotas a 3, y los 309 de
                       Praderas del Sol no se mueven. Diez grados y no mas:
                       con quince empiezan a fundirse linderos quebrados de
                       verdad.
                    */
                    const GIRO_DEL_MISMO_LADO = 10 * Math.PI / 180;
                    const tramos = [];

                    for (let i = 0; i < puntos.length; i++) {
                        const a = puntos[i];
                        const b = puntos[(i + 1) % puntos.length];
                        const largo = Math.hypot(b[0] - a[0], b[1] - a[1]);

                        if (largo < 1e-9) continue;

                        const rumbo = Math.atan2(b[1] - a[1], b[0] - a[0]);
                        const ultimo = tramos[tramos.length - 1];

                        if (ultimo && Math.abs(this.giroEntre(ultimo.rumbo, rumbo)) < GIRO_DEL_MISMO_LADO) {
                            ultimo.largo += largo;
                            ultimo.rumbo = rumbo;
                            ultimo.vertices.push(b);

                            continue;
                        }

                        tramos.push({ largo, rumbo, arranque: rumbo, vertices: [a, b] });
                    }

                    /*
                       El contorno es cerrado y puede haber empezado A LA
                       MITAD de un arco: entonces el ultimo tramo y el
                       primero son el mismo lado y hay que unirlos, o el
                       croquis muestra el arco partido en dos cotas.
                    */
                    if (tramos.length > 1) {
                        const cola = tramos[tramos.length - 1];

                        if (Math.abs(this.giroEntre(cola.rumbo, tramos[0].arranque)) < GIRO_DEL_MISMO_LADO) {
                            tramos.pop();
                            tramos[0].largo += cola.largo;
                            tramos[0].vertices = cola.vertices.concat(tramos[0].vertices.slice(1));
                        }
                    }

                    const lados = [];

                    for (const tramo of tramos) {
                        // Un lado de menos de 2% de la diagonal es ruido del
                        // trazado, no una medida que alguien vaya a cotar.
                        if (tramo.largo < diagonal * 0.02) continue;

                        /*
                           La etiqueta va a MITAD DEL DESARROLLO, caminando el
                           tramo vertice por vertice. En un arco el medio de
                           la cuerda cae por adentro del lote y la cota
                           terminaria escrita encima del dibujo.
                        */
                        let recorrido = 0;
                        let mx = tramo.vertices[0][0];
                        let my = tramo.vertices[0][1];
                        let dx = 1;
                        let dy = 0;

                        for (let i = 0; i < tramo.vertices.length - 1; i++) {
                            const desde = tramo.vertices[i];
                            const hasta = tramo.vertices[i + 1];
                            const paso = Math.hypot(hasta[0] - desde[0], hasta[1] - desde[1]);

                            dx = hasta[0] - desde[0];
                            dy = hasta[1] - desde[1];

                            if (recorrido + paso >= tramo.largo / 2 || i === tramo.vertices.length - 2) {
                                const t = paso > 0 ? Math.min(1, Math.max(0, (tramo.largo / 2 - recorrido) / paso)) : 0;

                                mx = desde[0] + dx * t;
                                my = desde[1] + dy * t;

                                break;
                            }

                            recorrido += paso;
                        }

                        const tangente = Math.hypot(dx, dy) || 1;

                        // Normal unitaria, girada hacia AFUERA: si apunta
                        // hacia el centro del lote se invierte.
                        let nx = -dy / tangente;
                        let ny = dx / tangente;

                        if ((mx - cx) * nx + (my - cy) * ny < 0) { nx = -nx; ny = -ny }

                        // El texto se lee siempre de izquierda a derecha:
                        // pasados los 90 grados se voltea media vuelta.
                        let angulo = Math.atan2(dy, dx) * 180 / Math.PI;

                        if (angulo > 90) angulo -= 180;
                        if (angulo < -90) angulo += 180;

                        lados.push({
                            a: tramo.vertices[0],
                            b: tramo.vertices[tramo.vertices.length - 1],
                            mx,
                            my,
                            nx,
                            ny,
                            largo: tramo.largo,
                            angulo,
                            extra: 0,
                            texto: this.cota(tramo.largo),
                        });
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
                        // en las fuentes de palo seco que usa el panel. Se
                        // mide el TEXTO ya armado —«25.05 m» y «29.99 V» no
                        // ocupan lo mismo— o los choques se calculan contra
                        // una etiqueta que no es la que se va a dibujar.
                        const ancho = (lado.texto.length + 1) * fuente * 0.55;
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
                /**
                 * Cuanto gira el rumbo `b` respecto del `a`, en radianes y
                 * normalizado al rango [-PI, PI].
                 *
                 * Sin normalizar, dos segmentos casi identicos que cruzan el
                 * corte de atan2 -uno a 179 grados y el otro a -179- se leen
                 * como un giro de 358 grados en vez de 2, y el arco se corta
                 * ahi en dos cotas.
                 */
                giroEntre(a, b) {
                    let giro = b - a;

                    while (giro > Math.PI) giro -= 2 * Math.PI;
                    while (giro < -Math.PI) giro += 2 * Math.PI;

                    return giro;
                },

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
                        texto.textContent = lado.texto;
                        g.appendChild(texto);
                    }
                },

                /*
                   🔴 EL CUADRO DE PLAZOS LO CALCULA EL SERVIDOR.

                   Hasta el 9-ago-2026 se calculaba aca: valor dividido entre
                   los meses. Mientras ningun plan cobraba interes daba el
                   numero correcto y nadie lo noto. El dia que un plan de
                   Praderas quedo al 12 % anual, esta pantalla —la que el
                   vendedor le muestra al cliente— decia L 54,166.67 donde el
                   contrato iba a decir L 57,751.71.

                   Y no se arregla escribiendo la formula francesa aca: en
                   JavaScript solo hay float, que es lo que el §8.3.1 prohibe
                   en el camino del dinero.

                   Cuesta un ida y vuelta corto por cada tecla. Vale la pena:
                   el numero que se dice en voz alta es el que sale impreso.

                   `pedido` descarta las respuestas viejas. Escribiendo rapido
                   salen varias consultas y no vuelven en orden; sin esto, la
                   respuesta de «26» puede pisar a la de «2600».
                */
                async recalcular() {
                    if (! this.seleccionado || ! this.hayPrecio) {
                        this.filas = [];
                        return;
                    }

                    const mio = ++this.pedido;
                    this.cotizando = true;

                    try {
                        const filas = await $wire.cotizar({
                            lote: this.seleccionado.id,
                            prima: this.prima,
                            precios: this.preciosTocados,
                            tasas: this.tasasTocadas,
                        });

                        if (mio === this.pedido) { this.filas = filas; }
                    } catch (e) {
                        /* Sin conexion el cuadro queda como estaba, que es
                           mejor que vaciarse sin decir por que. */
                    } finally {
                        if (mio === this.pedido) { this.cotizando = false; }
                    }
                },

                /* Se espera a que deje de escribir. Un pedido por tecla
                   sobre una casilla de precio son seis viajes para nada. */
                recalcularPronto() {
                    clearTimeout(this.reloj);
                    this.reloj = setTimeout(() => this.recalcular(), 350);
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

                /* Lo que se le pasa a la accion de apartar. Vacio significa
                   lo de siempre, y el formulario pone los numeros de R14. */
                get reserva() {
                    const senia = Number(this.senia);

                    return {
                        lote: this.seleccionado.id,
                        senia: Number.isFinite(senia) && senia > 0 ? senia.toFixed(2) : null,
                        vence: this.venceEl || null,
                    };
                },

                /* El precio que manda para un plan: el que se tecleo en la
                   fila, o el de la lista si no se toco ninguno. */
                precioDe(plan) {
                    const tocado = Number(this.preciosTocados[plan.meses]);

                    return Number.isFinite(tocado) && tocado > 0 ? tocado : Number(plan.precioVara);
                },

                /* Lo que se le pasa a la accion de vender. El formulario llega
                   con esto puesto y de ahi se puede seguir cambiando: el
                   servidor vuelve a validar todo, esto es solo el arranque. */
                get cotizacion() {
                    const plan = this.planes.find((p) => p.meses === this.plazoElegido);
                    const prima = Number(this.prima);
                    const precio = plan ? this.precioDe(plan).toFixed(2) : null;
                    const enMano = Number.isFinite(prima) && prima > 0 ? prima.toFixed(2) : '0.00';

                    /* La tasa NEGOCIADA, o vacio si no se toco. Vacio
                       significa «la del plan»: mandar la de lista igual
                       serviria, pero el dia que la administracion la cambie
                       entre cotizar y firmar, el formulario llegaria con la
                       vieja y sin que nadie lo pidiera. */
                    const tasa = plan ? this.tasaDe(plan) : '';

                    return {
                        lote: this.seleccionado.id,
                        plazo: this.plazoElegido,
                        precio,
                        prima: enMano,
                        tasa,
                        condiciones: [{
                            lote: this.seleccionado.id,
                            plazo: this.plazoElegido,
                            precio,
                            prima: enMano,
                            tasa,
                        }],
                    };
                },

                /* Lo que se tecleo en la fila de la tasa, o vacio. */
                tasaDe(plan) {
                    const tocada = String(this.tasasTocadas[plan.meses] ?? '').trim();

                    return tocada !== '' && Number.isFinite(Number(tocada)) ? tocada : '';
                },

                /* ¿Hay algo que cobrar en concepto de interés?

                   Praderas vende hoy los cinco plazos sin interés, y una columna con
                   cinco ceros no es información: es una casilla vacía que el vendedor
                   tiene que descartar con la vista cada vez que abre el cuadro, con el
                   cliente enfrente. Si ningún plazo cobra, la columna no está.

                   No se pierde nada: la columna existe para NEGOCIAR HACIA ABAJO, y
                   desde cero no hay para dónde bajar. El día que la administración le
                   ponga tasa a un plan, la columna vuelve sola.

                   Se miran las dos tasas —la de lista y la tecleada— y no solo la de
                   lista: un lote que ya está en el carrito con una tasa pactada la
                   restaura al reabrirlo, y esa tasa hay que poder verla. */
get hayInteres() {
                    return this.filas.some((f) => Number(f.tasa) > 0 || Number(f.tasaLista) > 0);
                },

                get areaFormateada() {
                    const crudo = String(this.seleccionado?.areaVaras ?? '').replace(/,/g, '');
                    const area = Number(crudo);

                    return Number.isFinite(area) ? this.numero(area) : crudo;
                },

                /* La cota de un lado, en la unidad que eligio el proyecto.

                   Convertir ACA y no en la base es lo que permite cambiar el
                   ajuste sin tocar un solo poligono: la geometria se guarda
                   en varas para siempre, porque en varas² es como se cobra. */
                cota(enVaras) {
                    return `${(enVaras * this.medidas.factor).toFixed(2)} ${this.medidas.unidad}`;
                },

                /* El area con las DOS unidades cuando el proyecto se
                   cobra en varas² y el plano viene acotado en metros,
                   igual que la rotula el topografo (A=320.19m2 /
                   459.22v2). La unidad con la que se VENDE nunca
                   desaparece.

                   En un proyecto que ya trabaja en metros², `dosUnidades`
                   viene en false: poner los m² al lado seria escribir el
                   mismo numero dos veces. */
                get areaConUnidades() {
                    const propia = `${this.areaFormateada} ${this.medidas.areaCorta}`;
                    const metros = this.seleccionado?.areaMetros;

                    return this.medidas.dosUnidades && this.medidas.enMetros && metros
                        ? `${propia} · ${metros} m²`
                        : propia;
                },
            }"
            :class="completo ? 'plano-grid plano-completo' : 'plano-grid'"
            x-on:keydown.escape.window="if (abierto) { cerrar() } else { completo = false }"
        >
        {{-- 🔴 EL BUSCADOR (23-ago-2026). Todo pasa en el navegador: los lotes ya
             viajan enteros al Alpine —con su codigo, su rotulo, su cliente y su
             contrato—, asi que buscar no toca el servidor y el plano responde
             mientras se teclea. Un ida y vuelta por letra sobre 309 lotes seria
             mas lento que leerlos a ojo. --}}
            <div class="plano-barra">
                <div class="plano-buscar">
                    <svg class="plano-buscar-lupa" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
                        <circle cx="9" cy="9" r="6"></circle>
                        <path d="M13.5 13.5 L17.5 17.5" stroke-linecap="round"></path>
                    </svg>
                    <input
                        type="search"
                        x-model="busqueda"
                        placeholder="Buscar por cliente, código de lote o contrato…"
                        aria-label="Buscar en el plano"
                        x-on:keydown.escape.stop="busqueda = ''"
                    >
                    <button type="button" x-show="hayBusqueda" x-on:click="busqueda = ''" x-cloak>Limpiar</button>
                </div>

                <div class="plano-leyenda">
                    {{-- Buscando, el conteo por estado no sirve: lo que importa es
                         cuantos quedaron encendidos. --}}
                    <span x-show="hayBusqueda" x-cloak class="plano-hallazgo">
                        <strong x-text="encontrados"></strong>
                        <span x-text="encontrados === 1 ? 'lote' : 'lotes'"></span>
                        de {{ count($plano['lotes']) }}
                    </span>

                    <span x-show="! hayBusqueda" class="plano-chips">
                        @foreach ($leyenda as $renglon)
                            <span class="plano-chip">
                                <span class="plano-stat-punto" style="background: {{ $renglon['color'] }}"></span>
                                {{ $renglon['etiqueta'] }}
                                <strong>{{ $renglon['valor'] }}</strong>
                            </span>
                        @endforeach
                    </span>
                </div>
            </div>

            {{-- ⚠️ ESTE SI SE QUEDA, y por eso no era una tarjeta mas: un lote sin
                 dibujar es EXACTAMENTE el que no se puede ver en el plano. Es el
                 caso de la manzana I del 22-ago, donde faltaban ocho y ningun
                 control lo atrapaba. Sale solo cuando hay alguno. --}}
            @if ($plano['sinDibujar'] > 0)
                <div class="plano-esquema">
                    <strong>{{ $plano['sinDibujar'] }} {{ $plano['sinDibujar'] === 1 ? 'lote cargado no está dibujado' : 'lotes cargados no están dibujados' }}.</strong>
                    No aparecen en el plano y por eso no se pueden ver ni buscar acá — se venden igual desde Ventas.
                    Para dibujarlos, «Acomodar plano».
                </div>
            @endif

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
                            :fill-opacity="atenuado(lotes[{{ $indice }}]) ? 0.07 : 0.78"
                            stroke-linejoin="round"
                            vector-effect="non-scaling-stroke"
                            class="lote"
                            data-indice="{{ $indice }}"
                            data-estado="{{ $lote['estado'] }}"
                            data-etiqueta="{{ $lote['etiqueta'] }}"
                            data-color="{{ $lote['color'] }}"
                            data-cliente="{{ $lote['cliente'] }}"
                            :stroke="enCarrito({{ $lote['id'] }})
                                ? '#f59e0b'
                                : (seleccionado?.id === {{ $lote['id'] }} ? '#0f172a' : '#ffffff')"
                            :stroke-width="enCarrito({{ $lote['id'] }})
                                ? 3
                                : (seleccionado?.id === {{ $lote['id'] }} ? 2.5 : 1)"
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

                         Van con la letra del bloque adelante —B-12— porque
                         en un plano de 24 manzanas un "12" solo no dice de
                         cual es, el codigo entero (RPS-B-012) no entra, y
                         asi se lee igual que como lo dice la oficina.

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

                {{-- El contrato en armado.

                     Solo aparece con lotes marcados: una barra vacia
                     flotando sobre el plano es ruido. Y es el UNICO lugar
                     desde donde se firma cuando hay varios, para que no
                     existan dos botones que hacen cosas distintas con el
                     mismo nombre. --}}
                <template x-if="carrito.length > 0">
                    <div class="plano-carrito">
                        <div>
                            <p class="plano-carrito-titulo">
                                <span x-text="resumenCarrito.lotes"></span>
                                <span x-text="resumenCarrito.lotes === 1 ? 'lote marcado' : 'lotes marcados'"></span>
                            </p>

                            <p class="plano-carrito-detalle">
                                <span x-text="resumenCarrito.area"></span> <span x-text="medidas.areaCorta"></span> ·
                                <span x-text="resumenCarrito.total"></span> ·
                                prima <span x-text="resumenCarrito.prima"></span>
                            </p>

                            {{-- La escalera. Cuando el lote de 12 meses se
                                 termina de pagar, a partir del mes 13 es una
                                 cuota menos — y eso hay que poder decirselo al
                                 cliente antes de firmar, no despues. --}}
                            <ul class="plano-tramos">
                                <template x-for="tramo in resumenCarrito.tramos" :key="tramo.desde">
                                    <li>
                                        <span x-text="tramo.desde === tramo.hasta
                                            ? `mes ${tramo.desde}`
                                            : `meses ${tramo.desde} al ${tramo.hasta}`"></span>
                                        <strong x-text="tramo.monto"></strong>
                                    </li>
                                </template>
                            </ul>

                            <p class="plano-carrito-codigos"
                               x-text="carrito.map((l) => `${l.codigo} (${l.etiqueta})`).join(' · ')"></p>
                        </div>

                        <div class="plano-carrito-botones">
                            <button type="button" class="plano-accion" x-on:click="vaciarCarrito()">Vaciar</button>

                            {{-- Apartar y vender salen de la MISMA seleccion: se
                                 marcan los lotes una vez y recien al final se
                                 decide que se hace con ellos. --}}
                            <button
                                type="button"
                                class="plano-accion plano-accion-apartar"
                                x-on:click="$wire.mountAction('apartarLote', reservaDelGrupo); abierto = false"
                                :title="`Seña de ${seniaDelGrupo} en total, una por lote`"
                            >
                                Apartar <span x-text="resumenCarrito.lotes === 1 ? 'el lote' : `los ${resumenCarrito.lotes}`"></span>
                            </button>

                            <button
                                type="button"
                                class="plano-accion plano-accion-vender"
                                x-on:click="$wire.mountAction('venderLote', contrato); abierto = false"
                            >Firmar el contrato</button>
                        </div>
                    </div>
                </template>
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
                                    wire:ignore
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
                                    {{-- La vara² arriba y los m² abajo, en dos
                                         renglones y no en uno: es como lo rotula el
                                         topografo (A=320.19m2 / 459.22v2), y en una
                                         sola linea el texto se sale de los lotes
                                         angostos y se monta con las cotas. --}}
                                    <text
                                        x-show="figura"
                                        :x="figura?.cx"
                                        :y="figura?.cy"
                                        text-anchor="middle"
                                        dominant-baseline="central"
                                        :font-size="figura ? figura.fuente * 1.35 : 1"
                                        font-weight="600"
                                        fill="#3f3f46"
                                    >
                                        <tspan
                                            :x="figura?.cx"
                                            :dy="medidas.enMetros ? -(figura?.fuente ?? 0) * 0.7 : 0"
                                            x-text="`${areaFormateada} ${medidas.areaCorta}`"
                                        ></tspan>
                                        <tspan
                                            x-show="medidas.dosUnidades && medidas.enMetros && seleccionado?.areaMetros"
                                            :x="figura?.cx"
                                            :dy="(figura?.fuente ?? 0) * 1.5"
                                            :font-size="figura ? figura.fuente : 1"
                                            font-weight="500"
                                            fill="#71717a"
                                            x-text="`${seleccionado?.areaMetros ?? ''} m²`"
                                        ></tspan>
                                    </text>

                                    <g x-effect="dibujarCotas($el)"></g>
                                </svg>

                                <p class="plano-modal-escala">{{ $plano['medidas']['pie'] }}</p>
                            </div>

                            <div>
                                <dl class="plano-datos">
                                    <div class="plano-dato">
                                        <dt>Área</dt>
                                        <dd x-text="areaConUnidades"></dd>
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

                                    {{-- La cartera del CONTRATO, no la del lote, y por eso
                                         se dice con esas palabras. Un contrato puede llevar
                                         varios lotes y el recibo los cubre a todos; poner
                                         aca un saldo del lote, al lado de un boton que cobra
                                         el contrato, seria dar dos numeros que no quieren
                                         decir lo mismo. Ver CarteraDelPlano. --}}
                                    <template x-if="seleccionado.cartera">
                                        <div class="plano-dato plano-dato-fuerte">
                                            <dt>Saldo del contrato</dt>
                                            <dd x-text="seleccionado.cartera.saldo"></dd>
                                        </div>
                                    </template>
                                    <template x-if="seleccionado.cartera && seleccionado.cartera.proximaCuota">
                                        <div class="plano-dato">
                                            <dt>Próxima cuota</dt>
                                            <dd x-text="seleccionado.cartera.proximaCuota"></dd>
                                        </div>
                                    </template>
                                    <template x-if="seleccionado.cartera && seleccionado.cartera.lotes > 1">
                                        <div class="plano-dato">
                                            <dt>Contrato</dt>
                                            <dd x-text="seleccionado.cartera.contrato + ' · ' + seleccionado.cartera.lotes + ' lotes'"></dd>
                                        </div>
                                    </template>
                                </dl>

                                {{-- El atraso NO es un dato mas de la lista: es lo unico
                                     que cambia la conversacion con el cliente que esta
                                     enfrente, asi que sale del <dl> y se lee solo. --}}
                                <template x-if="seleccionado.cartera && ! seleccionado.cartera.alDia">
                                    <p class="plano-aviso"
                                       x-text="seleccionado.cartera.vencidas === 1
                                           ? 'Tiene 1 cuota vencida.'
                                           : 'Tiene ' + seleccionado.cartera.vencidas + ' cuotas vencidas.'"></p>
                                </template>

                                {{--
                                    Vender y Apartar como PESTAÑAS, no como dos
                                    botones sueltos: el mismo conmutador que
                                    Bloques / Lotes / Planes de pago en la ficha
                                    del proyecto.

                                    Cada pestaña muestra lo suyo y termina en un
                                    boton que monta la accion de Filament con lo
                                    que se cotizo. El modal se cierra en el acto:
                                    el de Filament se monta al final del <body> y
                                    no tiene por que pelear por quien va encima.
                                --}}
                                <template x-if="seleccionado.seVende">
                                    <div>
                                        <div class="plano-toggle">
                                            <button
                                                type="button"
                                                :class="modo === 'vender' ? 'activo' : ''"
                                                x-on:click="modo = 'vender'"
                                                x-text="seleccionado.estado === 'apartado' ? 'Convertir en venta' : 'Vender'"
                                            ></button>
                                            <button
                                                type="button"
                                                :class="modo === 'apartar' ? 'activo' : ''"
                                                x-on:click="modo = 'apartar'"
                                                x-text="seleccionado.estado === 'apartado' ? 'Liberar' : 'Apartar'"
                                            ></button>
                                        </div>

                                        {{-- ── Vender ──────────────────────────── --}}
                                        <template x-if="modo === 'vender'">
                                            <div class="plano-panel">
                                                <template x-if="! hayPrecio">
                                                    <p class="plano-planes-nota">
                                                        Falta cargar el precio <span x-text="medidas.porUnidad"></span> de cada plazo. Se cargan en el
                                                        proyecto, pestaña «Planes de pago»; en cuanto haya uno, este
                                                        cuadro calcula la cuota de cada plan sobre este lote.
                                                        <strong>Hasta entonces este lote no se puede vender</strong> —no
                                                        hay precio ni plazo con que armar el contrato—, pero sí se puede
                                                        <strong>apartar</strong>: la seña lo reserva sin fijar precio y
                                                        después cuenta como parte de la prima.
                                                    </p>
                                                </template>

                                                <template x-if="hayPrecio">
                                                    <div>
                                                        <label class="plano-prima">
                                                            Prima
                                                            <input type="number" min="0" step="0.01" placeholder="0.00"
                                                                   x-model="prima" x-on:input="recalcularPronto()">
                                                            L
                                                        </label>

                                                        {{-- El plazo se ELIGE acá y el precio se puede tocar
                                                             acá: lo que quede marcado viaja al formulario de
                                                             venta ya puesto. El servidor lo revalida igual —
                                                             esto es el arranque, no la última palabra. --}}
                                                        <table class="plano-tabla" :style="cotizando ? 'opacity:.55' : ''">
                                                            <thead>
                                                                <tr>
                                                                    <th></th>
                                                                    <th>Plazo</th>
                                                                    <th>Precio <span x-text="medidas.areaCorta"></span></th>
                                                                    <th x-show="hayInteres">Interés</th>
                                                                    <th>Valor</th>
                                                                    <th>Cuota</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody>
                                                                <template x-for="fila in filas" :key="fila.meses">
                                                                    <tr
                                                                        class="plano-fila-plan"
                                                                        :class="fila.meses === plazoElegido ? 'elegido' : ''"
                                                                        x-on:click="plazoElegido = fila.meses"
                                                                    >
                                                                        <td>
                                                                            <input
                                                                                type="radio"
                                                                                :value="fila.meses"
                                                                                :checked="fila.meses === plazoElegido"
                                                                                x-on:change="plazoElegido = fila.meses"
                                                                            >
                                                                        </td>
                                                                        <td x-text="fila.etiqueta"></td>
                                                                        <td>
                                                                            <input
                                                                                type="number"
                                                                                min="0"
                                                                                step="0.01"
                                                                                class="plano-precio"
                                                                                :class="fila.rebajado ? 'tocado' : ''"
                                                                                :placeholder="fila.precioLista"
                                                                                :value="preciosTocados[fila.meses] ?? ''"
                                                                                x-on:click.stop
                                                                                x-on:input="preciosTocados[fila.meses] = $event.target.value; recalcularPronto()"
                                                                            >
                                                                        </td>
                                                                        {{-- El precio del DINERO, editable igual que el del
                                                                             terreno. De contado no hay interés que cobrar. --}}
                                                                        <td x-show="hayInteres">
                                                                            <template x-if="fila.meses > 0">
                                                                                <input
                                                                                    type="number"
                                                                                    min="0"
                                                                                    step="0.001"
                                                                                    class="plano-precio"
                                                                                    :class="fila.rebajada ? 'tocado' : ''"
                                                                                    :placeholder="fila.tasaLista"
                                                                                    :value="tasasTocadas[fila.meses] ?? ''"
                                                                                    x-on:click.stop
                                                                                    x-on:input="tasasTocadas[fila.meses] = $event.target.value; recalcularPronto()"
                                                                                >
                                                                            </template>
                                                                            <template x-if="fila.meses === 0">
                                                                                <span style="color:rgb(161 161 170)">—</span>
                                                                            </template>
                                                                        </td>
                                                                        <td x-text="fila.valor"></td>
                                                                        <td class="cuota">
                                                                            <span x-text="fila.cuota ?? '—'"></span>
                                                                            {{-- El desglose del interés NO va acá, y no es un olvido.
                                                                                 «+L 43,020.56 de intereses» al lado de la cuota es el mismo
                                                                                 dinero que ya está adentro de la cuota, dicho dos veces, y la
                                                                                 segunda vez asusta: al cliente que mira la pantalla por encima
                                                                                 del hombro, y al vendedor que tiene que explicarlo de pie. El
                                                                                 total con intereses vive en el plan de cuotas del contrato, que
                                                                                 es donde se firma. --}}
                                                                            <template x-if="fila.error">
                                                                                <small x-text="fila.error"></small>
                                                                            </template>
                                                                        </td>
                                                                    </tr>
                                                                </template>
                                                            </tbody>
                                                        </table>

                                                        <p class="plano-planes-nota">
                                                            Marcá el plazo con el que se va a vender.
                                                            <span x-show="hayInteres">El precio y el interés se pueden cambiar
                                                                acá mismo; vacío es lo que ofrece el plan, y si bajás alguno de
                                                                los dos el sistema va a pedir el motivo por escrito.</span>
                                                            <span x-show="! hayInteres">El precio se puede cambiar acá mismo;
                                                                vacío es lo que ofrece el plan, y si lo bajás el sistema va a
                                                                pedir el motivo por escrito.</span>
                                                            La cuota sale del mismo motor que firma el contrato: es la que va a
                                                            salir impresa.
                                                        </p>
                                                    </div>
                                                </template>

                                                {{-- Con un contrato en armado este boton NO se muestra:
                                                     firmar solo este lote dejaria los otros marcados
                                                     colgados y sin aviso. Cuando hay varios, se firma
                                                     desde la barra, que es la unica que sabe cuantos
                                                     son y cuanto suman. --}}
                                                <template x-if="carrito.length === 0">
                                                    {{-- 🔴 SIN PLANES NO SE VENDE (23-ago-2026, pedido de
                                                         Mauricio). La condicion vieja era
                                                         `planes.length > 0 && ! hayPlan`, que con CERO planes
                                                         da FALSE: el boton quedaba habilitado justo en el unico
                                                         caso donde no hay ni precio por vara ni plazo con que
                                                         armar el contrato. Se vendia tecleando todo a mano.
                                                         Apartar no se toca: reserva sin fijar precio.

                                                         ⚠️ 24-ago-2026: la condicion pasa a `hayPrecio`. Un
                                                         plan con precio CERO contaba como plan y volvia a
                                                         habilitar el boton en el mismo caso que esto vino a
                                                         cerrar. --}}
                                                    <button
                                                        type="button"
                                                        class="plano-accion plano-accion-vender plano-accion-ancha"
                                                        :disabled="! hayPrecio || ! hayPlan"
                                                        x-on:click="$wire.mountAction('venderLote', cotizacion); abierto = false"
                                                        x-text="! hayPrecio
                                                            ? 'Sin plan de pago no se puede vender'
                                                            : (! hayPlan
                                                                ? 'Marcá primero un plazo'
                                                                : (seleccionado.estado === 'apartado' ? 'Convertir en venta' : 'Vender este lote'))"
                                                    ></button>
                                                </template>

                                            </div>
                                        </template>

                                        {{-- ── Apartar / Liberar ───────────────── --}}
                                        <template x-if="modo === 'apartar'">
                                            <div class="plano-panel">
                                                <template x-if="seleccionado.estado === 'apartado'">
                                                    <div>
                                                        <p class="plano-planes-nota">
                                                            El lote vuelve a quedar disponible. El apartado queda en el
                                                            historial con su motivo, que se pide al confirmar.
                                                        </p>

                                                        <button
                                                            type="button"
                                                            class="plano-accion plano-accion-ancha"
                                                            x-on:click="$wire.mountAction('liberarLote', { lote: seleccionado.id }); abierto = false"
                                                        >
                                                            Liberar el apartado
                                                        </button>
                                                    </div>
                                                </template>

                                                <template x-if="seleccionado.estado !== 'apartado'">
                                                    <div>
                                                        {{-- Los campos llegan LLENOS con los términos que fijó
                                                             la contratante (R14). Editables igual: si un día se
                                                             recibe otra cantidad, se anota la que se recibió. --}}
                                                        <div class="plano-campos">
                                                            <label class="plano-campo">
                                                                <span>Monto del apartado</span>
                                                                <div class="plano-campo-caja">
                                                                    <span class="plano-campo-prefijo">L</span>
                                                                    <input type="number" min="0" step="0.01" x-model="senia">
                                                                </div>
                                                            </label>

                                                            <label class="plano-campo">
                                                                <span>Vence el</span>
                                                                <div class="plano-campo-caja">
                                                                    <input type="date" x-model="venceEl">
                                                                </div>
                                                            </label>
                                                        </div>

                                                        <ul class="plano-terminos">
                                                            <li>
                                                                Se reserva a nombre del cliente y se puede liberar
                                                                después sin consecuencias.
                                                            </li>
                                                            <li>
                                                                El monto <strong>cuenta como parte de la prima</strong>
                                                                cuando la venta se firme.
                                                            </li>
                                                            <li>
                                                                Si vence sin que el cliente vuelva, el lote se libera y
                                                                <strong>el dinero se devuelve</strong>.
                                                            </li>
                                                        </ul>

                                                        <template x-if="carrito.length === 0">
                                                            <button
                                                                type="button"
                                                                class="plano-accion plano-accion-apartar plano-accion-ancha"
                                                                x-on:click="$wire.mountAction('apartarLote', reserva); abierto = false"
                                                            >
                                                                Apartar este lote
                                                            </button>
                                                        </template>
                                                    </div>
                                                </template>
                                            </div>
                                        </template>

                                        {{-- ── Marcar ──────────────────────────────

                                             Fuera de las dos pestañas a proposito:
                                             marcar lotes es UNA sola cosa, y recien
                                             al final se decide si el grupo se vende
                                             o se aparta. Tenerlo adentro de «Vender»
                                             obligaria a entrar por ahi para armar un
                                             apartado de tres lotes.

                                             Sobre un mapa, marcar y seguir clickeando
                                             es el gesto natural; buscar los lotes en
                                             una lista de 301 no lo es. --}}
                                        <template x-if="carrito.length > 0">
                                            <p class="plano-nota-contrato">
                                                Tenés <strong x-text="carrito.length"></strong>
                                                <span x-text="carrito.length === 1 ? 'lote marcado' : 'lotes marcados'"></span>,
                                                cada uno con <strong>su propio plazo</strong>. Se venden o se apartan
                                                desde la barra de abajo.
                                            </p>
                                        </template>

                                        {{-- Marcar SIN plazo no se puede: el precio de la vara²
                                             depende del plazo, asi que un lote sin plazo es un
                                             lote sin precio. Y no hay ninguno por defecto —un
                                             plazo puesto solo es un plazo que alguien firma sin
                                             haberlo mirado. --}}
                                        <button
                                            type="button"
                                            class="plano-accion plano-accion-ancha"
                                            :class="enCarrito(seleccionado.id) ? 'plano-marcado' : ''"
                                            :disabled="! enCarrito(seleccionado.id) && ! hayPlan"
                                            x-on:click="alternarCarrito()"
                                            x-text="enCarrito(seleccionado.id)
                                                ? 'Quitar de la selección'
                                                : (! hayPlan
                                                    ? 'Marcá el plazo de este lote para sumarlo'
                                                    : (carrito.length > 0 ? 'Sumar a la selección' : 'Marcar y seguir eligiendo lotes'))"
                                        ></button>
                                    </div>
                                </template>

                                {{-- Cobrar sin salir del plano. Pedido de Mauricio el
                                     13-ago-2026: quien cobra abre el plano, no la lista de
                                     ventas, y mandarlo a navegar le hace perder de vista el
                                     lote que tenia en pantalla.

                                     El modal que se monta es el MISMO de la tabla de Ventas
                                     y del expediente —CobrarUnPago—, no una copia. Se manda
                                     el lote y del otro lado se sube a su contrato.

                                     `seCobra` es que la venta este vigente: una liquidada o
                                     rescindida no recibe dinero.

                                     🔴 Y hay UN SOLO boton (23-ago-2026, pedido de Mauricio:
                                     «solo deberia mostrar registrar un pago ya que al
                                     presionarlo se puede hacer abono a capital»). Al lado
                                     vivia «Abonar a capital», que era de cuando el modal
                                     cobraba una sola cosa. Desde el 10-ago el modal abre con
                                     los cuatro modos adentro —cuota, abono, ambas, pronto
                                     pago— y ofrecer uno de ellos aparte era la misma puerta
                                     dibujada dos veces: quien la tomaba entraba al mismo
                                     modal con una opcion MENOS. R21 no se pierde: el toggle
                                     de adentro ya dibuja solo los modos que ese usuario
                                     puede, y el servidor lo verifica igual. --}}
                                <template x-if="seleccionado.cartera && seleccionado.cartera.seCobra">
                                    <div class="plano-acciones">
                                        <button
                                            type="button"
                                            class="plano-accion plano-accion-ancha plano-accion-cobrar"
                                            x-on:click="$wire.mountAction('cobrarDesdeElPlano', { lote: seleccionado.id }); abierto = false"
                                        >
                                            Registrar un pago
                                        </button>

                                        <button
                                            type="button"
                                            class="plano-accion plano-accion-ancha"
                                            x-on:click="$wire.abrirExpediente(seleccionado.cartera.venta)"
                                        >
                                            Abrir el expediente
                                        </button>
                                    </div>
                                </template>

                                <template x-if="! seleccionado.seVende">
                                    <p class="plano-detalle-vacio" x-text="seleccionado.porQueNoSeVende"></p>
                                </template>

                                {{-- Donar vive FUERA del conmutador Vender/Apartar y eso es
                                     a proposito. Esas dos son el mismo gesto en dos tiempos
                                     —hay alguien que paga— y por eso comparten la seleccion
                                     de lotes y la cotizacion. Una donacion no cotiza nada, es
                                     de un lote y pasa una vez cada varios meses: metida entre
                                     las pestañas solo estaria un click mas cerca del error.

                                     Aparece para los DISPONIBLES y para los RESERVADOS, que
                                     es el camino normal —el lote se guarda mientras corre el
                                     tramite y se dona cuando se firma la escritura—. Lo
                                     decide seDona(), en el enum, no este blade.

                                     Y ademas mientras QUEDEN donaciones por hacer: cada
                                     desarrollo declara cuantos lotes va a regalar y el boton
                                     desaparece solo al cumplirse el cupo (13-ago-2026). Las
                                     dos condiciones se calculan afuera; aca solo se leen. --}}
                                <template x-if="seleccionado.seDona && donaciones.puede">
                                    <button
                                        type="button"
                                        class="plano-accion plano-accion-ancha plano-accion-donar"
                                        x-on:click="$wire.mountAction('donarLote', { lote: seleccionado.id }); abierto = false"
                                    >
                                        Donar este lote
                                    </button>
                                </template>

                                {{-- Corregir una donacion mal registrada.

                                     No dice «devolver» a proposito: no se deshace
                                     una entrega que ocurrio, se le saca la marca a
                                     un lote que nunca se regalo. Lo decide
                                     seDeshaceLaDonacion(), en el enum. --}}
                                <template x-if="seleccionado.seDeshace">
                                    <button
                                        type="button"
                                        class="plano-accion plano-accion-ancha"
                                        x-on:click="$wire.mountAction('deshacerDonacion', { lote: seleccionado.id }); abierto = false"
                                    >
                                        Quitar de donación
                                    </button>
                                </template>

                                {{-- Guardar el lote para la familia.

                                     El gemelo del boton de donar y con la misma
                                     mecanica: aparece solo para los DISPONIBLES
                                     —lo decide seReserva(), en el enum— y mientras
                                     QUEDEN lotes por guardar del cupo que declaro
                                     el desarrollo. Las dos condiciones se calculan
                                     afuera; aca solo se leen.

                                     Dice «herencia» y no «reservar» porque es lo
                                     que es, y porque quien lee esta pantalla
                                     trabaja adentro. El cliente que abre el plano
                                     publico sigue leyendo «Reservado». --}}
                                <template x-if="seleccionado.seReserva && herencia.puede">
                                    <button
                                        type="button"
                                        class="plano-accion plano-accion-ancha"
                                        x-on:click="$wire.mountAction('reservarLote', { lote: seleccionado.id }); abierto = false"
                                    >
                                        Guardar para herencia
                                    </button>
                                </template>

                                {{-- Y la vuelta: el lote guardado que se pone a la
                                     venta. Lo decide seDeshaceLaReserva(). --}}
                                <template x-if="seleccionado.seDeshaceReserva">
                                    <button
                                        type="button"
                                        class="plano-accion plano-accion-ancha"
                                        x-on:click="$wire.mountAction('deshacerReserva', { lote: seleccionado.id }); abierto = false"
                                    >
                                        Devolver a la venta
                                    </button>
                                </template>

                                <template x-if="seleccionado.desalineado">
                                    <p class="plano-aviso">
                                        El dibujo de este lote no coincide con su área cargada. Manda el área del plano
                                        legal — el polígono solo está avisando que alguien tiene que mirarlo.
                                    </p>
                                </template>

                            </div>
                        </div>
                    </div>
                </div>
            </template>
        </div>
    @endif
</x-filament-panels::page>
