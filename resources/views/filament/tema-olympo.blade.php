{{--
    ═══════════════════════════════════════════════════════════════════════
    EL CHASIS VISUAL DEL PANEL — Olympo Lotificaciones
    ═══════════════════════════════════════════════════════════════════════

    Lo que hace que un panel se vea serio no es el color: es la disciplina.
    Etiquetas chicas en versalitas, números que se alinean en columna, líneas
    de un pixel en vez de sombras, y títulos con el espaciado apretado. Esto
    es todo eso, y nada más.

    ═══ POR QUE NO ES UN TEMA DE FILAMENT ═══

    Lo ortodoxo sería `php artisan make:filament-theme` y compilar con Vite.
    NO se hizo, y a diez días de arrancar la razón es una sola: un tema de
    Vite obliga a que el asset compilado exista. Si falta —porque nadie corrió
    el build— Filament tira una excepción del manifiesto y **el panel entero
    deja de abrir**. Esto, en cambio, es CSS que se inyecta en el <head>: si
    algún día molesta, se borra la línea del `renderHook` y el panel vuelve al
    aspecto de fábrica sin compilar nada ni tocar una línea de lógica.

    ═══ POR QUE NO HACE FALTA UN SOLO !important ═══

    Filament 5 compila con Tailwind 4, que mete TODO adentro de capas
    (`@layer theme, base, components, utilities`). El CSS sin capa —este—
    gana siempre contra el CSS con capa, sin importar la especificidad. Es
    regla de la cascada, no un truco.

    ═══ LO QUE NO SE TOCA ═══

    - **El color primario.** Lo elige cada lotificadora desde Configuración
      (Ley L0). Acá solo se tocan los neutros, así que el ámbar de Praderas y
      el azul de la próxima se ven igual de bien.
    - **La tipografía.** Filament sirve Inter Variable desde el propio
      servidor. Cambiarla la haría venir de un CDN externo en cada carga, y
      una oficina sin internet vería la pantalla degradarse sola.
    - **Los tamaños de las cajas.** Ni un padding, ni un ancho: eso mueve
      layouts y rompe pantallas que hoy funcionan.
--}}
<style>
    /*
       ── 1. Los neutros ────────────────────────────────────────────────

       Filament trae grises cálidos. Un sistema que maneja dinero ajeno se
       lee mejor en grises fríos: el papel se ve papel y la tinta, tinta.
       Es un corrimiento de dos o tres grados, no un cambio de paleta.
    */
    .fi-body {
        background-color: rgb(247 248 250);
        -webkit-font-smoothing: antialiased;
        -moz-osx-font-smoothing: grayscale;
    }

    .dark .fi-body { background-color: rgb(17 18 22); }

    /*
       ── 2. Superficies: una línea de un pixel, cero sombra ────────────

       Filament separa las tarjetas con un `ring` y una sombra. La sombra
       es lo que hace que un panel se vea "de plantilla": son doce cajas
       flotando sobre un fondo, sin jerarquía entre ellas.

       Una línea de un pixel dice lo mismo —dónde empieza y termina cada
       cosa— sin gritar. La profundidad la da el contraste entre el blanco
       de la tarjeta y el gris del fondo, que es como se ve un documento
       sobre un escritorio.

       `box-shadow: none` mata las dos: en Tailwind el `ring` TAMBIEN es un
       box-shadow.
    */
    .fi-section,
    .fi-ta-ctn,
    .fi-wi-stats-overview-stat,
    .fi-dropdown-panel,
    .fi-modal-window {
        box-shadow: none;
        border: 1px solid rgb(228 230 235);
        border-radius: .625rem;
    }

    .dark .fi-section,
    .dark .fi-ta-ctn,
    .dark .fi-wi-stats-overview-stat,
    .dark .fi-dropdown-panel,
    .dark .fi-modal-window {
        border-color: rgba(255, 255, 255, .08);
    }

    /* El modal sí necesita despegarse: está sobre el contenido, no al lado. */
    .fi-modal-window { box-shadow: 0 24px 48px -12px rgba(9, 9, 11, .18); }
    .dark .fi-modal-window { box-shadow: 0 24px 48px -12px rgba(0, 0, 0, .6); }

    /*
       ── 3. Títulos: el espaciado apretado ─────────────────────────────

       El detalle tipográfico que más rinde y que nadie sabe nombrar. Las
       fuentes de interfaz vienen espaciadas para leerse a 14px; a 24px esa
       misma separación se ve suelta y amateur. Menos de un centésimo de em
       y el título se ve dibujado en vez de escrito.
    */
    .fi-header-heading,
    .fi-section-header-heading,
    .fi-ta-header-heading,
    .fi-modal-heading,
    .fi-wi-stats-overview-stat-value {
        letter-spacing: -.021em;
    }

    .fi-header-heading { font-weight: 700; }
    .fi-section-header-heading { font-weight: 600; }

    /*
       ── 4. Los números se alinean ─────────────────────────────────────

       `tabular-nums` le da a cada dígito el mismo ancho, así que L 1,111.00
       y L 8,888.00 ocupan exactamente lo mismo y una columna de montos se
       lee como una columna y no como un párrafo. En un sistema de cobros
       esto no es estética: es poder comparar de un vistazo.

       Va en los datos, no en los títulos.
    */
    .fi-ta-cell,
    .fi-in-entry-content,
    .fi-badge,
    .fi-pagination,
    .fi-wi-stats-overview-stat-value,
    .fi-wi-stats-overview-stat-description {
        font-variant-numeric: tabular-nums;
    }

    /*
       ── 5. Las micro-etiquetas ────────────────────────────────────────

       Cabeceras de tabla, etiquetas de ficha y títulos de grupo del menú
       comparten un mismo tratamiento: chicas, en versalitas, con el
       espaciado abierto y en gris medio. Es la convención de los sistemas
       contables desde antes de que existieran las pantallas, y es lo que
       hace que el ojo salte directo al DATO y no al rótulo.
    */
    .fi-ta-header-cell,
    .fi-ta-header-cell-sort-btn,
    .fi-in-entry-label,
    .fi-sidebar-group-label {
        font-size: .6875rem;
        font-weight: 600;
        letter-spacing: .055em;
        text-transform: uppercase;
        color: rgb(107 114 128);
    }

    .dark .fi-ta-header-cell,
    .dark .fi-ta-header-cell-sort-btn,
    .dark .fi-in-entry-label,
    .dark .fi-sidebar-group-label {
        color: rgb(148 154 165);
    }

    /* La columna por la que está ordenado se distingue sin cambiar de tamaño. */
    .fi-ta-header-cell-sorted,
    .fi-ta-header-cell-sorted .fi-ta-header-cell-sort-btn {
        color: rgb(24 24 27);
    }

    .dark .fi-ta-header-cell-sorted,
    .dark .fi-ta-header-cell-sorted .fi-ta-header-cell-sort-btn {
        color: rgb(244 244 245);
    }

    /*
       ── 6. Radios: menos globo, más documento ─────────────────────────

       Las esquinas muy redondeadas se leen como app de consumo. Un sistema
       de contratos quiere la esquina de un papel: presente, discreta.
    */
    .fi-btn,
    .fi-icon-btn,
    .fi-input-wrp,
    .fi-select-input,
    .fi-sidebar-item-btn,
    .fi-tabs-item {
        border-radius: .5rem;
    }

    .fi-badge { border-radius: .375rem; font-weight: 600; letter-spacing: .005em; }

    /*
       ── 7. El menú lateral ────────────────────────────────────────────

       Lo que está seleccionado se dice con el peso de la letra, no solo con
       el fondo: el que atiende sabe dónde está aunque mire de reojo.
    */
    .fi-sidebar-item-btn { font-weight: 500; }
    .fi-sidebar-item-active .fi-sidebar-item-btn { font-weight: 600; }

    /*
       ── 8. Las filas responden ────────────────────────────────────────

       Una transición de doce centésimas: lo suficiente para que la fila se
       sienta viva bajo el mouse, lo bastante corta para que nadie la espere.
    */
    .fi-ta-row { transition: background-color .12s ease; }

    /*
       ── 9. La barra de pestañas ───────────────────────────────────────

       Se queda CENTRADA, que es el default de Filament y lo que pidió
       Mauricio el 10-ago: en el medio se lee como el fiel de la balanza
       entre la ficha de arriba y la tabla de abajo.

       Lo único que se le toca es el acabado, para que combine con las
       tarjetas: la misma línea de un pixel en vez de la sombra.
    */
    .fi-sc-tabs .fi-tabs:not(.fi-contained) {
        box-shadow: none;
        border: 1px solid rgb(228 230 235);
        border-radius: .625rem;
    }

    .dark .fi-sc-tabs .fi-tabs:not(.fi-contained) {
        border-color: rgba(255, 255, 255, .08);
    }

    /*
       ── 10. Que cada tarjeta mida lo que mide ─────────────────────────

       Las tarjetas de una grilla se estiran para igualar la altura del
       renglón. Suena prolijo y en la práctica es el mayor generador de
       espacio muerto del panel: una caja con tres datos al lado de otra con
       diez se estira al doble y muestra media tarjeta en blanco.

       `align-self: start` las deja medir lo suyo. Las alturas quedan
       desparejas —que es lo honesto: hay cajas con más adentro que otras— y
       la página se acorta sola.

       ═══ ⚠️ 🔴 LA CORRECCION DEL 11-AGO-2026 ═══

       Hasta hoy esta regla decía `.fi-sc-component, .fi-section`, con el
       argumento de que «según el caso el hijo de la grilla es la sección o
       su envoltorio, y sobre el que no lo sea la regla no hace nada».

       La segunda mitad es FALSA, y es exactamente lo que Mauricio vio en el
       expediente de una venta: «acá antes el diseño estaba bien bonito, qué
       pasó».

       La cadena real, leída del DOM con el panel a 1216:

           .fi-grid                                   1216px
           └ .fi-grid-col          ← ESTE es el item de la grilla
             └ .fi-sc-component                       (block)
               └ .fi-sc-section    ← display: flex, flex-direction: COLUMN
                 └ section.fi-section

       En un flex en COLUMNA el eje transversal es el HORIZONTAL. Así que
       `align-self: start` sobre la sección no le tocaba la altura: le
       apagaba el `stretch` del ANCHO, y cada tarjeta se encogía al largo de
       su propio texto. Medido en dos pantallas:

           Ventas    · «Quiénes compran»    221px  en vez de   596
           Ventas    · «Lotes»              639px  en vez de  1216
           Proyectos · «Identificación»     380px  en vez de   596
           Proyectos · «Estado»             628px  en vez de  1216

       Con la regla sobre `.fi-grid-col` los cuatro vuelven a su ancho y
       NINGUNA altura cambia —medidas idénticas antes y después—: en una
       grilla `align-self` sí es el eje vertical, que es lo único que este
       bloque quiso decir siempre.

       La moraleja, por si vuelve a tentar: `align-self` no significa lo
       mismo en un grid que en un flex en columna. Antes de repartir una
       regla «sobre los dos candidatos», hay que mirar qué es cada padre.
    */
    .fi-grid > .fi-grid-col { align-self: start; }

    /*
       ── 11. La caja que tiene que ocupar TODO el ancho ────────────────

       `columnSpanFull()` en el infolist del cliente ya deja todo el
       andamiaje a 1216. Esta clase —que el componente pone a mano con
       `extraAttributes`— solo lo dice también en el CSS, y alcanza
       EXACTAMENTE a las cajas que la piden y a ninguna otra.

       ═══ ⚠️ ACA VIVIA UN PARCHE QUE TAPABA EL BUG DEL §10 ═══

       Debajo había un `flex: 1 1 100%; width: 100%` sobre la sección de
       adentro, con este diagnóstico del 10-ago: «el `<section>` mide 688 de
       1216 porque su padre es un flex y un item de flex sin `flex-grow`
       mide lo que mide su contenido».

       La primera mitad era cierta —el padre es un flex en columna— y la
       segunda confundía la consecuencia con la causa: ese item no tenía que
       CRECER, tenía que ESTIRARSE, y lo único que le apagaba el estiramiento
       era el `align-self: start` del §10. Arreglado el §10, el parche sobra:
       medido el 11-ago, la sección da 1216 con él y sin él.

       Se borra. Un parche que ya no tapa nada solo hace creer que hace algo.
    */
    .olympo-ancho-total { grid-column: 1 / -1; }

    /*
       ── 12. El control segmentado del modal de cobro ──────────────────

       ═══ EL DIAGNOSTICO, LEIDO DEL DOM EL 10-AGO-2026 ═══

       Filament arma un `ToggleButtons` así:

           .fi-fo-toggle-buttons-wrp              ← el campo entero
             ├ .fi-fo-field-label-col             ← display: grid
             └ .fi-fo-field-content-col           ← display: grid
               └ .fi-fo-toggle-buttons.fi-btn-group   ← display: GRID
                   input + label.fi-btn   ×3

       El grupo es una GRILLA, y ahí estaba lo que señaló Mauricio: los
       tres botones se reparten el ancho en partes iguales hasta llenar el
       modal, así que un toggle de tres palabras medía 545px y se leía como
       una barra de herramientas de 2008.

       `inline-flex` los deja medir su texto. Y el centrado va con
       `justify-items` sobre las DOS columnas del campo —la de la etiqueta y
       la del contenido—: las dos son grillas, y centrar solo una dejaba el
       rótulo a la izquierda y el control en el medio.

       ⚠️ `white-space: nowrap` no es opcional: sin él «Abono a capital» se
       parte en dos renglones y la pastilla queda más alta que las otras dos.

       ═══ POR QUE UNA PASTILLA GRIS Y NO TRES COLORES ═══

       Cada opción tenía su color. Tres fondos saturados compiten entre sí y
       ninguno contesta la única pregunta que importa —cuál está elegido—:
       el ojo ve tres botones encendidos. El control segmentado sí se lee de
       un vistazo porque tiene UNA señal y no tres: un riel hundido y una
       pastilla blanca que se mueve.

       De regalo dejó de gastarse el color primario en un campo. Lo elige
       cada lotificadora (Ley L0) y acá no significaba nada.

       El activo se marca con `input:checked + .fi-btn`: en este control el
       `<input>` es el hermano ANTERIOR de su `<label>`, así que alcanza el
       selector de hermano adyacente y no hace falta `:has()`.

       ⚠️ **`extraAttributes` de un `ToggleButtons` NO cae en el campo: cae en
       el GRUPO.** Medido en el navegador el 10-ago —la clase salió en
       `div.olympo-modo.fi-fo-toggle-buttons.fi-btn-group`—, así que para
       centrar el campo hay que SUBIR con `:has()`. La primera versión de
       este bloque bajaba con `.olympo-modo .fi-fo-field-label-col` y no
       aplicaba nada: esos dos son ancestros, no descendientes.

       Con eso, esto no toca ningún otro `ToggleButtons` del sistema.
    */
    .fi-fo-toggle-buttons-wrp:has(.olympo-modo) .fi-fo-field-label-col,
    .fi-fo-toggle-buttons-wrp:has(.olympo-modo) .fi-fo-field-content-col { justify-items: center; }

    /* El rótulo, en el mismo idioma que las micro-etiquetas del §5. */
    .fi-fo-toggle-buttons-wrp:has(.olympo-modo) .fi-fo-field-label {
        font-size: .6875rem;
        font-weight: 600;
        letter-spacing: .055em;
        text-transform: uppercase;
        color: rgb(107 114 128);
    }

    .fi-fo-toggle-buttons-wrp:has(.olympo-modo) .fi-sc-text { text-align: center; }

    /* El riel — la clase cae ACA, sobre el grupo. */
    .olympo-modo {
        display: inline-flex;
        width: auto;
        gap: .1875rem;
        padding: .1875rem;
        border-radius: .625rem;
        background: rgb(241 243 246);
        border: 1px solid rgb(228 230 235);
    }

    /* Las tres opciones, apagadas. */
    .olympo-modo .fi-btn {
        background: none;
        border: 0;
        box-shadow: none;
        border-radius: .4375rem;
        padding: .4375rem 1.125rem;
        font-size: .8125rem;
        font-weight: 500;
        white-space: nowrap;
        color: rgb(82 88 98);
        transition: background-color .15s ease, color .15s ease, box-shadow .15s ease;
    }

    .olympo-modo .fi-btn:hover { color: rgb(24 24 27); }

    /* La pastilla: la única señal de qué está elegido. */
    .olympo-modo input:checked + .fi-btn {
        background: rgb(255 255 255);
        color: rgb(24 24 27);
        font-weight: 600;
        box-shadow: 0 1px 2px rgba(9, 9, 11, .10), 0 0 0 1px rgba(9, 9, 11, .04);
    }

    .dark .fi-fo-toggle-buttons-wrp:has(.olympo-modo) .fi-fo-field-label { color: rgb(148 154 165); }

    .dark .olympo-modo {
        background: rgba(255, 255, 255, .04);
        border-color: rgba(255, 255, 255, .08);
    }

    .dark .olympo-modo .fi-btn { color: rgb(161 168 180); }
    .dark .olympo-modo .fi-btn:hover { color: rgb(244 244 245); }

    .dark .olympo-modo input:checked + .fi-btn {
        background: rgb(46 48 56);
        color: rgb(244 244 245);
        box-shadow: 0 1px 2px rgba(0, 0, 0, .4), 0 0 0 1px rgba(255, 255, 255, .06);
    }

    /*
       ── 13. La barra de la izquierda ──────────────────────────────────

       Lo pidió Mauricio el 11-ago-2026: «mejoremos el navbar, que sea más
       profesional».

       ═══ EL PROBLEMA: TODO ERA LA MISMA HOJA ═══

       Medido antes de tocar nada: `.fi-sidebar` tenía `background:
       transparent` y CERO borde, así que el menú y el contenido compartían
       exactamente el mismo `rgb(247 248 250)` del `<body>`. No había línea,
       no había tono, no había nada: el ojo tenía que deducir dónde
       terminaba la navegación por la posición de las palabras.

       Un riel apenas más apagado lo contesta sin decir una palabra. Es lo
       que hacen Linear, Stripe y Vercel, y es más viejo que las tres: el
       margen de una libreta.

       ═══ EL ACTIVO ES UNA PASTILLA BLANCA, IGUAL QUE EL §12 ═══

       Sobre el riel gris, el activo se marca **al revés**: la pastilla es
       la que va en blanco, con la misma sombra de un pixel que el control
       segmentado del modal de cobro. Dos controles distintos del sistema
       diciendo «esto es lo que está elegido» con el mismo gesto.

       El default de Filament era un gris casi idéntico al del riel nuevo
       —`oklch(0.967)` sobre `rgb(241 243 246)`—, o sea que sin este cambio
       el ítem activo se habría vuelto invisible.

       ⚠️ El color del texto y del ícono NO se tocan: el activo sigue en el
       primario de la lotificadora (Ley L0). Blanco + ámbar, o blanco + azul
       el día que otra lotificadora elija el suyo.

       ⚠️ La clase del activo es `.fi-active` sobre el `<li>`, NO
       `.fi-sidebar-item-active`. Leído del DOM; el nombre viejo no existe
       en Filament 5.
    */
    .fi-sidebar {
        background-color: rgb(241 243 246);
        border-right: 1px solid rgb(228 230 235);
    }

    .fi-sidebar-item .fi-sidebar-item-btn:hover { background-color: rgba(255, 255, 255, .65); }

    .fi-sidebar-item.fi-active .fi-sidebar-item-btn {
        background-color: rgb(255 255 255);
        box-shadow: 0 1px 2px rgba(9, 9, 11, .10), 0 0 0 1px rgba(9, 9, 11, .04);
    }

    /* En oscuro el riel va para el otro lado: más hundido que el contenido. */
    .dark .fi-sidebar {
        background-color: rgb(13 14 18);
        border-right-color: rgba(255, 255, 255, .08);
    }

    .dark .fi-sidebar-item .fi-sidebar-item-btn:hover { background-color: rgba(255, 255, 255, .04); }

    .dark .fi-sidebar-item.fi-active .fi-sidebar-item-btn {
        background-color: rgb(46 48 56);
        box-shadow: 0 1px 2px rgba(0, 0, 0, .4), 0 0 0 1px rgba(255, 255, 255, .06);
    }

    /*
       ── 13-bis. El menú, más apretado ─────────────────────────────────

       22-ago-2026. Mauricio: «se ve muy seco, no se sabe qué es lo
       importante ni cómo se maneja».

       ═══ MEDIDO ANTES DE TOCAR NADA ═══

       Leído del DOM en su propia pantalla, no supuesto:

         .fi-sidebar-item-btn   font-size 16px
         su <svg>               24 × 24 px

       Dieciséis píxeles es tamaño de PÁRRAFO, y el ícono era más alto que
       la mayúscula del texto que acompaña. Once entradas así ocupan 678 px
       de alto y **todas gritan al mismo volumen**: nada puede destacar,
       porque destacar es ser distinto del resto.

       Con 14 / 20 el menú entero baja a 610 px, entra sin scroll y —lo que
       importa— deja lugar para que el contador rojo de Ventas y la pastilla
       del activo sean lo único que levanta la voz. La densidad es la misma
       que usan Linear y Stripe en su navegación.

       ⚠️ La TIPOGRAFÍA no se toca, solo el tamaño: sigue siendo la Inter de
       Filament (regla de Mauricio del 11-ago). Y el ancho del riel tampoco:
       320 px es de Filament y el logo se dibuja contra él.
    */
    .fi-sidebar-item-btn {
        font-size: .875rem;
        padding-block: .4rem;
        gap: .625rem;
    }

    .fi-sidebar-item-btn .fi-icon,
    .fi-sidebar-item-btn svg {
        width: 1.25rem;
        height: 1.25rem;
    }

    /* El activo además pesa: la pastilla sola no alcanza a 14px. */
    .fi-sidebar-item.fi-active .fi-sidebar-item-btn { font-weight: 600; }

    /* Las versalitas del grupo, un punto más chicas y más separadas. */
    .fi-sidebar-group-label {
        font-size: .6875rem;
        letter-spacing: .08em;
    }

    /*
       ── 14. El contador tiene que verse ───────────────────────────────

       Medido el 22-ago: el badge de Prospectos llegaba con
       `background: oklch(0.987 0.022 95.277)` — un ámbar tan pálido que
       sobre el riel `rgb(241 243 246)` del §13 **desaparecía**. El número
       se leía gris, y un aviso que se lee gris no es un aviso.

       No es culpa de Filament: ese ámbar está pensado para fondo blanco.
       Al meter el riel gris del §13 nos quedamos con la deuda.

       Se le sube el cuerpo al fondo y se le pone la línea de un pixel que
       usa todo el resto del sistema. Los colores siguen siendo los de
       Filament —`warning` y `danger` según lo que cuente cada recurso—, no
       se inventa ninguno.
    */
    .fi-sidebar-item .fi-badge {
        font-variant-numeric: tabular-nums;
        box-shadow: inset 0 0 0 1px rgba(9, 9, 11, .06);
    }

    .fi-sidebar-item .fi-badge.fi-color-warning { background-color: rgb(254 243 199); }
    .fi-sidebar-item .fi-badge.fi-color-danger { background-color: rgb(254 226 226); }

    .dark .fi-sidebar-item .fi-badge { box-shadow: inset 0 0 0 1px rgba(255, 255, 255, .08); }
    .dark .fi-sidebar-item .fi-badge.fi-color-warning { background-color: rgba(180, 83, 9, .25); }
    .dark .fi-sidebar-item .fi-badge.fi-color-danger { background-color: rgba(153, 27, 27, .3); }
</style>
