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
     * 🔴 Las claves de `$capas` y de `$tramosPorCapa` son `int|string`, y no
     * es un descuido del tipo. La capa por defecto de AutoCAD se llama «0»,
     * y PHP guarda como ENTERO cualquier clave de texto que parezca un
     * numero: `$capas['0']` queda con la clave 0. Con `strict_types`, pasar
     * esa clave a un parametro `string` es un TypeError -un 500 al subir el
     * archivo-, y devolverla de un metodo `?string` tambien. Por eso cada
     * lectura de una clave de capa pasa por nombre().
     *
     * Vivio escondido hasta el 18-sep-2026 porque en los planos anteriores
     * la capa «0» no tenia ni contornos ni textos. En un plano dibujado con
     * lineas sueltas TODO esta en la «0».
     *
     * @param array<int|string, array{contornos: int, rotulos: int, area: float}> $capas
     * @param array{float, float, float, float}|null $caja
     * @param array<string, int> $tipos
     * @param array<int|string, int> $tramosPorCapa cuantos tramos sueltos -LINE, lados de polilinea,
     *                                              arcos- tiene cada capa
     */
    public function __construct(
        public UnidadDxf $unidadDeclarada,
        public array $capas,
        public array $tipos,
        public ?array $caja,
        public int $bloquesInsertados,
        public int $contornosEspejados,
        public array $tramosPorCapa = [],
    ) {}

    /**
     * @return list<string>
     */
    public function nombresDeCapas(): array
    {
        return array_map($this->nombre(...), array_keys($this->capas));
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

    /**
     * La capa de donde armar los lotes cuando el plano los trae dibujados
     * con lineas sueltas: la que tenga mas tramos.
     *
     * Aca el vocabulario no ayuda. Quien dibuja con lineas sueltas suele
     * dejar todo en la capa «0», y la que se llama «NUMERO SOLAR» es la de
     * los rotulos, no la de los lotes.
     */
    public function capaSugeridaDeTramos(): ?string
    {
        $mejor = null;
        $tope = 0;

        foreach ($this->tramosPorCapa as $capa => $cuantos) {
            if ($cuantos > $tope) {
                $tope = $cuantos;
                $mejor = $this->nombre($capa);
            }
        }

        return $mejor;
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
                $mejor = $this->nombre($capa);
            }
        }

        return $mejor;
    }

    /**
     * @param list<string> $vocabulario
     */
    private function porVocabulario(array $vocabulario): ?string
    {
        foreach ($this->nombresDeCapas() as $capa) {
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
                $mejor = $this->nombre($capa);
            }
        }

        return $mejor;
    }

    /**
     * El nombre de la capa a partir de su clave. Ver el constructor.
     */
    private function nombre(int|string $clave): string
    {
        return (string) $clave;
    }
}
