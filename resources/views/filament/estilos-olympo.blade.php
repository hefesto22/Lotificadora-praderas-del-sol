{{--
    Los estilos de los cuadros que arma PHP.

    ═══ POR QUE NO SON UTILIDADES DE TAILWIND ═══

    El CSS de Filament se compila aparte y NO ve las clases que arma un
    `HtmlString` del lado de PHP: Tailwind purga lo que no encuentra en los
    archivos que escanea, y una tabla escrita con `py-1.5 text-right` sale sin
    un solo margen. Pasó, y no hay forma de darse cuenta hasta abrir el modal.

    ═══ POR QUE EN EL PANEL Y NO EN CADA PAGINA ═══

    La misma tabla de lotes y la misma escalera de cuotas se ven en el modal
    del plano y en la ficha del expediente. Vivían en el <style> del plano, o
    sea que la ficha las mostraba sin estilos. Ahora las inyecta el panel una
    vez, en el <head>, y las dos pantallas dicen lo mismo.
--}}
<style>
    /*
       ── El cuadro de lotes y la escalera del formulario de venta ──

       Viven acá, con clases propias, por el mismo motivo que dice el
       comentario de arriba: el CSS de Filament se compila aparte y NO ve
       las clases que arma un HtmlString del lado de PHP. Escribirlas como
       utilidades de Tailwind las deja sin un solo margen —pasó— y no hay
       forma de darse cuenta hasta abrir el modal.

       El modal de Filament se monta al final del <body>, pero de ESTA
       página: estas reglas lo alcanzan igual.
    */
    /*
       ── El envoltorio que impide que un número quede recortado ──

       La tarjeta de Filament no ofrece scroll: recorta. Con siete columnas
       `nowrap`, en cuanto la tarjeta se angosta —el modal del plano, una
       pantalla chica, el panel en dos columnas— el último valor se ve
       «L. 54,1» y no hay forma de darse cuenta desde el código.

       `auto`, no `scroll`: cuando entra —que es lo normal— no se ve nada.
    */
    .olympo-scroll { overflow-x: auto; overscroll-behavior-x: contain; }

    .olympo-tabla { width: 100%; border-collapse: collapse; font-size: .8125rem; }
    .olympo-tabla th {
        padding: 0 .625rem .5rem; text-align: right; white-space: nowrap;
        font-size: .6875rem; font-weight: 500; letter-spacing: .04em; text-transform: uppercase;
        color: rgb(113 113 122); border-bottom: 1px solid rgb(228 228 231);
    }
    .olympo-tabla td {
        padding: .5625rem .625rem; text-align: right; white-space: nowrap;
        font-variant-numeric: tabular-nums; color: rgb(63 63 70);
        border-bottom: 1px dashed rgb(228 228 231);
    }
    .olympo-tabla th:first-child, .olympo-tabla td:first-child { text-align: left; padding-left: 0; }
    .olympo-tabla th:last-child, .olympo-tabla td:last-child { padding-right: 0; }
    .olympo-tabla tr:last-child td { border-bottom: 0; }
    .olympo-tabla .lote { font-weight: 600; color: rgb(9 9 11); }
    .olympo-tabla .fuerte { font-weight: 700; color: rgb(9 9 11); }
    .olympo-tabla .apagado { color: rgb(161 161 170); }
    .dark .olympo-tabla th { color: rgb(161 161 170); border-bottom-color: rgba(255, 255, 255, .14); }
    .dark .olympo-tabla td { color: rgb(212 212 216); border-bottom-color: rgba(255, 255, 255, .08); }
    .dark .olympo-tabla .lote, .dark .olympo-tabla .fuerte { color: #fff; }

    .olympo-pill {
        display: inline-block; padding: .1875rem .5rem; border-radius: 9999px;
        font-size: .6875rem; font-weight: 600; white-space: nowrap; line-height: 1;
        background: rgb(244 244 245); color: rgb(63 63 70);
    }
    .dark .olympo-pill { background: rgba(255, 255, 255, .09); color: rgb(228 228 231); }
    .olympo-pill.contado { background: rgba(22, 163, 74, .12); color: #15803d; }
    .dark .olympo-pill.contado { background: rgba(22, 163, 74, .22); color: #86efac; }

    /* El tope de ancho es para leerla: son dos datos por renglón, uno a cada
       orilla, y estirados a lo ancho de la página el ojo pierde el par. En
       los modales —2xl, 3xl— no llega a aplicar. */
    .olympo-escalera { display: grid; gap: .3125rem; max-width: 42rem; }
    .olympo-escalera li {
        display: flex; align-items: baseline; justify-content: space-between; gap: 1.5rem;
        padding: .5rem .75rem; border-radius: .5rem; font-size: .875rem;
        background: rgb(250 250 250); border: 1px solid rgb(244 244 245);
    }
    .dark .olympo-escalera li { background: rgba(255, 255, 255, .04); border-color: rgba(255, 255, 255, .06); }
    /* El primer tramo es el mas alto: es lo que paga mientras todos los
       lotes siguen vivos, y el numero con el que decide si le alcanza. */
    .olympo-escalera li:first-child { background: rgb(239 246 255); border-color: rgba(37, 99, 235, .25); }
    .dark .olympo-escalera li:first-child { background: rgba(59, 130, 246, .14); border-color: rgba(59, 130, 246, .35); }
    .olympo-escalera .meses { color: rgb(82 82 91); }
    .dark .olympo-escalera .meses { color: rgb(212 212 216); }
    .olympo-escalera .monto { font-weight: 700; font-variant-numeric: tabular-nums; color: rgb(9 9 11); }
    .dark .olympo-escalera .monto { color: #fff; }

    /* Las cuotas VENCIDAS de cada lote, debajo de su casilla en el modal de
       cobro. Van listadas una por una —no contadas— porque quien atiende
       tiene que poder contestar «¿de qué meses me estás cobrando?» sin
       salirse del modal. La primera va marcada: es a la que se aplica la
       plata si el cliente trae para una sola. */
    .olympo-vencidas { display: block; font-weight: 600; color: rgb(180 35 24); }
    .dark .olympo-vencidas { color: rgb(252 165 165); }
    .olympo-vencidas-lista { margin: .25rem 0 0; padding: 0; list-style: none; }
    .olympo-vencidas-lista li { font-variant-numeric: tabular-nums; line-height: 1.5; }
    .olympo-vencidas-lista li:first-child strong { color: rgb(180 35 24); font-weight: 600; }
    .dark .olympo-vencidas-lista li:first-child strong { color: rgb(252 165 165); }

    /* El encabezado de cada lote adentro del desglose de un cobro, y el
       total de abajo. Sin el total, quien atiende tendría que sumar tres
       cuotas de cabeza con el cliente enfrente. */
    .olympo-lote {
        margin: .75rem 0 .3125rem; font-size: .6875rem; font-weight: 700;
        letter-spacing: .04em; text-transform: uppercase; color: rgb(113 113 122);
    }
    .olympo-lote:first-child { margin-top: 0; }
    .dark .olympo-lote { color: rgb(161 161 170); }

    .olympo-total {
        display: flex; align-items: baseline; justify-content: space-between; gap: 1.5rem;
        max-width: 42rem; margin-top: .625rem; padding-top: .625rem;
        font-size: .9375rem; font-weight: 700; font-variant-numeric: tabular-nums;
        color: rgb(9 9 11); border-top: 2px solid rgb(228 228 231);
    }
    .dark .olympo-total { color: #fff; border-top-color: rgba(255, 255, 255, .18); }

    /* ── La casilla que hay que tildar antes de firmar ──

       Es la última puerta antes de quemar un correlativo (14-ago) y hasta hoy
       se veía como un campo más de la lista: quien scrolleaba de largo la
       pasaba sin verla, apretaba «Firmar la venta» y recién ahí el modal le
       contestaba con un error. La friccion existía, pero llegaba tarde.

       Ahora es una BANDA: ámbar mientras falta, verde apenas se tilda. El
       estado de la última puerta se ve sin leer una palabra, y desde arriba
       del botón.

       ⚠️ `:has()` es lo que deja pintarla por el estado del <input> sin una
       línea de JS. Donde no exista, la banda se queda ámbar y no se pierde
       nada: la casilla sigue funcionando igual. */
    .olympo-confirmar {
        padding: .75rem .875rem;
        border: 1px solid rgba(245, 158, 11, .45);
        border-radius: .625rem;
        background: rgba(254, 243, 199, .55);
        transition: background-color .15s ease, border-color .15s ease;
    }
    .olympo-confirmar label { font-weight: 600; color: rgb(120 53 15); }
    .olympo-confirmar:has(input:checked) {
        border-color: rgba(22, 163, 74, .45);
        background: rgba(220, 252, 231, .6);
    }
    .olympo-confirmar:has(input:checked) label { color: rgb(21 128 61); }
    .dark .olympo-confirmar { border-color: rgba(245, 158, 11, .35); background: rgba(245, 158, 11, .1); }
    .dark .olympo-confirmar label { color: rgb(253 230 138); }
    .dark .olympo-confirmar:has(input:checked) { border-color: rgba(34, 197, 94, .4); background: rgba(34, 197, 94, .12); }
    .dark .olympo-confirmar:has(input:checked) label { color: rgb(134 239 172); }

    .olympo-nota { margin-top: .5rem; font-size: .75rem; line-height: 1.65; color: rgb(113 113 122); }
    .dark .olympo-nota { color: rgb(161 161 170); }
    .olympo-vacio { font-size: .875rem; color: rgb(113 113 122); }
    .dark .olympo-vacio { color: rgb(161 161 170); }

    /*
       ── La pestaña «Gastos» del proyecto (8-oct-2026) ──

       El resumen de arriba son cifras en tarjetas —total, pagado con la caja,
       pagado por socios— y una fila de rubros con su puntito de color. Todo
       son `span`: se imprime DENTRO del `<p>` de la descripción de la tabla,
       y un `div` ahí adentro cierra el párrafo y el navegador saca el
       contenido de su lugar. El `display: block` / `grid` lo da el CSS.

       `.olympo-gastos-punto` y `.olympo-gastos-linea` también los usa la
       línea de apoyo de la columna «Detalle».
    */
    .olympo-gastos { display: block; margin-top: .5rem; }

    .olympo-gastos-cifras {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr));
        gap: .75rem;
        margin-bottom: .875rem;
    }
    .olympo-gastos-cifra {
        display: block;
        padding: .75rem 1rem;
        border: 1px solid rgb(228 230 235);
        border-radius: .75rem;
        background: rgb(250 250 251);
    }
    .olympo-gastos-cifra.socios {
        border-color: rgba(59, 130, 246, .28);
        background: rgba(239, 246, 255, .7);
    }
    .olympo-gastos-rotulo {
        display: block;
        font-size: .6875rem;
        font-weight: 600;
        letter-spacing: .055em;
        text-transform: uppercase;
        color: rgb(107 114 128);
    }
    .olympo-gastos-monto {
        display: block;
        margin-top: .25rem;
        font-size: 1.25rem;
        font-weight: 700;
        line-height: 1.2;
        color: rgb(9 9 11);
        font-variant-numeric: tabular-nums;
    }
    .olympo-gastos-nota {
        display: block;
        margin-top: .1875rem;
        font-size: .75rem;
        color: rgb(107 114 128);
        font-variant-numeric: tabular-nums;
    }

    .olympo-gastos-rubros { display: flex; flex-wrap: wrap; gap: .375rem; }
    .olympo-gastos-rubro {
        display: inline-flex;
        align-items: center;
        gap: .375rem;
        padding: .25rem .625rem;
        border: 1px solid rgb(228 230 235);
        border-radius: 9999px;
        background: rgb(255 255 255);
        font-size: .75rem;
        color: rgb(63 63 70);
        white-space: nowrap;
    }
    .olympo-gastos-rubro strong { font-weight: 600; color: rgb(9 9 11); font-variant-numeric: tabular-nums; }

    .olympo-gastos-punto {
        display: inline-block;
        width: .5rem;
        height: .5rem;
        border-radius: 9999px;
        flex-shrink: 0;
    }
    .olympo-gastos-linea { display: inline-flex; flex-wrap: wrap; align-items: center; gap: .25rem .375rem; }
    .olympo-gastos-tenue { color: rgb(161 161 170); }
    .olympo-gastos-pie { display: block; margin-top: .625rem; font-size: .75rem; color: rgb(161 161 170); }

    .dark .olympo-gastos-cifra { border-color: rgba(255, 255, 255, .10); background: rgba(255, 255, 255, .03); }
    .dark .olympo-gastos-cifra.socios { border-color: rgba(96, 165, 250, .35); background: rgba(59, 130, 246, .10); }
    .dark .olympo-gastos-rotulo, .dark .olympo-gastos-nota { color: rgb(148 154 165); }
    .dark .olympo-gastos-monto, .dark .olympo-gastos-rubro strong { color: rgb(244 244 245); }
    .dark .olympo-gastos-rubro { border-color: rgba(255, 255, 255, .10); background: rgba(255, 255, 255, .03); color: rgb(212 212 216); }

    /* ══ «Reestructurar el contrato» (traído de Maya, 25-oct-2026) ══

       Las tarjetas, la franja del contrato, la franja de estado y el plan
       con altura fija del modal de Maya, tal cual: «debería de tener más o
       menos ese mismo diseño» —Mauricio—. El CSS de Filament está
       precompilado y estas clases no existen ahí (§9.A7). */
    /* ── Tarjetas de números: «Reestructurar el contrato» ──

       «Que tenga un mejor diseño» —Mauricio—, mirando un modal que decía todo
       en párrafos grises. Un número que decide dinero se lee en una tarjeta:
       rótulo chico, el número grande, una línea de detalle. La grilla se
       acomoda sola: cuatro por fila en la computadora, una en el celular.

       `minmax(11rem, …)` y no menos: «L. 1,161,498.00» en negrita tiene que
       entrar entero en la tarjeta más angosta. Un monto recortado es peor que
       uno ausente (§9.A15). */
    .olympo-kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr)); gap: .625rem; }
    .olympo-kpi {
        display: flex; flex-direction: column; gap: .1875rem; min-width: 0;
        padding: .75rem .875rem; border: 1px solid rgb(228 228 231); border-radius: .75rem; background: #fff;
    }
    .olympo-kpi .rotulo {
        font-size: .6875rem; font-weight: 600; letter-spacing: .04em; text-transform: uppercase; color: rgb(113 113 122);
    }
    .olympo-kpi .valor {
        font-size: 1.125rem; font-weight: 700; line-height: 1.3; white-space: nowrap;
        font-variant-numeric: tabular-nums; color: rgb(9 9 11);
    }
    .olympo-kpi .detalle { font-size: .75rem; line-height: 1.45; color: rgb(113 113 122); }
    .olympo-kpi.pagado { border-color: rgba(22, 163, 74, .35); background: rgba(220, 252, 231, .45); }
    .olympo-kpi.pagado .valor { color: rgb(21 128 61); }
    .olympo-kpi.mora { border-color: rgba(220, 38, 38, .3); background: rgba(254, 226, 226, .45); }
    .olympo-kpi.mora .valor { color: rgb(185 28 28); }
    .dark .olympo-kpi { border-color: rgba(255, 255, 255, .1); background: rgba(255, 255, 255, .03); }
    .dark .olympo-kpi .rotulo, .dark .olympo-kpi .detalle { color: rgb(161 161 170); }
    .dark .olympo-kpi .valor { color: #fff; }
    .dark .olympo-kpi.pagado { border-color: rgba(34, 197, 94, .35); background: rgba(34, 197, 94, .1); }
    .dark .olympo-kpi.pagado .valor { color: rgb(134 239 172); }
    .dark .olympo-kpi.mora { border-color: rgba(248, 113, 113, .35); background: rgba(248, 113, 113, .1); }
    .dark .olympo-kpi.mora .valor { color: rgb(252 165 165); }

    /* ── La franja de datos del contrato (26-sep-2026) ──

       Valor, PRIMA, financiado, tasa y día, de corrido y separados por una
       raya: se leen de un vistazo sin competir con las tarjetas de abajo, que
       son las que cambian. La prima va resaltada porque es lo primero que el
       cliente pregunta cuando se le rehace el plan. En el celular se parten
       en dos o tres renglones solas. */
    .olympo-datos {
        display: flex; flex-wrap: wrap; margin: 0;
        border: 1px solid rgb(228 228 231); border-radius: .75rem; overflow: hidden; background: rgb(250 250 250);
    }
    .olympo-datos > div {
        flex: 1 1 8.5rem; display: flex; flex-direction: column; gap: .125rem;
        padding: .625rem .875rem; border-right: 1px solid rgb(228 228 231);
    }
    .olympo-datos > div:last-child { border-right: 0; }
    .olympo-datos dt { font-size: .625rem; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: rgb(113 113 122); }
    .olympo-datos dd { margin: 0; font-size: .9375rem; font-weight: 700; white-space: nowrap; font-variant-numeric: tabular-nums; color: rgb(9 9 11); }
    .olympo-datos span { font-size: .6875rem; color: rgb(113 113 122); }
    .olympo-datos .destacado { background: rgba(254, 243, 199, .6); }
    .olympo-datos .destacado dd { color: rgb(146 64 14); }
    .dark .olympo-datos { border-color: rgba(255, 255, 255, .1); background: rgba(255, 255, 255, .03); }
    .dark .olympo-datos > div { border-right-color: rgba(255, 255, 255, .1); }
    .dark .olympo-datos dt, .dark .olympo-datos span { color: rgb(161 161 170); }
    .dark .olympo-datos dd { color: #fff; }
    .dark .olympo-datos .destacado { background: rgba(245, 158, 11, .12); }
    .dark .olympo-datos .destacado dd { color: rgb(253 230 138); }

    /* ── La franja de estado: un círculo con el signo y dos renglones ──

       Verde «queda en números cerrados», ámbar «a la cuota 1 le faltan…». El
       color y el signo dicen el estado antes de leer una palabra. */
    .olympo-estado {
        display: flex; gap: .75rem; align-items: flex-start;
        padding: .875rem 1rem; border: 1px solid; border-radius: .75rem;
    }
    .olympo-estado .icono {
        flex: none; display: inline-flex; align-items: center; justify-content: center;
        width: 1.75rem; height: 1.75rem; border-radius: 9999px; font-size: .875rem; font-weight: 800; color: #fff;
    }
    .olympo-estado strong { display: block; font-size: .9375rem; line-height: 1.4; }
    .olympo-estado p { margin: .125rem 0 0; font-size: .8125rem; line-height: 1.55; }
    .olympo-estado.bien { border-color: rgba(22, 163, 74, .4); background: rgba(220, 252, 231, .6); color: rgb(21 128 61); }
    .olympo-estado.bien .icono { background: rgb(22 163 74); }
    .olympo-estado.bien strong { color: rgb(20 83 45); }
    .olympo-estado.falta { border-color: rgba(245, 158, 11, .45); background: rgba(254, 243, 199, .55); color: rgb(120 53 15); }
    .olympo-estado.falta .icono { background: rgb(217 119 6); }
    .olympo-estado.falta strong { color: rgb(146 64 14); }
    .dark .olympo-estado.bien { border-color: rgba(34, 197, 94, .4); background: rgba(34, 197, 94, .12); color: rgb(134 239 172); }
    .dark .olympo-estado.bien strong { color: rgb(220 252 231); }
    .dark .olympo-estado.falta { border-color: rgba(245, 158, 11, .35); background: rgba(245, 158, 11, .1); color: rgb(253 230 138); }
    .dark .olympo-estado.falta strong { color: rgb(254 243 199); }

    /* Las pastillas de la columna «Ya pagado» del plan, y el renglón de un
       mes que lo ya pagado cubre entero, apenas teñido de verde. */
    .olympo-pill.bien { background: rgba(22, 163, 74, .12); color: rgb(21 128 61); }
    .olympo-pill.parcial { background: rgba(245, 158, 11, .15); color: rgb(146 64 14); }
    .dark .olympo-pill.bien { background: rgba(34, 197, 94, .15); color: rgb(134 239 172); }
    .dark .olympo-pill.parcial { background: rgba(245, 158, 11, .15); color: rgb(253 230 138); }
    .olympo-tabla tr.cubierta td { background: rgba(220, 252, 231, .35); }
    .dark .olympo-tabla tr.cubierta td { background: rgba(34, 197, 94, .07); }

    /* Las aclaraciones del plan, una por renglón, en un panel gris claro
       con un punto discreto: se leen como nota al pie, no como alarma.

       La franja negra que iba encima de las tarjetas («11 pagos de …») se
       quitó el 26-sep-2026 —«se ve feo»—: lo que decía vive ahora en la
       primera línea de cada tarjeta. */
    .olympo-notas {
        display: grid; gap: .4375rem; margin: 0; padding: .75rem .875rem; list-style: none;
        border: 1px solid rgb(244 244 245); border-radius: .75rem; background: rgb(250 250 250);
    }
    .olympo-notas li {
        position: relative; padding-left: .875rem;
        font-size: .8125rem; line-height: 1.55; color: rgb(82 82 91);
    }
    .olympo-notas li::before {
        content: ''; position: absolute; left: 0; top: .55rem;
        width: .3125rem; height: .3125rem; border-radius: 9999px; background: rgb(161 161 170);
    }
    .dark .olympo-notas { border-color: rgba(255, 255, 255, .06); background: rgba(255, 255, 255, .03); }
    .dark .olympo-notas li { color: rgb(212 212 216); }
    .dark .olympo-notas li::before { background: rgb(113 113 122); }

    /* La vista previa antes de que haya un plan que mostrar: un recuadro
       punteado que dice qué falta, en vez de una línea suelta de texto. */
    .olympo-espera {
        padding: 1.5rem 1rem; border: 1px dashed rgb(212 212 216); border-radius: .75rem;
        font-size: .875rem; line-height: 1.55; text-align: center; color: rgb(113 113 122);
    }
    .dark .olympo-espera { border-color: rgba(255, 255, 255, .15); color: rgb(161 161 170); }

    /* La segunda línea de una tarjeta —«Antes …»— un tono más apagada que
       la primera, para que se lea primero lo nuevo. */
    .olympo-kpi .detalle + .detalle { color: rgb(161 161 170); }
    .dark .olympo-kpi .detalle + .detalle { color: rgb(113 113 122); }

    /* El total del plan, al pie del cuadro: la prueba a la vista de que cuadra. */
    .olympo-plan tfoot td {
        padding-top: .625rem; font-weight: 700; color: rgb(9 9 11);
        border-top: 2px solid rgb(228 228 231); border-bottom: 0;
    }
    .olympo-scroll-alto tfoot td { position: sticky; bottom: 0; background: #fff; }
    .dark .olympo-plan tfoot td { color: #fff; border-top-color: rgba(255, 255, 255, .18); }
    .dark .olympo-scroll-alto tfoot td { background: rgb(24 24 27); }

    /* Bloques apilados con aire entre ellos, sin márgenes sueltos en PHP. */
    .olympo-bloques { display: grid; gap: .875rem; }

    /* Un cuadro largo —un plan de 48 cuotas— no estira el modal hasta perder
       de vista el botón: altura fija, y el encabezado se queda arriba. */
    .olympo-scroll-alto { max-height: 18rem; overflow: auto; overscroll-behavior: contain; }
    .olympo-scroll-alto thead th { position: sticky; top: 0; z-index: 1; padding-top: .375rem; background: #fff; }
    .dark .olympo-scroll-alto thead th { background: rgb(24 24 27); }
</style>

{{-- ═══ IMPRIMIR SIN ABRIR UNA PESTAÑA ═══

     Pedido de Mauricio el 14-ago-2026: «que no se abra una nueva ventana al
     presionar imprimir, que directamente se abra la pantalla de impresión».
     Tenía razón — quien cobra imprime veinte veces al día y cada una le
     dejaba una pestaña abierta que después hay que cerrar a mano.

     El documento se carga en un iframe escondido y se manda a imprimir ahí.
     El diálogo sale igual, la pestaña no existe, y el expediente que la
     persona tenía abierto no se mueve.

     ⚠️ El iframe se DESTRUYE después de imprimir y no antes: si se quita
     mientras el diálogo está abierto, Chrome imprime una hoja en blanco.
     `onafterprint` avisa tanto al imprimir como al cancelar, y el
     `setTimeout` de respaldo cubre a los navegadores que no lo disparan. --}}
<script>
    window.olympoImprimir = function (url) {
        const viejo = document.getElementById('olympo-impresor');
        if (viejo) viejo.remove();

        const marco = document.createElement('iframe');
        marco.id = 'olympo-impresor';
        marco.setAttribute('aria-hidden', 'true');
        marco.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;visibility:hidden';

        marco.onload = function () {
            const ventana = marco.contentWindow;
            let listo = false;

            const limpiar = function () {
                if (listo) return;
                listo = true;
                setTimeout(function () { marco.remove(); }, 500);
            };

            ventana.onafterprint = limpiar;
            ventana.focus();
            ventana.print();

            // Respaldo: hay navegadores que no disparan onafterprint.
            setTimeout(limpiar, 60000);
        };

        marco.src = url;
        document.body.appendChild(marco);
    };
</script>
