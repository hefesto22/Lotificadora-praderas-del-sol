<?php

declare(strict_types=1);

namespace App\Domain\Pagos;

use App\Domain\ValueObjects\Monto;

/**
 * El antes y el después de UN lote en la re-imputación de un recibo.
 *
 * Se arma con lo que quedó en la base —no con una cuenta aparte—, así que es
 * lo mismo lo que imprime el ensayo y lo que después se escribe. Salen TODOS
 * los lotes del expediente y no solo los que reciben dinero: el lote que lo
 * pierde es justamente el que amanece con cuotas vencidas, y eso tiene que
 * verse antes de escribir.
 */
final readonly class LoteReimputado
{
    /**
     * @param Monto $imputadoAntes lo que el recibo viejo le había puesto a este lote
     * @param Monto $imputadoDespues lo que le ponen los recibos nuevos
     * @param Monto $aCuotas de lo nuevo, cuánto fue a cuotas
     * @param Monto $aCapital de lo nuevo, cuánto bajó capital
     * @param int $vencidasAntes cuotas vencidas sin pagar antes de mover nada
     * @param int $vencidasDespues las que quedan vencidas con el reparto nuevo
     */
    public function __construct(
        public string $codigo,
        public Monto $imputadoAntes,
        public Monto $imputadoDespues,
        public Monto $aCuotas,
        public Monto $aCapital,
        public Monto $saldoAntes,
        public Monto $saldoDespues,
        public int $vencidasAntes,
        public int $vencidasDespues,
        public Monto $debeVencidoDespues,
    ) {}

    public function cambia(): bool
    {
        return ! $this->imputadoAntes->igualA($this->imputadoDespues);
    }
}
