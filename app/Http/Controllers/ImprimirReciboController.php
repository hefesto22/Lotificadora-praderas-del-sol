<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Pagos\RegistroDeImpresiones;
use App\Domain\ValueObjects\Monto;
use App\Domain\ValueObjects\MontoEnLetras;
use App\Models\BrandingSetting;
use App\Models\Recibo;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

/**
 * El recibo, listo para el papel.
 *
 * ═══ POR QUE ES HTML Y NO UN PDF ═══
 *
 * R10: estos recibos son de uso INTERNO, sin CAI. No hay requisito fiscal de
 * formato ni de archivo, así que lo único que tiene que pasar es que salga por
 * la impresora de la ventanilla. Un HTML con hoja de estilo de impresión hace
 * eso sin agregar una sola dependencia — y **funciona desde el teléfono**, que
 * importa porque los receptores cobran desde el celular (§14).
 *
 * La alternativa era Browsershot, que está en `composer.json` sin usarse:
 * exige Chrome headless en el VPS y falla en producción con mensajes que nadie
 * puede leer con un cliente enfrente. A cinco semanas del 11-sep eso es una
 * dependencia nueva que habría que probar en el servidor real.
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
 */
final readonly class ImprimirReciboController
{
    public function __construct(private RegistroDeImpresiones $impresiones) {}

    public function __invoke(Request $request, Recibo $recibo): View
    {
        Gate::authorize('view', $recibo);

        $recibo->load(['cliente', 'venta', 'compromiso.lote', 'aplicaciones.cuota.compromiso.lote']);

        // `variosLotes`: con un solo lote el rótulo del papel va en singular
        // y el detalle no repite el código en cada renglón.
        return view('documentos.recibo', [
            'recibo'      => $recibo,
            'impresion'   => $this->impresiones->registrar($recibo),
            'emisor'      => $this->emisor(),
            'enLetras'    => MontoEnLetras::de($recibo->montoTotal()),
            'aCapital'    => $recibo->montoACapital(),
            'variosLotes' => $recibo->tocaVariosLotes(),
            'saldo'       => $this->saldoDelLote($recibo),
            'logo'        => $this->logo(),
        ]);
    }

    // ─── Interno ──────────────────────────────────────────────────────

    /**
     * Los datos de la lotificadora, que es quien entrega el recibo.
     *
     * @return array<string, string|null>
     */
    private function emisor(): array
    {
        $datos = config('lotificadora.emisor');

        if (! is_array($datos)) {
            return [];
        }

        $limpio = [];

        foreach (['nombre', 'rtn', 'residencial', 'direccion', 'telefono'] as $clave) {
            $valor = $datos[$clave] ?? null;
            $limpio[$clave] = is_string($valor) && trim($valor) !== '' ? trim($valor) : null;
        }

        return $limpio;
    }

    /**
     * Lo que el lote debe HOY, no lo que debía el día del pago.
     *
     * Va rotulado con la fecha de impresión en el papel a propósito: una copia
     * sacada tres meses después muestra otro número, y sin la fecha al lado
     * parecería que el recibo cambió.
     */
    private function saldoDelLote(Recibo $recibo): ?Monto
    {
        $lote = $recibo->compromiso;

        if ($lote === null) {
            return null;
        }

        $saldo = Monto::cero();

        foreach ($lote->cuotas()->get() as $cuota) {
            $saldo = $saldo->sumar($cuota->saldo());
        }

        return $saldo;
    }

    /**
     * El logo del branding, solo si el archivo existe de verdad.
     *
     * Un `<img>` roto en un documento que se entrega se ve peor que no tener
     * logo, así que se comprueba antes en vez de confiar en la columna.
     */
    private function logo(): ?string
    {
        $ruta = BrandingSetting::current()->getAttribute('logo_path');

        if (! is_string($ruta) || trim($ruta) === '') {
            return null;
        }

        $disco = Storage::disk('public');

        return $disco->exists($ruta) ? $disco->url($ruta) : null;
    }
}
