<?php

declare(strict_types=1);

namespace App\Domain\Plano\Dxf;

/**
 * Un contorno cerrado leido del plano, en las unidades del dibujo.
 */
final readonly class PoligonoDxf
{
    /**
     * @param list<array{float, float}> $puntos
     */
    public function __construct(
        public string $capa,
        public array $puntos,
        public string $origen,
        public bool $espejado = false,
    ) {}

    public function area(): float
    {
        return GeometriaPlana::area($this->puntos);
    }

    public function perimetro(): float
    {
        return GeometriaPlana::perimetro($this->puntos);
    }

    public function contiene(float $x, float $y): bool
    {
        return GeometriaPlana::contiene($this->puntos, $x, $y);
    }

    /**
     * @return array{float, float}
     */
    public function centro(): array
    {
        return GeometriaPlana::centro($this->puntos);
    }

    public function vertices(): int
    {
        return count($this->puntos);
    }
}
