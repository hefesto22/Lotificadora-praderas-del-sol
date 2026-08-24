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

    .olympo-nota { margin-top: .5rem; font-size: .75rem; line-height: 1.65; color: rgb(113 113 122); }
    .dark .olympo-nota { color: rgb(161 161 170); }
    .olympo-vacio { font-size: .875rem; color: rgb(113 113 122); }
    .dark .olympo-vacio { color: rgb(161 161 170); }
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
