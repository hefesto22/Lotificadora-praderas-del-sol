<?php

declare(strict_types=1);

namespace App\Domain\Plano\Dxf;

use App\Domain\Exceptions\ValueObjectInvalidoException;
use App\Domain\Plano\ValidaMedidas;

/**
 * Que hacer con cada capa del DXF y en que unidad esta dibujado.
 *
 * La unidad se pide SIEMPRE, aunque el archivo la declare. Motivo: el
 * $INSUNITS de los planos de topografia viene en cero con muchisima
 * frecuencia —el dibujante nunca lo configuro— y adivinar "seguro son
 * metros" es exactamente la clase de suposicion que despues sale impresa
 * como un area equivocada en una escritura.
 *
 * `dibujadoEnVaras` existe porque en Honduras no es raro que el topografo
 * dibuje directamente en varas. Si es asi, no hay conversion que hacer y
 * el factor de la vara —que sigue pendiente de confirmar, ver pregunta 16
 * de docs/dominio.md— no toca el area de ningun lote.
 *
 * `sufijosDeArea` decide de donde sale el AREA de cada lote. Vacio -el
 * default- la calcula del contorno, que es lo unico posible cuando el
 * plano no la rotula. Con las unidades adentro -['m2'], ['v2','vr2']- se
 * lee el numero que escribio el topografo, y ESE es el que se guarda.
 *
 * La diferencia no es cosmetica: un lote con un lado curvo entra con el
 * arco teselado, y una poligonal inscrita encierra siempre MENOS area que
 * el arco. El G-7 de Altamira dice 314.16 m2 en el plano y su contorno da
 * 314.02. El area multiplica al precio y sale impresa en la escritura, asi
 * que cuando el plano la dice, la dice el plano. Ver RotuloDxf::areaRotulada().
 *
 * ⚠️ Se piden las unidades y no un booleano porque un plano rotula las DOS
 * -«A=200.00m2» y «286.85v2» en el mismo lote- y hay que tomar la del
 * proyecto, o cada lote entra con el area de la otra unidad.
 *
 * `bloquePorRotulo` es para los planos que traen la manzana pegada al
 * numero ("A1", "B-7"): con la opcion prendida, cada lote entra en el
 * bloque que dice su rotulo y el bloque elegido en el formulario pasa a
 * ser solo el destino de los que no traigan letra. APAGADA por defecto y
 * a proposito: el rotulo que el propio sistema dibuja en el mapa es
 * `numero.bloque` ("12B"), y leer eso como bloque "12" seria peor que no
 * leer nada. Ver RotuloDxf::bloqueDeLote().
 */
final readonly class OpcionesDeImportacion
{
    use ValidaMedidas;

    /** @var numeric-string */
    public string $precioVara;

    /** @var numeric-string */
    public string $varaEnMetros;

    /**
     * @param list<string> $sufijosDeArea las unidades del rotulo de area: ['m2'], ['v2', 'vr2']
     */
    public function __construct(
        public string $capaDeLotes,
        string $precioVara,
        public ?string $capaDeRotulos = null,
        public ?string $capaDeCalles = null,
        public UnidadDxf $unidad = UnidadDxf::Metros,
        public bool $dibujadoEnVaras = false,
        string $varaEnMetros = '0.8359',
        public bool $bloquePorRotulo = false,
        public array $sufijosDeArea = [],
    ) {
        if (trim($capaDeLotes) === '') {
            throw ValueObjectInvalidoException::paraCampo(
                campo: 'capaDeLotes',
                valor: $capaDeLotes,
                razon: 'Hay que decir de que capa del DXF salen los lotes.'
            );
        }

        $this->precioVara = $this->medidaNoNegativa('precioVara', $precioVara);
        $this->varaEnMetros = $this->medidaPositiva('varaEnMetros', $varaEnMetros);
    }

    /**
     * Cuantas varas mide una unidad del dibujo.
     *
     * Si el plano ya esta en varas, uno. Si no: metros por unidad dividido
     * metros por vara.
     */
    public function varasPorUnidad(): float
    {
        if ($this->dibujadoEnVaras) {
            return 1.0;
        }

        $enMetros = $this->unidad->enMetros();

        if ($enMetros === null) {
            throw ValueObjectInvalidoException::paraCampo(
                campo: 'unidad',
                valor: $this->unidad->name,
                razon: 'El archivo no declara unidades y no se eligio ninguna. '.
                       'Hay que decir en que esta dibujado el plano antes de importarlo.'
            );
        }

        return $enMetros / (float) $this->varaEnMetros;
    }

    public function usaCapa(?string $capa, string $contra): bool
    {
        return $capa !== null && self::normalizar($capa) === self::normalizar($contra);
    }

    public function leeElAreaDelRotulo(): bool
    {
        return $this->sufijosDeArea !== [];
    }

    /**
     * Nombre de capa comparable: sin acentos, sin mayusculas y sin los
     * separadores que cada dibujante usa a su gusto.
     */
    public static function normalizar(string $capa): string
    {
        $sinAcentos = strtr(
            mb_strtolower(trim($capa), 'UTF-8'),
            ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u']
        );

        return (string) preg_replace('/[\s_\-.]+/u', '', $sinAcentos);
    }
}
