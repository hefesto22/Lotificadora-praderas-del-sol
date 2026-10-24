<?php

declare(strict_types=1);

namespace App\Domain\Reportes;

use App\Domain\Enums\FormaDePago;
use App\Domain\ValueObjects\Monto;
use Carbon\CarbonImmutable;

/**
 * Un renglón de la caja: lo que entró un día.
 *
 * Una clase y no un `array{...}`: el renglón cruza del cálculo a la pantalla y
 * de ahí a la vista, y una forma declarada se pierde en el primer parámetro
 * que dice `array` a secas. Con una clase el tipo viaja solo.
 */
final readonly class DiaDeCaja
{
    /**
     * @param array<string, Monto> $porForma clave: `FormaDePago::value`, solo las que tuvieron algo
     */
    public function __construct(
        public CarbonImmutable $fecha,
        public int $recibos,
        public array $porForma,
        public Monto $total,
    ) {}

    public function deLaForma(FormaDePago $forma): Monto
    {
        return $this->porForma[$forma->value] ?? Monto::cero();
    }
}
