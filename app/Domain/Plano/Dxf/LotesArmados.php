<?php

declare(strict_types=1);

namespace App\Domain\Plano\Dxf;

/**
 * Lo que salio de armar los lotes de un plano dibujado con lineas sueltas.
 */
final readonly class LotesArmados
{
    /**
     * @param list<PoligonoDxf> $poligonos los lotes, de norte a sur y de oeste a este
     * @param array<int, string> $manzanas el bloque de cada poligono, por su posicion en la lista;
     *                                     falta el de los que quedaron en una manzana sin nombre
     * @param list<string> $advertencias
     */
    public function __construct(
        public array $poligonos,
        public array $manzanas,
        public array $advertencias,
    ) {}
}
