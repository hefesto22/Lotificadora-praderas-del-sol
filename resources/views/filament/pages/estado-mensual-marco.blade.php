{{--
    La hoja del mes, adentro de la ventana flotante.

    ═══ POR QUE UN `<iframe>` ═══

    El documento trae su propio `<style>` con `@page`, tipografías y reglas
    sueltas sobre `table` y `td`. Pegado en el panel, ese CSS se derrama sobre
    Filament. Adentro del marco es otro documento y su CSS no sale de ahí — y
    de yapa, el `window.print()` del botón «Imprimir» imprime SOLO el marco,
    que es exactamente el papel que se quería.

    ═══ 🔴 ESTILOS EN LINEA, Y NO CLASES DE TAILWIND ═══

    Se probó con `class="h-[75vh] w-full"` y el marco salió de 300 × 150 — el
    tamaño POR DEFECTO de un `<iframe>` sin CSS. La razón: el panel no declara
    `viteTheme()`, así que corre con **el CSS precompilado que trae Filament**,
    donde solo existen las clases que Filament usa. Una clase arbitraria como
    `h-[75vh]` nunca se generó, y `w-full` tampoco estaba.

    Regla para cualquier vista que se inyecte en el panel: **si no lo dibuja un
    componente de Filament, el estilo va en línea.** Compilar un tema entero
    para tres reglas no vale la deuda que deja.

    `loading="eager"`: el marco ya está a la vista cuando el modal abre.
--}}
<div style="
    width: 100%;
    height: 74vh;
    min-height: 24rem;
    overflow: hidden;
    border: 1px solid rgb(228 228 231);
    border-radius: .75rem;
    background: #f4f4f5;
">
    <iframe
        src="{{ $url }}"
        title="Estado de resultados"
        loading="eager"
        style="display: block; width: 100%; height: 100%; border: 0;"
    ></iframe>
</div>
