<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Enums\EstadoVenta;
use App\Domain\ValueObjects\MontoEnLetras;
use App\Models\Compromiso;
use App\Models\Devolucion;
use App\Models\Facturacion;
use App\Models\Proyecto;
use App\Models\Venta;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;

/**
 * El papel de una salida de dinero, listo para la impresora.
 *
 * ═══ CIERRA UN PENDIENTE QUE VENIA DEL 10-AGO ═══
 *
 * La devolución de la seña emitía su número y guardaba sus tres montos desde
 * el 10-ago-2026, pero **el cliente se iba sin papel**: faltaban la ruta, el
 * controlador y la vista. Quedó anotado como pendiente en el traspaso —«se
 * resuelven juntos, en un solo drop»— y este es el drop, porque la rescisión
 * (R22) necesita exactamente el mismo documento.
 *
 * ═══ QUIEN PUEDE ═══
 *
 * No hay `DevolucionPolicy` y no hace falta inventar una: una devolución no
 * tiene pantalla propia en el panel. Se autoriza sobre lo que sí tiene dueño
 * —la venta cuando es una rescisión, el compromiso cuando es una seña—, que
 * además es exactamente la pregunta correcta: quien puede ver ese expediente
 * puede ver su acta.
 *
 * La sesión y la cuenta activa las verifica `UsuarioActivoDelPanel`, que es
 * el middleware de todos los documentos.
 */
final readonly class ImprimirDevolucionController
{
    public function __invoke(Devolucion $devolucion): View
    {
        $devolucion->load(['cliente', 'compromiso.lote', 'compromiso.proyecto.facturacion', 'venta']);

        $venta = $devolucion->venta;
        $compromiso = $devolucion->compromiso;

        if ($venta instanceof Venta) {
            Gate::authorize('view', $venta);
        } elseif ($compromiso instanceof Compromiso) {
            Gate::authorize('view', $compromiso);
        }

        $proyecto = $compromiso?->proyecto;

        return view('documentos.devolucion', [
            'devolucion'    => $devolucion,
            'cliente'       => $devolucion->cliente,
            'identidad'     => $this->identidad($devolucion),
            'codigoDelLote' => $compromiso?->lote?->getAttribute('codigo'),
            'contrato'      => $venta?->getAttribute('numero_contrato'),
            'contratoSigue' => $venta?->getAttribute('estado') === EstadoVenta::Vigente,
            'emisor'        => $this->emisorDe($proyecto),
            'logo'          => $proyecto?->logoUrl(),
            'enLetras'      => MontoEnLetras::de($devolucion->montoDevuelto()),
            'notaDeCredito' => $this->notaDeCredito($devolucion, $proyecto),
        ]);
    }

    // ─── Interno ──────────────────────────────────────────────────────

    /**
     * Qué le toca al contador con lo que se devolvió, o null si no le toca
     * nada.
     *
     * ═══ LA PREGUNTA DE MAURICIO, 14-AGO-2026 ═══
     *
     * «¿Y si factura pero no hacen notas de crédito?». Es el caso normal: esa
     * autorización se tramita aparte de la de facturas —CAI propio, rango
     * propio— y la mayoría nunca la pidió.
     *
     * Las tres respuestas posibles, y ninguna bloquea la rescisión:
     *
     *  1. **El desarrollo no factura** (Praderas) → null, no sale nada. Un
     *     recibo interno NO es un documento fiscal —lo dice el propio papel,
     *     «NO VÁLIDO PARA CRÉDITO FISCAL»— así que ante el SAR no hay nada
     *     que acreditar y la rescisión ya está completa.
     *  2. **Factura y NO emite notas de crédito** → sale el aviso para el
     *     contador. El sistema no puede emitirla, pero sí puede evitar que
     *     nadie se acuerde.
     *  3. **Factura y sí las emite** → sale el aviso de que corresponde una.
     *     El día que exista el módulo, acá se emitirá sola.
     *
     * Y si no se devolvió nada, tampoco sale nada: no hubo qué acreditar. Es
     * el caso de la rescisión por incumplimiento, donde se retiene todo.
     *
     * @return array{monto: string, emite: bool}|null
     */
    private function notaDeCredito(Devolucion $devolucion, ?Proyecto $proyecto): ?array
    {
        if ($devolucion->montoDevuelto()->esCero()) {
            return null;
        }

        $facturacion = $proyecto?->facturacion;

        if (! $facturacion instanceof Facturacion || ! (bool) $facturacion->getAttribute('activa')) {
            return null;
        }

        return [
            'monto' => $devolucion->montoDevuelto()->formateado(),
            'emite' => $facturacion->emiteNotasDeCredito(),
        ];
    }

    /**
     * El documento de quien firma que recibió la plata.
     *
     * Sale del cliente y no del compromiso: el titular del recibo (R8-bis)
     * sirve para que un recibo salga a nombre de otra persona, pero una
     * devolución la cobra el dueño del apartado o del contrato.
     */
    private function identidad(Devolucion $devolucion): ?string
    {
        $dni = $devolucion->cliente?->getAttribute('dni');

        return is_string($dni) && trim($dni) !== '' ? $dni : null;
    }

    /**
     * Quién entrega, tal como sale impreso arriba del papel.
     *
     * PRIMERO el desarrollo y la config solo de respaldo, igual que en el
     * recibo interno. **No pasa por la facturación**, y es a propósito: un
     * acta de rescisión no es un documento fiscal —no lleva CAI, no acredita
     * crédito fiscal y no consume rango—, así que quien la firma es la
     * lotificadora, no el obligado tributario.
     *
     * @return array<string, string|null>
     */
    private function emisorDe(?Proyecto $proyecto): array
    {
        if ($proyecto instanceof Proyecto) {
            $propio = $proyecto->comoEmisor();

            if ($propio['residencial'] !== null) {
                return $propio;
            }
        }

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
}
