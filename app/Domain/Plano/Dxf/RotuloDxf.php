<?php

declare(strict_types=1);

namespace App\Domain\Plano\Dxf;

/**
 * Un texto del plano con su posicion: el candidato a numero de lote.
 */
final readonly class RotuloDxf
{
    public function __construct(
        public string $capa,
        public string $texto,
        public float $x,
        public float $y,
        public float $altura,
    ) {}

    /**
     * El texto reducido a lo que puede ser un numero de lote.
     *
     * Los planos rotulan de todo: "12", "LOTE 12", "L-12", "12\n250 m2".
     * Se busca el primer numero con su sufijo opcional de letra, que es el
     * formato que ya admite el sistema ("12", "12-A", "12B").
     */
    public function numeroDeLote(): ?string
    {
        $limpio = trim(preg_replace('/\s+/u', ' ', $this->texto) ?? '');

        if ($limpio === '') {
            return null;
        }

        if (preg_match('/(\d+)\s*[-\s]?\s*([A-Za-z])?(?![\d.,])/u', $limpio, $partes) !== 1) {
            return null;
        }

        // El grupo de la letra es opcional: si no participo, preg_match ni
        // siquiera lo define. Se normaliza a cadena vacia antes de mirarlo.
        $letra = $partes[2] ?? '';

        return $partes[1].($letra === '' ? '' : '-'.mb_strtoupper($letra, 'UTF-8'));
    }
}
