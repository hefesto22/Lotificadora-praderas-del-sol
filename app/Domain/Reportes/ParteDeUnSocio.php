<?php

declare(strict_types=1);

namespace App\Domain\Reportes;

use App\Domain\ValueObjects\Monto;
use App\Models\Socio;

/**
 * Lo que hay que entregarle a UN socio por ese mes.
 *
 * ═══ SOLO EL MES, Y ES UNA DECISION DE MAURICIO ═══
 *
 * El 24-ago-2026, mirando la primera versión: «nada de qué hay en la caja,
 * solo que muestre el estado de resultados mes a mes, nada de acumulado, y qué
 * hay que entregar».
 *
 * Así que acá no hay saldos históricos: la utilidad del mes se reparte por
 * porcentaje, se descuenta lo que ya se le entregó **imputado a ese mes**, y lo
 * que queda es lo que hay que darle. Un mes cierra y empieza otro.
 *
 * ⚠️ `$porEntregar` y `$entregadoDeMas` son EXCLUYENTES: uno de los dos vale
 * cero siempre. `Monto` no admite negativos —y está bien que no— así que a
 * quien se le adelantó plata no se lo dice un signo menos, se lo dice su propio
 * renglón. En el papel se lee distinto y se entiende sin explicar.
 */
final readonly class ParteDeUnSocio
{
    public function __construct(
        public Socio $socio,
        /** El porcentaje tal cual está en la tabla: 33.5 significa 33.5 %. */
        public Monto $porcentaje,
        /** Su parte de la utilidad de ESTE mes. */
        public Monto $leToca,
        /** Lo que ya se le entregó imputado a ESTE mes. */
        public Monto $entregado,
        public Monto $porEntregar,
        public Monto $entregadoDeMas,
    ) {}

    /**
     * ¿A este socio se le entregó más de lo que le tocaba en el mes?
     *
     * No es un error ni un fraude: pasa cuando se le adelanta plata a cuenta.
     * Lo que sí sería un error es no decirlo, porque entonces el renglón
     * mostraría cero por entregar y parecería que quedó a mano.
     */
    public function estaSobregirado(): bool
    {
        return ! $this->entregadoDeMas->esCero();
    }

    public function nombre(): string
    {
        return (string) $this->socio->getAttribute('nombre');
    }

    /**
     * El porcentaje como sale impreso: «33.5 %», y «20 %» sin el «.0».
     */
    public function porcentajeEscrito(): string
    {
        $crudo = $this->porcentaje->redondeado(1);

        return rtrim(rtrim($crudo, '0'), '.').' %';
    }
}
