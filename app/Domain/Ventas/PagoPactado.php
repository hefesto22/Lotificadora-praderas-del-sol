<?php

declare(strict_types=1);

namespace App\Domain\Ventas;

use App\Domain\ValueObjects\Monto;
use Carbon\CarbonImmutable;

/**
 * Un pago que el cliente se comprometio a hacer en UNA FECHA concreta.
 *
 * Es el renglon de un plan que no se calcula: se pacta. Nace el 05-sep-2026,
 * pedido por Mauricio mirando el expediente de ALMA HONORIA LARA TABORA —
 * L 1,150,000.00 de lote, L 1,000,000.00 de prima el dia de la firma y «el
 * resto lo paga el 20 de febrero de 2027»—. Eso no es un plazo de N meses ni
 * una venta de contado, y hasta hoy el sistema no tenia como escribirlo.
 *
 * ═══ ES CAPITAL, NO ES LO QUE PAGA ═══
 *
 * `$capital` es cuanto del SALDO cancela este pago, y es el numero que se
 * pacta con el cliente. Si el contrato lleva interes, lo que el cliente
 * entrega ese dia es capital + el interes devengado desde el pago anterior,
 * y eso lo calcula `PlanDeCuotas::aFechasPactadas()`.
 *
 * Se pacta el capital y no el total por una razon: **la suma de los capitales
 * tiene que dar el saldo exacto**, y esa es la invariante que el sistema ya
 * verifica en todos los planes (`PlanDeCuotas::cierraExacto()`). Si lo pactado
 * fuera el total, un renglon que no alcanzara a cubrir su propio interes
 * dejaria el plan sin cerrar y habria que rehacerlo con el cliente enfrente.
 * Asi, lo que se pacta siempre cierra y el interes es un agregado que se
 * muestra.
 */
final readonly class PagoPactado
{
    public function __construct(
        public CarbonImmutable $fecha,
        public Monto $capital,
    ) {}

    /**
     * El mismo pago con el capital ajustado al centimo.
     *
     * La columna es NUMERIC(14,2) y quien llena el formulario puede escribir
     * mas decimales. Se ajusta ACA y no al guardar, para que la suma que
     * verifica el plan sea la misma que va a la base: redondear despues de
     * verificar es como se cuelan los planes que cierran en pantalla y no
     * cierran en la tabla.
     */
    public function alCentimo(): self
    {
        return new self($this->fecha, new Monto($this->capital->redondeado()));
    }
}
