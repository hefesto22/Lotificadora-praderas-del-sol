<?php

declare(strict_types=1);

namespace App\Domain\Plano\Dxf;

/**
 * Que se creo al importar, y que hay que mirar despues.
 */
final readonly class ResultadoDeImportacion
{
    /**
     * @param list<string> $advertencias
     */
    public function __construct(
        public int $lotesCreados,
        public int $callesCreadas,
        public int $sinRotulo,
        public int $descartados,
        public float $areaTotalVaras,
        public array $advertencias,
    ) {}

    public function huboAlgo(): bool
    {
        return $this->lotesCreados > 0 || $this->callesCreadas > 0;
    }
}
