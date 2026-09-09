<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Documentos\PapelDelRecibo;
use App\Models\Recibo;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;

/**
 * El recibo, listo para el papel.
 *
 * ═══ POR QUE ES HTML Y NO UN PDF ═══
 *
 * Porque lo único que tiene que pasar es que salga por la impresora de la
 * ventanilla. Un HTML con hoja de estilo de impresión hace eso sin agregar una
 * sola dependencia — y **funciona desde el teléfono**, que importa porque los
 * receptores cobran desde el celular (§14).
 *
 * ⚠️ Desde el 14-ago-2026 esta misma vista imprime FACTURAS con CAI, así que
 * ya no es cierto que no haya requisito de formato: el Acuerdo 481-2017,
 * Art. 10 pide una lista de datos concreta. Lo que sigue sin haber es
 * requisito de ARCHIVO — el SAR no exige PDF—, y el formato lo cumple el
 * bloque fiscal de la plantilla.
 *
 * La alternativa era Browsershot, que está en `composer.json` sin usarse:
 * exige Chrome headless en el VPS y falla en producción con mensajes que nadie
 * puede leer con un cliente enfrente.
 *
 * El documento no es el papel: es la fila en `recibos`, con su número (R12) y
 * su detalle de aplicación. El papel es una vista de eso, reimprimible.
 *
 * ═══ ABRIR ESTA VISTA ES IMPRIMIR ═══
 *
 * Cada visita queda anotada en `impresiones_de_recibo`, y de la segunda en
 * adelante el papel dice COPIA. Si alguien abre y cancela el diálogo del
 * navegador, la fila queda igual — y está bien: lo que se registra es que una
 * persona pidió el papel, que es la pregunta que importa cuando aparecen dos
 * con el mismo número. Para solo mirar está la ficha del recibo en el panel,
 * que no imprime nada.
 *
 * ═══ QUIEN PUEDE ═══
 *
 * La sesión y la cuenta activa las verifica `UsuarioActivoDelPanel`, que es el
 * middleware de todos los documentos; acá solo queda el permiso concreto,
 * `View:Recibo`, que es lo único que cambia de un documento a otro.
 *
 * ⚠️ Los datos los prepara `PapelDelRecibo` desde el 9-sep-2026, porque los
 * comparte con `ImprimirRecibosController` —el que saca los varios recibos de
 * un mismo cobro en una sola pasada—. Acá quedan el permiso y la vista, que es
 * lo único que este documento decide.
 */
final readonly class ImprimirReciboController
{
    public function __construct(private PapelDelRecibo $papel) {}

    public function __invoke(Recibo $recibo): View
    {
        Gate::authorize('view', $recibo);

        return view('documentos.recibo', $this->papel->de($recibo));
    }
}
