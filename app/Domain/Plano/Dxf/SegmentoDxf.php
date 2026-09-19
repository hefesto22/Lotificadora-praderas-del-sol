<?php

declare(strict_types=1);

namespace App\Domain\Plano\Dxf;

/**
 * Un tramo recto del dibujo, en las unidades del dibujo.
 *
 * Es la materia prima de los planos que NO traen los lotes como
 * polilineas cerradas: una LINE suelta, un lado de una polilinea abierta
 * o un pedacito de un arco ya teselado. Ver ArmadorDeContornos.
 */
final readonly class SegmentoDxf
{
    public function __construct(
        public string $capa,
        public float $x1,
        public float $y1,
        public float $x2,
        public float $y2,
    ) {}

    /**
     * @return array{float, float, float, float}
     */
    public function extremos(): array
    {
        return [$this->x1, $this->y1, $this->x2, $this->y2];
    }
}
