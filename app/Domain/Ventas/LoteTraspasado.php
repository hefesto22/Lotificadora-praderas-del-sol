<?php

declare(strict_types=1);

namespace App\Domain\Ventas;

use App\Domain\ValueObjects\Monto;

/**
 * Un lote antes y después de un traspaso de valor.
 *
 * Una clase y no un `array{...}`: cruza del dominio al comando y a la
 * bitácora, y una forma declarada se pierde en el primer `array` a secas.
 */
final readonly class LoteTraspasado
{
    public function __construct(
        public string $codigo,
        public Monto $valorAntes,
        public Monto $valorDespues,
        public Monto $saldoAntes,
        public Monto $saldoDespues,
        public int $cuotasAntes,
        public int $cuotasDespues,
        public ?Monto $cuotaAntes,
        public ?Monto $cuotaDespues,
    ) {}

    public function cambiaDeValor(): bool
    {
        return ! $this->valorAntes->igualA($this->valorDespues);
    }
}
