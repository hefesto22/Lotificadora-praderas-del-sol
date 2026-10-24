<?php

declare(strict_types=1);

namespace App\Domain\Ventas;

use App\Domain\ValueObjects\Monto;

/**
 * Lo que dejó —o dejaría, en el ensayo— un traspaso de valor.
 */
final readonly class TraspasoHecho
{
    /**
     * @param list<LoteTraspasado> $lotes
     * @param list<array{folio: string, monto: Monto, antes: array<string, Monto>, despues: array<string, Monto>}> $recibos cuánto le tocó a cada lote, por código
     */
    public function __construct(
        public array $lotes,
        public array $recibos,
    ) {}

    public function saldoAntes(): Monto
    {
        $total = Monto::cero();

        foreach ($this->lotes as $lote) {
            $total = $total->sumar($lote->saldoAntes);
        }

        return $total;
    }

    public function saldoDespues(): Monto
    {
        $total = Monto::cero();

        foreach ($this->lotes as $lote) {
            $total = $total->sumar($lote->saldoDespues);
        }

        return $total;
    }
}
