<?php

declare(strict_types=1);

namespace App\Domain\Plano\Dxf;

/**
 * Lo que el sistema entendio del archivo, ANTES de crear nada.
 *
 * Existe para que la importacion sea un paso con vista previa y no una
 * apuesta: el usuario ve cuantos contornos hay en cada capa y cuanta area
 * suman, y recien ahi decide.
 */
final readonly class AnalisisDeDxf
{
    /**
     * @param array<string, array{contornos: int, rotulos: int, area: float}> $capas
     * @param array{float, float, float, float}|null $caja
     * @param array<string, int> $tipos
     */
    public function __construct(
        public UnidadDxf $unidadDeclarada,
        public array $capas,
        public array $tipos,
        public ?array $caja,
        public int $bloquesInsertados,
        public int $contornosEspejados,
    ) {}

    /**
     * @return list<string>
     */
    public function nombresDeCapas(): array
    {
        return array_keys($this->capas);
    }

    /**
     * Capa que mas parece contener los lotes.
     *
     * Primero por nombre, contra el vocabulario que se usa de verdad en
     * los planos de lotificacion. Si ninguno coincide, la que tenga mas
     * contornos cerrados: en un plano de lotificacion, los lotes son
     * siempre lo mas numeroso.
     */
    public function capaSugeridaDeLotes(): ?string
    {
        return $this->porVocabulario(['lote', 'lotes', 'lotificacion', 'lotizacion', 'parcela', 'predio', 'terreno', 'solar', 'manzana'])
            ?? $this->conMasContornos();
    }

    public function capaSugeridaDeCalles(): ?string
    {
        return $this->porVocabulario(['calle', 'calles', 'via', 'vias', 'vial', 'calzada', 'avenida', 'pasaje', 'acera', 'vereda']);
    }

    public function capaSugeridaDeRotulos(): ?string
    {
        $porNombre = $this->porVocabulario(['texto', 'textos', 'rotulo', 'rotulos', 'numero', 'numeracion', 'etiqueta', 'nomenclatura']);

        if ($porNombre !== null) {
            return $porNombre;
        }

        $mejor = null;
        $tope = 0;

        foreach ($this->capas as $capa => $datos) {
            if ($datos['rotulos'] > $tope) {
                $tope = $datos['rotulos'];
                $mejor = $capa;
            }
        }

        return $mejor;
    }

    /**
     * @param list<string> $vocabulario
     */
    private function porVocabulario(array $vocabulario): ?string
    {
        foreach (array_keys($this->capas) as $capa) {
            $normal = OpcionesDeImportacion::normalizar($capa);

            foreach ($vocabulario as $palabra) {
                if (str_contains($normal, OpcionesDeImportacion::normalizar($palabra))) {
                    return $capa;
                }
            }
        }

        return null;
    }

    private function conMasContornos(): ?string
    {
        $mejor = null;
        $tope = 0;

        foreach ($this->capas as $capa => $datos) {
            if ($datos['contornos'] > $tope) {
                $tope = $datos['contornos'];
                $mejor = $capa;
            }
        }

        return $mejor;
    }
}
