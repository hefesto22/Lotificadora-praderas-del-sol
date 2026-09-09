<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Livewire\Component;

/**
 * 🔴 El diálogo de impresión, solo, apenas se emite el papel — 9-sep-2026.
 *
 * «Al pagar debería de abrirse de una la ventana para imprimir los recibos»
 * —Mauricio, mirando cuatro notificaciones apiladas después de UN cobro—.
 *
 * ═══ POR QUE NO ABRE UNA VENTANA ═══
 *
 * Porque una ventana la bloquea el navegador. Un `window.open()` que no nace
 * de un clic —y este nace de una respuesta de Livewire— es exactamente lo que
 * Chrome frena, y frenarlo se ve como una barrita arriba que nadie mira:
 * quedaría PEOR que el botón de hoy, porque además nadie se enteraría.
 *
 * `window.olympoImprimir()` no abre nada: carga el documento en un iframe
 * escondido y manda a imprimir ahí. Eso no es un pop-up, así que no hay nada
 * que bloquear. Ya existía desde el 14-ago —«que no se abra una nueva ventana
 * al presionar imprimir»— y esto es la misma idea un paso más allá: ni
 * siquiera hay que apretar el botón.
 *
 * ═══ UNA SOLA LLAMADA, SIEMPRE ═══
 *
 * 🔴 `olympoImprimir()` tiene UN iframe y lo reemplaza en cada llamada. Dos
 * llamadas seguidas no imprimen dos papeles: imprimen el último, o una hoja en
 * blanco. Quien tenga varios documentos que sacar necesita UNA url que los
 * traiga a todos — para los recibos es `documentos.recibos`, que apila una
 * hoja por cada uno.
 *
 * ⚠️ Los botones de imprimir se QUEDAN donde están. Esto es JavaScript, y el
 * papel que el cliente está esperando del otro lado del mostrador no puede
 * depender de que el JavaScript haya cargado. Si esto no corre, el botón de la
 * notificación sigue ahí y hace lo mismo.
 */
final class AbrirLaImpresion
{
    public static function de(string $url, ?Component $pantalla): void
    {
        if (! $pantalla instanceof Component) {
            return;
        }

        /*
         * `js()` y no un evento del navegador: corre una sola vez, cuando esta
         * respuesta llega, y no queda ningún listener escuchando por si acaso.
         *
         * La url se codifica con `json_encode` en vez de meterla entre
         * comillas a mano — es la misma razón de siempre: una url lleva
         * comillas, barras y ampersands, y armar JavaScript pegando texto es
         * como se rompe una pantalla con un dato que parecía inofensivo.
         */
        $pantalla->js(sprintf(
            'window.olympoImprimir && window.olympoImprimir(%s)',
            json_encode($url, JSON_THROW_ON_ERROR),
        ));
    }
}
