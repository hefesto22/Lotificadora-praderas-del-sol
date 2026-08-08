<?php

declare(strict_types=1);

namespace App\Domain\Ventas;

use App\Domain\ValueObjects\Monto;
use App\Models\Lote;
use App\Models\PlanDePago;
use App\Models\Proyecto;

/**
 * Cuánto vale la vara² HOY, para un plazo dado.
 *
 * ═══ POR QUE ESTO NO PUEDE SER `lotes.precio_vara` A SECAS ═══
 *
 * El lote tiene un precio propio, que es el que se fija por proyecto o por
 * bloque. Pero desde el 5-ago-2026 el precio depende del PLAZO, y esa lista
 * vive en `planes_de_pago` (R1 sigue en pie: no es interés, son precios de
 * lista distintos).
 *
 * Si el precio de lista se siguiera leyendo del lote, vender de contado a
 * L 1,300 cuando el lote está fijado en L 1,500 contaría como descuento y
 * el sistema pediría motivo por escrito (R4) — para un precio de lista
 * oficial. El precio contra el que se mide un descuento es **el del plan
 * que se eligió**, no el de la ficha del lote.
 *
 * Sin plan cargado para ese plazo, manda el del lote. Es lo que había antes
 * y sigue siendo verdad mientras la lista esté vacía.
 */
final readonly class ListaDePrecios
{
    /**
     * El precio del plan de ese plazo, o null si el proyecto no lo ofrece.
     *
     * Solo mira los planes ACTIVOS: uno apagado dejó de ofrecerse, y cotizar
     * con él sería vender algo que la administración ya retiró.
     */
    public function paraPlazo(Proyecto $proyecto, int $meses): ?Monto
    {
        return $this->planParaPlazo($proyecto, $meses)?->montoPrecioVara();
    }

    /**
     * El plan entero de ese plazo, no solo su precio.
     *
     * Desde el 8-ago-2026 el plan trae ademas la tasa de interes y las
     * condiciones de mora (§8.5), y las tres cosas se congelan juntas en el
     * compromiso al firmar. Devolver el modelo y no tres valores sueltos es
     * lo que impide que alguien copie el precio y se olvide de la tasa.
     *
     * Solo planes ACTIVOS, igual que `paraPlazo()`: uno apagado dejo de
     * ofrecerse y cotizar con el seria vender algo que ya se retiro.
     */
    public function planParaPlazo(Proyecto $proyecto, int $meses): ?PlanDePago
    {
        return PlanDePago::query()
            ->where('proyecto_id', $proyecto->getKey())
            ->where('meses', $meses)
            ->activos()
            ->first();
    }

    /**
     * El precio contra el que se mide si hubo descuento.
     *
     * Es el del plan elegido; si no hay plan para ese plazo, el del lote.
     */
    public function deListaPara(Proyecto $proyecto, Lote $lote, int $meses): Monto
    {
        $delPlan = $this->paraPlazo($proyecto, $meses);

        if ($delPlan instanceof Monto) {
            return $delPlan;
        }

        $propio = $lote->getAttribute('precio_vara');

        return new Monto(is_string($propio) || is_int($propio) ? $propio : '0');
    }
}
