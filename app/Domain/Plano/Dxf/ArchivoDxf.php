<?php

declare(strict_types=1);

namespace App\Domain\Plano\Dxf;

/**
 * Un DXF ya parseado: sus entidades y las variables del HEADER.
 */
final readonly class ArchivoDxf
{
    /**
     * @param list<EntidadDxf> $entidades
     * @param array<string, list<array{int, string}>> $header
     */
    public function __construct(
        public array $entidades,
        public array $header,
    ) {}

    public function unidades(): UnidadDxf
    {
        return UnidadDxf::desde($this->variableEntera('$INSUNITS'));
    }

    public function variableEntera(string $nombre): ?int
    {
        foreach ($this->header[$nombre] ?? [] as [$codigo, $valor]) {
            if (is_numeric(trim($valor))) {
                return (int) (float) trim($valor);
            }
        }

        return null;
    }

    /**
     * Entidades de la seccion ENTITIES, que es la geometria del dibujo.
     *
     * Lo que vive en BLOCKS son definiciones reutilizables que solo
     * aparecen en el dibujo a traves de un INSERT, con su propia
     * traslacion, rotacion y escala. Importarlas como si estuvieran en
     * coordenadas del mundo pondria los lotes en el lugar equivocado, asi
     * que se dejan afuera y se avisa.
     *
     * @return list<EntidadDxf>
     */
    public function delDibujo(): array
    {
        return array_values(array_filter(
            $this->entidades,
            static fn (EntidadDxf $entidad): bool => $entidad->seccion === 'ENTITIES'
        ));
    }

    /**
     * @return list<EntidadDxf>
     */
    public function deTipo(string ...$tipos): array
    {
        return array_values(array_filter(
            $this->delDibujo(),
            static fn (EntidadDxf $entidad): bool => in_array($entidad->tipo, $tipos, true)
        ));
    }

    /**
     * Cuantos INSERT hay: geometria que existe en el dibujo pero que este
     * importador no expande todavia. Se reporta para que nadie crea que
     * el plano se importo completo cuando no fue asi.
     */
    public function bloquesInsertados(): int
    {
        return count($this->deTipo('INSERT'));
    }

    /**
     * Capas realmente usadas por la geometria, con cuantas entidades tiene
     * cada una. Es lo que se le muestra al usuario para que elija.
     *
     * Se leen de las entidades y no de la tabla LAYER porque una capa
     * declarada y vacia no le sirve a nadie para mapear.
     *
     * @return array<string, int>
     */
    public function capasUsadas(): array
    {
        $capas = [];

        foreach ($this->delDibujo() as $entidad) {
            $capa = $entidad->capa();
            $capas[$capa] = ($capas[$capa] ?? 0) + 1;
        }

        arsort($capas);

        return $capas;
    }

    /**
     * @return array<string, int>
     */
    public function conteoPorTipo(): array
    {
        $tipos = [];

        foreach ($this->delDibujo() as $entidad) {
            $tipos[$entidad->tipo] = ($tipos[$entidad->tipo] ?? 0) + 1;
        }

        arsort($tipos);

        return $tipos;
    }
}
