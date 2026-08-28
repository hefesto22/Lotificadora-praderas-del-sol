<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Enums\UnidadDeArea;
use App\Domain\Plano\Dxf\OpcionesDeImportacion;
use App\Domain\Plano\Dxf\UnidadDxf;
use RuntimeException;

/**
 * El plano tal como esta IMPRESO, declarado a mano antes de leer nada.
 *
 * ═══ POR QUE SE DECLARA LO QUE EL ARCHIVO YA DICE ═══
 *
 * Porque el 22-ago-2026 la manzana I de Praderas del Sol entro a medias
 * -siete lotes de quince- y NINGUN control lo pudo atrapar. Todos los
 * controles del importador comparan el dibujo de un lote contra SU PROPIO
 * rotulo, y un lote que no se leyo no tiene rotulo que comparar: es
 * invisible para el tablero entero. Siete lotes seguidos numerados del 1
 * al 7 no se leen como media manzana, se leen como una manzana chica. Lo
 * encontro una persona mirando el mapa contra el PDF, quince dias
 * despues.
 *
 * La unica defensa contra eso es un numero que NO salga del archivo. Aca
 * se escribe lo que dice el plano de papel -manzana por manzana- y el
 * seeder se niega a dejar cargado un proyecto que no de exactamente eso.
 * Es la diferencia entre "el archivo se leyo sin errores" y "entro el
 * plano completo", que no son la misma frase.
 *
 * ⚠️ `lotesPorBloque` se cuenta del PLANO IMPRESO, no de la salida de la
 * importacion. Copiar aca el resultado de una corrida convierte el
 * control en un espejo y no sirve para nada.
 */
final readonly class PlanoDeclarado
{
    /**
     * @param string $codigo prefijo de los correlativos: RAL-00000001
     * @param string $archivo ruta del DXF, relativa a la raiz del repo
     * @param array<string, int> $lotesPorBloque lo que dice el plano impreso, manzana por manzana
     * @param float $areaTotal suma de las areas rotuladas, en la unidad del proyecto
     * @param array<string, mixed> $datos columnas extra de `proyectos` (municipio, direccion, ...)
     */
    public function __construct(
        public string $codigo,
        public string $nombre,
        public string $archivo,
        public array $lotesPorBloque,
        public float $areaTotal,
        public string $capaDeLotes = 'LOTES',
        public ?string $capaDeRotulos = null,
        public ?string $capaDeCalles = null,
        public UnidadDeArea $unidad = UnidadDeArea::Metros,
        public string $precioPorUnidad = '0',
        public string $varaEnMetros = '1.000000',
        public float $toleranciaDeArea = 0.05,
        public array $datos = [],
    ) {
        if ($lotesPorBloque === []) {
            throw new RuntimeException(
                "El plano de {$codigo} no declara ni una manzana. ".
                'Sin eso no hay contra que comparar la lectura del DXF.'
            );
        }
    }

    /**
     * Cuantos lotes tiene que haber cuando termine. La suma de las manzanas.
     */
    public function lotes(): int
    {
        return array_sum($this->lotesPorBloque);
    }

    /**
     * Las manzanas del plano, en orden y en mayusculas, como las guarda Bloque.
     *
     * @return array<string, int>
     */
    public function manzanas(): array
    {
        $manzanas = [];

        foreach ($this->lotesPorBloque as $nombre => $cuantos) {
            $manzanas[mb_strtoupper((string) $nombre, 'UTF-8')] = $cuantos;
        }

        ksort($manzanas);

        return $manzanas;
    }

    /**
     * La manzana que se crea primero, y que el importador usa de destino
     * para cualquier lote que llegue sin letra en el rotulo.
     *
     * Existe porque ImportadorDeDxf::importar() arranca de un Bloque: la
     * transformacion al origen se calcula UNA vez sobre el plano entero, y
     * por eso un plano de varias manzanas entra de una sola importacion
     * con `bloquePorRotulo`, nunca partido en un archivo por manzana.
     */
    public function manzanaSemilla(): string
    {
        return (string) array_key_first($this->manzanas());
    }

    /**
     * En metros² la vara del proyecto ES el metro.
     *
     * No es un truco: `Proyecto::varaEnMetros()` devuelve 1.000000 para
     * todo proyecto en metros², mande lo que mande la columna. El area se
     * sigue guardando en `lotes.area_varas` y lo que cambia es con que
     * palabra se escribe. Ver la migracion 2026_08_13_150000.
     */
    public function factorDeVara(): string
    {
        return $this->unidad === UnidadDeArea::Metros ? '1.000000' : $this->varaEnMetros;
    }

    public function opciones(): OpcionesDeImportacion
    {
        return new OpcionesDeImportacion(
            capaDeLotes: $this->capaDeLotes,
            precioVara: $this->precioPorUnidad,
            capaDeRotulos: $this->capaDeRotulos,
            capaDeCalles: $this->capaDeCalles,
            // El DXF de un topografo declara metros o no declara nada; el
            // factor real lo pone factorDeVara(), no esta linea.
            unidad: UnidadDxf::Metros,
            dibujadoEnVaras: false,
            varaEnMetros: $this->factorDeVara(),
            // Un plano de varias manzanas se reparte por la letra del
            // rotulo ("A1", "B-7"). Ver RotuloDxf::bloqueDeLote().
            bloquePorRotulo: true,
            // El AREA sale del rotulo del topografo, no del contorno:
            // un lado curvo entra teselado y el poligono mide de menos.
            // Ver OpcionesDeImportacion::$sufijosDeArea.
            sufijosDeArea: $this->unidadesDelRotulo(),
        );
    }

    /**
     * Las unidades con que este plano rotula sus areas: "m2", "v2", "vr2".
     *
     * Sirven para DOS cosas, y la segunda es la que importa: de aca
     * sale el AREA de cada lote -el numero que escribio el topografo y
     * que va al contrato-, no del contorno dibujado.
     *
     * Es lo que hace posible la RESTA -cuantos rotulos de area trae el
     * archivo contra cuantos lotes quedaron cargados-. Un plano suele
     * rotular las dos, la del pais y la del metro; se cuenta solo la del
     * proyecto para no contar cada lote dos veces.
     *
     * @return list<string>
     */
    public function unidadesDelRotulo(): array
    {
        return $this->unidad === UnidadDeArea::Metros
            ? ['m2', 'm²']
            : ['v2', 'v²', 'vr2', 'vr²'];
    }
}
