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
     * @param array<string, int> $lotesPorBloque cuantos lotes cayo en cada bloque
     * @param list<string> $bloquesCreados los que no existian y nacieron con la importacion
     * @param int $sinAreaRotulada cuantos entraron con el area CALCULADA del contorno porque
     *                             el plano no se la rotulaba (ver OpcionesDeImportacion)
     */
    public function __construct(
        public int $lotesCreados,
        public int $callesCreadas,
        public int $sinRotulo,
        public int $descartados,
        public float $areaTotalVaras,
        public array $advertencias,
        public array $lotesPorBloque = [],
        public array $bloquesCreados = [],
        public int $sinAreaRotulada = 0,
    ) {}

    /**
     * "36 en A, 7 en B, 8 en C" — el reparto, para el aviso de pantalla.
     */
    public function repartoEnTexto(): string
    {
        $partes = [];

        foreach ($this->lotesPorBloque as $nombre => $cuantos) {
            $partes[] = "{$cuantos} en {$nombre}";
        }

        return implode(', ', $partes);
    }

    public function huboAlgo(): bool
    {
        return $this->lotesCreados > 0 || $this->callesCreadas > 0;
    }
}
