<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Documentos\PapelDelRecibo;
use App\Models\Recibo;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Varios recibos, en una sola pasada de impresora.
 *
 * ═══ POR QUE EXISTE (9-sep-2026) ═══
 *
 * «Al pagar debería de abrirse de una la ventana para imprimir los recibos»
 * —Mauricio, mirando cuatro notificaciones apiladas después de un solo cobro—.
 *
 * Un cobro sale en un recibo POR TITULAR (`RegistroDePagos::agruparPorNombre()`),
 * así que el contrato de cinco lotes con cuatro representados emite cuatro
 * papeles de un solo pago. Con la ruta de a uno eso son cuatro clics y cuatro
 * diálogos que hay que cerrar, con el cliente enfrente.
 *
 * Acá salen los cuatro juntos: **una hoja por recibo**, un solo diálogo, una
 * pasada de impresora, y cada titular se lleva el suyo. Nunca se apretujan dos
 * titulares en una hoja — es papel de otra persona.
 *
 * ⚠️ Sirve igual para UNO. Eso es a propósito: quien dispara la impresión
 * después de cobrar usa siempre esta ruta y no tiene que decidir nada según
 * cuántos papeles salieron.
 *
 * ═══ ABRIR ESTO ES IMPRIMIR, UNA VEZ POR RECIBO ═══
 *
 * Cada hoja anota su impresión y cada una dice COPIA por su cuenta a partir de
 * la segunda vez. Es lo correcto: salieron cuatro papeles, no uno — y la
 * pregunta que `impresiones_de_recibo` contesta es cuántas veces se pidió CADA
 * papel, no cuántas veces se abrió una pantalla.
 *
 * ═══ EL PERMISO SE PREGUNTA POR CADA UNO ═══
 *
 * 🔴 Y antes de preparar ninguno. Autorizar sobre la marcha dejaría anotadas
 * las impresiones de los primeros y recién ahí cortaría: filas escritas por un
 * documento que nunca se entregó. Un id ajeno metido a mano en la barra de
 * direcciones no imprime nada.
 */
final readonly class ImprimirRecibosController
{
    /**
     * Cuántos papeles como mucho. No es una regla de negocio: es el freno de
     * una URL que cualquiera puede escribir a mano. El cobro más grande que
     * este sistema emitió salió en cinco.
     */
    private const int TOPE = 20;

    public function __construct(private PapelDelRecibo $papel) {}

    public function __invoke(Request $request): View
    {
        $recibos = $this->pedidos($request);

        abort_if($recibos->isEmpty(), 404);

        // 🔴 TODOS los permisos primero: ver el docblock de la clase.
        foreach ($recibos as $recibo) {
            Gate::authorize('view', $recibo);
        }

        $papeles = [];

        foreach ($recibos as $recibo) {
            $papeles[] = $this->papel->de($recibo);
        }

        return view('documentos.recibos', ['papeles' => $papeles]);
    }

    /**
     * Los recibos que pide la URL, en el orden en que se emitieron.
     *
     * Por id y no por el orden de la lista: las hojas salen apiladas como
     * salieron los correlativos, que es como se archivan y como se revisan
     * después. El orden de un parámetro que alguien escribió a mano no dice
     * nada.
     *
     * @return Collection<int, Recibo>
     */
    private function pedidos(Request $request): Collection
    {
        $crudo = $request->query('recibos');
        $ids = [];

        foreach (explode(',', is_string($crudo) ? $crudo : '') as $trozo) {
            $trozo = trim($trozo);

            if (preg_match('/^[1-9]\d{0,17}$/', $trozo) === 1) {
                $ids[] = (int) $trozo;
            }
        }

        return Recibo::query()
            ->whereKey(array_slice(array_unique($ids), 0, self::TOPE))
            ->orderBy('id')
            ->get();
    }
}
