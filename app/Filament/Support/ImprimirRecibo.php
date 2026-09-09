<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Recibo;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Livewire\Component;

/**
 * El botón de imprimir, uno solo para las tres pantallas donde aparece.
 *
 * Vive acá y no copiado en la tabla, en la pestaña del expediente y en la
 * ficha, porque **abrir esa URL registra una impresión**: si mañana hay que
 * cambiar cómo se llega al papel —una confirmación, un permiso propio, otro
 * destino— tiene que cambiar en un solo lugar o alguna de las tres se queda
 * abriendo la vieja.
 */
final class ImprimirRecibo
{
    public static function accion(): Action
    {
        return Action::make('imprimir')
            ->label('Imprimir')
            ->icon(Heroicon::OutlinedPrinter)
            ->color('gray')
            /*
             * Sin pestaña: el documento se carga en un iframe escondido y el
             * dialogo de impresion sale ahi mismo. Pedido de Mauricio el
             * 14-ago-2026 — quien cobra imprime veinte veces al dia y cada
             * una le dejaba una pestaña que despues hay que cerrar.
             *
             * La `url` se conserva como respaldo: si el JS no cargo, el
             * enlace sigue siendo un enlace y abre el documento. Alpine
             * intercepta el clic solo cuando puede.
             */
            ->url(static fn (Recibo $record): string => route('documentos.recibo', $record))
            ->openUrlInNewTab()
            ->extraAttributes(static fn (Recibo $record): array => [
                'x-on:click.prevent' => sprintf(
                    "window.olympoImprimir && window.olympoImprimir('%s')",
                    route('documentos.recibo', $record),
                ),
            ])
            ->visible(static fn (Recibo $record): bool => auth()->user()?->can('view', $record) === true);
    }

    /**
     * La misma acción, para una notificación que ya sabe de qué recibo habla.
     *
     * En la notificación no hay `$record` que inyectar —no está montada sobre
     * una fila— así que la URL se arma con el recibo concreto.
     */
    public static function enNotificacion(Recibo $recibo): Action
    {
        return Action::make('imprimir')
            /*
             * «El recibo» o «la factura», segun lo que salio. La notificacion
             * aparece justo despues de cobrar, y llamarle recibo a una
             * factura ahi es la primera vez que alguien se entera de que el
             * papel es otro — conviene que no sea equivocandose.
             */
            ->label('Imprimir '.($recibo->esFactura() ? 'la factura' : 'el recibo'))
            ->icon(Heroicon::OutlinedPrinter)
            ->url(route('documentos.recibo', $recibo))
            ->openUrlInNewTab()
            ->extraAttributes([
                'x-on:click.prevent' => sprintf(
                    "window.olympoImprimir && window.olympoImprimir('%s')",
                    route('documentos.recibo', $recibo),
                ),
            ]);
    }

    /**
     * Los recibos que ACABAN de emitirse, mandados a imprimir de una pasada.
     *
     * El porqué —y por qué no es una ventana— está en `AbrirLaImpresion`. Acá
     * queda lo propio de los recibos: son N y se piden en UNA url, porque
     * `olympoImprimir()` tiene un solo iframe y llamarla cuatro veces
     * imprimiría el último papel y nada más.
     *
     * `documentos.recibos` sirve igual para uno solo, y eso es a propósito:
     * quien cobra no tiene que decidir nada según cuántos salieron.
     *
     * @param list<Recibo> $recibos
     */
    public static function alEmitir(array $recibos, ?Component $pantalla): void
    {
        if ($recibos === []) {
            return;
        }

        $ids = [];

        foreach ($recibos as $recibo) {
            $ids[] = (string) $recibo->getKey();
        }

        AbrirLaImpresion::de(
            route('documentos.recibos', ['recibos' => implode(',', $ids)]),
            $pantalla,
        );
    }
}
