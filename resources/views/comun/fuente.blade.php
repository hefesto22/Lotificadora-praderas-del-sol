{{--
    ═══════════════════════════════════════════════════════════════════════
    LA TIPOGRAFIA DEL SISTEMA — una sola, en todas las pantallas
    ═══════════════════════════════════════════════════════════════════════

    Lo pidió Mauricio el 11-ago-2026: «la tipografía que sea en todo la de
    Filament, para que no haya problemas en el futuro».

    ═══ EL PROBLEMA QUE RESUELVE ═══

    El panel se ve en **Inter Variable**, que Filament sirve desde el propio
    servidor. Pero las pantallas que viven FUERA del panel cada una había
    elegido la suya:

        recibo.blade.php            ui-sans-serif, system-ui, …
        estado-de-cuenta.blade.php  ui-sans-serif, system-ui, …
        publico/plano.blade.php     system-ui, -apple-system, …
        errors/layout.blade.php     -apple-system, BlinkMacSystemFont, …

    Cuatro pilas distintas y ninguna igual a la del panel. En la práctica eso
    significa que el recibo que el cliente se lleva a su casa está escrito en
    una letra distinta a la de la pantalla donde se lo cobraron — y que en
    una Mac, una PC y un Android se ve de tres maneras.

    ═══ POR QUE UN PARCIAL Y NO CUATRO COPIAS ═══

    Porque la ruta del archivo es lo único frágil de todo esto. Si Filament
    la mueve en una versión futura, se corrige ACA y las cuatro pantallas
    quedan arregladas. Cuatro copias serían cuatro lugares donde olvidarse.

    ═══ 🔴 ESTO NO REVIVE LA DEPENDENCIA DE UN BUILD ═══

    El docblock de `recibo.blade.php` decía —con razón— que un documento que
    se entrega «no puede depender de que un build de assets haya corrido».
    Sigue sin depender: estos archivos NO los produce Vite. Los publica
    `php artisan filament:assets` y están **versionados en git**
    (`git ls-files public/fonts` los lista). Vienen con el repo.

    Y si igual faltaran, el `font-display: swap` y el resto de la pila hacen
    que la página se vea exactamente como se veía antes. Nada se rompe.

    ═══ EL COSTO, MEDIDO ═══

    - Solo baja el subconjunto **latin: 48 KB**. Los otros seis (cirílico,
      griego, vietnamita…) no los pide nadie que escriba en español: los
      frena el `unicode-range` del propio `index.css`.
    - `font-display: swap` — el texto se pinta en el PRIMER cuadro con la
      letra del sistema y cambia a Inter cuando llega. La página nunca se
      queda en blanco esperando la fuente.

    Eso último es lo que hace que esto sea aceptable en `publico/plano.blade.php`,
    que se abre en un teléfono con mala señal y cuyo comentario original decía
    «tampoco hay tipografía descargada». La razón de aquella decisión —que se
    pinte en el primer cuadro— se sigue cumpliendo.
--}}
<link rel="stylesheet" href="{{ asset('fonts/filament/filament/inter/index.css') }}">
<style>
    :root {
        /* La pila EXACTA que usa el panel, leída del navegador el 11-ago-2026. */
        --olympo-fuente: 'Inter Variable', ui-sans-serif, system-ui, sans-serif,
            'Apple Color Emoji', 'Segoe UI Emoji', 'Segoe UI Symbol', 'Noto Color Emoji';
    }
</style>
