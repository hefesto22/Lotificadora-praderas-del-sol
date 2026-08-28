<?php

declare(strict_types=1);

namespace App\Domain\Plano\Dxf;

/**
 * Un texto del plano con su posicion: el candidato a numero de lote.
 */
final readonly class RotuloDxf
{
    /**
     * La forma de un rotulo de lote, entera y de una sola pasada.
     *
     * Se exige que la linea COMPLETA tenga esta forma, y ahi esta la
     * diferencia con la version que se cayo el 13-ago-2026: buscar "el
     * primer numero que aparezca" en cualquier parte del texto convertia
     * "A=157.63m2" en el lote 63 y "17.40m" en el lote 40-M. En un plano de
     * verdad, adentro de cada lote hay tres o cuatro textos —el numero, el
     * area en m2, el area en varas2 y las medidas de los lados— y gana el
     * que quede mas cerca del centro. Con la regla vieja, de 84 lotes de
     * EL BAMBU quedaba UNO con el numero correcto.
     *
     * Las tres partes:
     *  - `bloque`: la letra de la manzana cuando el plano la pega al
     *    numero ("A1", "B-7"). Ver bloqueDeLote().
     *  - `numero`: lo unico obligatorio.
     *  - `sufijo`: la letra de una subdivision posterior ("12-A", "12B"),
     *    que es formato que el sistema ya admitia y se conserva.
     */
    private const string FORMA_DE_ROTULO = '/^(?<bloque>\p{L}{1,4})?[\s\-.:#]*(?<numero>\d{1,4})[\s\-]*(?<sufijo>\p{L})?$/u';

    /**
     * Lo que en un plano quiere decir "lote" y NO es el nombre de un bloque.
     *
     * Una sola "L" no esta en la lista a proposito: "L-12" y "L12" se leen
     * igual de bien como bloque L, y el numero —que es lo que este metodo
     * viene prometiendo desde siempre— sale 12 en los dos casos.
     */
    private const array PALABRAS_DE_LOTE = ['LOTE', 'LOT', 'LT'];

    public function __construct(
        public string $capa,
        public string $texto,
        public float $x,
        public float $y,
        public float $altura,
    ) {}

    /**
     * El numero de lote que dice el rotulo, si es que dice uno.
     *
     * Los planos rotulan de todo: "12", "LOTE 12", "L-12", "A1",
     * "12\n250 m2". Todos esos dan "12" —o "1" para el A1—; un area
     * ("A=157.63m2"), una medida ("17.40m") o un nombre ("AREA MUNICIPAL")
     * dan null, que es lo que hace que no le roben el numero al lote.
     */
    public function numeroDeLote(): ?string
    {
        $partes = $this->partes();

        return $partes === null ? null : $partes['numero'];
    }

    /**
     * La manzana que nombra el rotulo cuando la trae pegada al numero.
     *
     * "A1" -> "A", "B-7" -> "B", "12" -> null. Es lo que le permite a UNA
     * sola importacion repartir el plano entero en sus bloques, que es la
     * unica manera de que la transformacion al origen sea compartida y los
     * bloques no queden apilados uno encima del otro.
     *
     * Ojo con la ambiguedad al reves: el rotulo que el sistema dibuja EN EL
     * MAPA es `numero.bloque` ("12B", ver Lote::componerRotulo()), asi que
     * un plano exportado desde el propio sistema se leeria como el lote
     * "12-B". No se resuelve solo, y por eso esto es una OPCION de la
     * importacion y no el comportamiento por defecto.
     */
    public function bloqueDeLote(): ?string
    {
        $partes = $this->partes();

        return $partes === null ? null : $partes['bloque'];
    }

    /**
     * El AREA que dice el rotulo, tal como la escribio el topografo.
     *
     * ═══ POR QUE EL NUMERO IMPRESO Y NO EL DEL DIBUJO ═══
     *
     * Porque el area es lo que se vende: multiplica al precio y sale
     * impresa en la escritura. Y el area que se saca del contorno NO puede
     * ser exacta cuando el lote tiene un lado curvo: el arco entra
     * teselado y una poligonal inscrita siempre encierra MENOS que el arco
     * -ver GeometriaPlana::GRADOS_POR_SEGMENTO-.
     *
     * Medido sobre RESIDENCIAL ALTAMIRA el 25-ago-2026: el lote G-7 dice
     * 314.16 m2 en el plano y el poligono da 314.02. Cuatro centesimas de
     * porciento que no se pueden defender delante de un comprador con el
     * plano del ingeniero en la mano. Mauricio: «no esta dando medidas
     * exactas, tiene que ser exacto».
     *
     * Devuelve STRING y no float a proposito: es una medida que despues
     * entra a bcmath (§8.3.1), y «314.16» es exactamente 314.16 mientras
     * que el float no.
     *
     * ⚠️ El numero viene YA en la unidad del negocio -m2 o varas2 segun
     * que sufijo se pida- y por eso NO se le aplica la transformacion del
     * dibujo. El contorno esta en unidades de CAD; el rotulo, en las del
     * contrato.
     *
     * @param list<string> $sufijos las unidades que cuentan: ['m2'] o ['v2', 'vr2']
     *
     * @return numeric-string|null
     */
    public function areaRotulada(array $sufijos): ?string
    {
        if ($sufijos === []) {
            return null;
        }

        $unidades = implode('|', array_map(
            static fn (string $sufijo): string => preg_quote($sufijo, '/'),
            $sufijos,
        ));

        $forma = '/^\s*(?:A\s*=\s*)?([\d.,]+)\s*(?:'.$unidades.')\s*$/iu';

        foreach ($this->lineas() as $linea) {
            if (preg_match($forma, $linea, $partes) !== 1) {
                continue;
            }

            // Los separadores de miles del dibujante: «1,234.56».
            $numero = str_replace(',', '', $partes[1]);

            if (! is_numeric($numero)) {
                continue;
            }

            return $numero;
        }

        return null;
    }

    /**
     * El rotulo descompuesto, leyendo linea por linea.
     *
     * Linea por linea y no sobre el texto entero porque el numero suele
     * venir arriba y el area abajo, en el mismo MTEXT: aplastar los saltos
     * de linea con espacios haria que la linea completa nunca calce.
     *
     * @return array{bloque: ?string, numero: string}|null
     */
    private function partes(): ?array
    {
        foreach ($this->lineas() as $limpia) {

            if (preg_match(self::FORMA_DE_ROTULO, $limpia, $partes) !== 1) {
                continue;
            }

            /*
             * `bloque` SIEMPRE viene definido —vacio si no participo—
             * porque un grupo posterior si calzo, y PHP rellena los del
             * medio. `sufijo` va al final: si no participo, no esta.
             *
             * La diferencia no es cosmetica: PHPStan lee la forma real que
             * devuelve este preg_match y marca `nullCoalesce.offset` en el
             * `??` que sobra. Cambiar la regex puede cambiar cual de los
             * dos es cual.
             */
            $bloque = mb_strtoupper($partes['bloque'], 'UTF-8');
            $sufijo = mb_strtoupper($partes['sufijo'] ?? '', 'UTF-8');

            if (in_array($bloque, self::PALABRAS_DE_LOTE, true)) {
                $bloque = '';
            }

            return [
                'bloque' => $bloque === '' ? null : $bloque,
                'numero' => $partes['numero'].($sufijo === '' ? '' : '-'.$sufijo),
            ];
        }

        return null;
    }

    /**
     * El texto partido en lineas limpias y sin las vacias.
     *
     * Linea por linea y no sobre el texto entero porque el numero suele
     * venir arriba y el area abajo, en el mismo MTEXT: aplastar los saltos
     * de linea con espacios haria que ninguna de las dos formas calce.
     *
     * @return list<string>
     */
    private function lineas(): array
    {
        $partido = preg_split('/\R/u', $this->texto);

        if (! is_array($partido)) {
            $partido = [$this->texto];
        }

        $lineas = [];

        foreach ($partido as $linea) {
            $limpia = trim((string) preg_replace('/\s+/u', ' ', (string) $linea));

            if ($limpia !== '') {
                $lineas[] = $limpia;
            }
        }

        return $lineas;
    }
}
