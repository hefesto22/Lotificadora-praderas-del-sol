<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Recibo;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;

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
            // Pestaña nueva y no navegación: quien está cobrando no pierde el
            // expediente que tenía abierto, y el diálogo de impresión sale en
            // la pestaña nueva.
            ->url(static fn (Recibo $record): string => route('documentos.recibo', $record))
            ->openUrlInNewTab()
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
            ->label('Imprimir el recibo')
            ->icon(Heroicon::OutlinedPrinter)
            ->url(route('documentos.recibo', $recibo))
            ->openUrlInNewTab();
    }
}
