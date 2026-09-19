<?php

declare(strict_types=1);

namespace App\Domain\Plano\Dxf;

/**
 * Saca los lotes de un plano dibujado con lineas sueltas.
 *
 * ArmadorDeContornos devuelve TODAS las caras del dibujo: en un plano de
 * verdad, cientos -los lotes, pero tambien cada calle, el cajetin, los
 * recuadros del membrete y hasta el carrito de la seccion tipica de
 * calle-. Aca se decide cuales son lotes, y la regla es una sola:
 *
 *   ES UN LOTE LA CARA QUE TIENE UN NUMERO DE LOTE ADENTRO.
 *
 * No hay heuristica de tamano ni de forma. El topografo ya dijo cuales
 * son los lotes al numerarlos; todo lo demas no tiene numero y no entra.
 *
 * De paso resuelve las otras dos cosas que este estilo de plano trae
 * distintas, y las dos salen del mismo grafo:
 *
 * ═══ LA MANZANA SALE DE LA VECINDAD ═══
 *
 * Estos planos numeran "1", "2", "3" y el nombre de la manzana va en un
 * texto aparte -«BLOQUE A»- tirado en el medio. No hay letra que leer en
 * el rotulo, asi que OpcionesDeImportacion::$bloquePorRotulo no tiene de
 * donde agarrarse. Pero los lotes de una manzana comparten lindero entre
 * si y no con los de la manzana de enfrente, que tiene una calle en el
 * medio: cada isla de lotes pegados es una manzana, y se llama como diga
 * el «BLOQUE X» que cayo adentro de alguno de sus lotes.
 *
 * ═══ LA LINEA SOBRANTE ═══
 *
 * Un trazo que el dibujante dejo cruzando un lote lo parte en dos caras.
 * El numero queda en una mitad, y esa mitad sola mide la mitad de lo que
 * dice el rotulo. Se funde con la cara vecina SOLO si se cumplen las dos:
 * la mitad sola NO mide lo que dice el rotulo, y las dos juntas SI, dentro
 * de la tolerancia. Es el plano confirmandose a si mismo; sin esa suma no
 * se toca nada, porque una cara vecina sin numero puede ser cualquier
 * cosa -la calle, un area verde, el lote de al lado sin rotular-.
 *
 * En uno de los dos planos del 18-sep-2026 habia dos lotes asi, de
 * 1,109.88 varas² cada uno: una mitad dibujaba 586.88 y la otra 521.65.
 */
final readonly class LotesDeLineasSueltas
{
    public function __construct(
        private ArmadorDeContornos $armador = new ArmadorDeContornos,
    ) {}

    /**
     * @param list<array{float, float, float, float}> $segmentos los tramos de la capa de lotes, en unidades del dibujo
     * @param float $tolerancia a que distancia dos extremos son el mismo punto, en unidades del dibujo
     * @param list<array{numero: string, bloque: ?string, x: float, y: float}> $numeros los rotulos con forma de numero de lote
     * @param list<array{area: numeric-string, x: float, y: float}> $areas las areas rotuladas, en la unidad del negocio
     * @param list<array{nombre: string, x: float, y: float}> $bloques los textos «BLOQUE X»
     * @param float $factorDeArea cuantas unidades de area del negocio mide una unidad cuadrada del dibujo
     * @param float $toleranciaDeArea en porcentaje; la misma de Lote::TOLERANCIA_DE_AREA
     * @param string $capa la capa con la que se etiquetan los poligonos que salen
     */
    public function armar(
        array $segmentos,
        float $tolerancia,
        array $numeros,
        array $areas,
        array $bloques,
        float $factorDeArea,
        float $toleranciaDeArea,
        string $capa,
    ): LotesArmados {
        $caras = $this->armador->armar($segmentos, $tolerancia);

        $superficies = [];

        foreach ($caras as $cara) {
            $superficies[] = $cara->area();
        }

        $advertencias = [];

        $numerosEn = $this->numerosPorCara($caras, $superficies, $numeros, $advertencias);
        $lotes = $this->fundirMitades($caras, $superficies, $numerosEn, $areas, $factorDeArea, $toleranciaDeArea, $advertencias);
        $nombres = $this->nombresDeManzana($lotes, $bloques, $advertencias);

        // De norte a sur y de oeste a este. No es estetica: cuando el
        // plano repite un numero, el importador renumera por orden de
        // llegada, y ese orden no puede depender de como se recorrio el
        // grafo.
        $centros = [];

        foreach ($lotes as $i => $contorno) {
            $centros[$i] = GeometriaPlana::centroide($contorno->puntos);
        }

        $orden = array_keys($lotes);

        usort($orden, static fn (int $a, int $b): int => [$centros[$b][1], $centros[$a][0]] <=> [$centros[$a][1], $centros[$b][0]]);

        $poligonos = [];
        $manzanas = [];

        foreach ($orden as $i) {
            if (isset($nombres[$i])) {
                $manzanas[count($poligonos)] = $nombres[$i];
            }

            $poligonos[] = new PoligonoDxf($capa, $lotes[$i]->puntosSinAlineados($tolerancia / 10.0), 'LINEAS');
        }

        return new LotesArmados($poligonos, $manzanas, $advertencias);
    }

    /**
     * Cuantos numeros de lote cayeron en cada cara, contando solo las
     * caras que son de verdad UN lote.
     *
     * A cada numero le toca la cara MAS CHICA que lo contiene: adentro de
     * la cara de la calle estan todas las manzanas, y adentro del marco
     * del plano esta todo.
     *
     * Y por eso mismo, una cara que contiene ademas los numeros de OTROS
     * lotes no es un lote: es lo que rodea a un lote que no llego a
     * cerrar. Ese numero se queda sin contorno y se avisa; importarlo con
     * la forma de la calle seria peor que no importarlo.
     *
     * @param list<ContornoArmado> $caras
     * @param list<float> $superficies
     * @param list<array{numero: string, bloque: ?string, x: float, y: float}> $numeros
     * @param list<string> $advertencias
     *
     * @return array<int, int>
     */
    private function numerosPorCara(array $caras, array $superficies, array $numeros, array &$advertencias): array
    {
        $numerosEn = [];
        $sinContorno = 0;

        foreach ($numeros as $numero) {
            $i = $this->caraMasChica($caras, $superficies, $numero['x'], $numero['y']);

            if ($i === null) {
                $sinContorno++;

                continue;
            }

            $numerosEn[$i] = ($numerosEn[$i] ?? 0) + 1;
        }

        foreach ($numerosEn as $i => $propios) {
            $adentro = 0;

            foreach ($numeros as $numero) {
                if ($caras[$i]->contiene($numero['x'], $numero['y'])) {
                    $adentro++;
                }
            }

            if ($adentro > $propios) {
                $sinContorno += $propios;
                unset($numerosEn[$i]);
            }
        }

        $dobles = count(array_filter($numerosEn, static fn (int $cuantos): bool => $cuantos > 1));

        if ($sinContorno > 0) {
            $advertencias[] = "{$sinContorno} numeros de lote no tienen contorno propio y NO entraron: ".
                'a su lote le falta un lado, o las lineas no llegan a tocarse.';
        }

        if ($dobles > 0) {
            $advertencias[] = "{$dobles} contornos tienen mas de un numero adentro y entro un solo lote por cada uno: ".
                'falta la linea que los separa.';
        }

        return $numerosEn;
    }

    /**
     * El contorno de cada lote, fundiendo los que una linea sobrante
     * partio en dos.
     *
     * @param list<ContornoArmado> $caras
     * @param list<float> $superficies
     * @param array<int, int> $numerosEn
     * @param list<array{area: numeric-string, x: float, y: float}> $areas
     * @param list<string> $advertencias
     *
     * @return array<int, ContornoArmado>
     */
    private function fundirMitades(
        array $caras,
        array $superficies,
        array $numerosEn,
        array $areas,
        float $factorDeArea,
        float $toleranciaDeArea,
        array &$advertencias,
    ): array {
        /** @var array<int, list<float>> $areasEn */
        $areasEn = [];

        foreach ($areas as $area) {
            $i = $this->caraMasChica($caras, $superficies, $area['x'], $area['y']);

            if ($i !== null) {
                $areasEn[$i][] = (float) $area['area'];
            }
        }

        /** @var array<string, list<int>> $carasDelLindero */
        $carasDelLindero = [];

        foreach ($caras as $i => $cara) {
            foreach (array_keys($cara->linderos()) as $lindero) {
                $carasDelLindero[$lindero][] = $i;
            }
        }

        $lotes = [];
        $absorbidas = [];
        $fundidos = 0;

        foreach (array_keys($numerosEn) as $i) {
            $lotes[$i] = $caras[$i];
            $propias = $areasEn[$i] ?? [];

            // El dibujo ya mide lo que dice su rotulo: el lote esta entero.
            if ($this->algunaCoincide($propias, $superficies[$i] * $factorDeArea, $toleranciaDeArea)) {
                continue;
            }

            foreach (array_keys($caras[$i]->linderos()) as $lindero) {
                foreach ($carasDelLindero[$lindero] ?? [] as $j) {
                    if ($j === $i) {
                        continue;
                    }

                    if (isset($numerosEn[$j])) {
                        continue;
                    }

                    if (isset($absorbidas[$j])) {
                        continue;
                    }
                    // El rotulo del area pudo quedar de cualquiera de los
                    // dos lados de la linea sobrante.
                    $rotuladas = [...$propias, ...($areasEn[$j] ?? [])];
                    $juntas = ($superficies[$i] + $superficies[$j]) * $factorDeArea;

                    if (! $this->algunaCoincide($rotuladas, $juntas, $toleranciaDeArea)) {
                        continue;
                    }

                    $fundido = $caras[$i]->unidoCon($caras[$j]);

                    if (! $fundido instanceof ContornoArmado) {
                        continue;
                    }

                    $lotes[$i] = $fundido;
                    $absorbidas[$j] = true;
                    $fundidos++;

                    continue 3;
                }
            }
        }

        if ($fundidos > 0) {
            $advertencias[] = "{$fundidos} lotes venian partidos en dos por una linea sobrante y se unieron, ".
                'porque sus dos mitades suman el area que dice el rotulo. Miralos en el plano.';
        }

        return $lotes;
    }

    /**
     * El nombre de la manzana de cada lote, para los que tienen una.
     *
     * @param array<int, ContornoArmado> $lotes
     * @param list<array{nombre: string, x: float, y: float}> $bloques
     * @param list<string> $advertencias
     *
     * @return array<int, string>
     */
    private function nombresDeManzana(array $lotes, array $bloques, array &$advertencias): array
    {
        if ($bloques === []) {
            return [];
        }

        /** @var array<int, int> $padre */
        $padre = [];

        foreach (array_keys($lotes) as $i) {
            $padre[$i] = $i;
        }

        /** @var array<string, int> $duenio el primer lote que paso por cada lindero */
        $duenio = [];

        foreach ($lotes as $i => $contorno) {
            foreach (array_keys($contorno->linderos()) as $lindero) {
                if (isset($duenio[$lindero])) {
                    $padre[$this->raiz($padre, $i)] = $this->raiz($padre, $duenio[$lindero]);
                } else {
                    $duenio[$lindero] = $i;
                }
            }
        }

        /** @var array<int, string> $nombreDeLaIsla */
        $nombreDeLaIsla = [];

        foreach ($bloques as $bloque) {
            $lote = array_find_key($lotes, fn ($contorno) => $contorno->contiene($bloque['x'], $bloque['y']));

            if ($lote === null) {
                $advertencias[] = "El rotulo «BLOQUE {$bloque['nombre']}» no cayo adentro de ningun lote ".
                    'y no le puso nombre a ninguna manzana.';

                continue;
            }

            $isla = $this->raiz($padre, $lote);
            $yaTenia = $nombreDeLaIsla[$isla] ?? null;

            if ($yaTenia !== null && $yaTenia !== $bloque['nombre']) {
                $advertencias[] = "Una misma manzana trae dos nombres, {$yaTenia} y {$bloque['nombre']}, y se quedo con {$yaTenia}. ".
                    'Suele ser una calle dibujada con una sola linea: los lotes de los dos lados quedan pegados.';

                continue;
            }

            $nombreDeLaIsla[$isla] = $bloque['nombre'];
        }

        $nombres = [];

        foreach (array_keys($lotes) as $i) {
            $isla = $this->raiz($padre, $i);

            if (isset($nombreDeLaIsla[$isla])) {
                $nombres[$i] = $nombreDeLaIsla[$isla];
            }
        }

        return $nombres;
    }

    /**
     * @param array<int, int> $padre
     */
    private function raiz(array &$padre, int $i): int
    {
        while ($padre[$i] !== $i) {
            $padre[$i] = $padre[$padre[$i]];
            $i = $padre[$i];
        }

        return $i;
    }

    /**
     * La cara de menor superficie que contiene al punto, o null.
     *
     * @param list<ContornoArmado> $caras
     * @param list<float> $superficies
     */
    private function caraMasChica(array $caras, array $superficies, float $x, float $y): ?int
    {
        $mejor = null;

        foreach ($caras as $i => $cara) {
            if ($mejor !== null && $superficies[$i] >= $superficies[$mejor]) {
                continue;
            }

            if ($cara->contiene($x, $y)) {
                $mejor = $i;
            }
        }

        return $mejor;
    }

    /**
     * @param list<float> $rotuladas
     */
    private function algunaCoincide(array $rotuladas, float $medida, float $toleranciaDeArea): bool
    {
        return array_any($rotuladas, fn (float $rotulada): bool => $rotulada > 0.0 && abs($medida - $rotulada) / $rotulada * 100.0 <= $toleranciaDeArea);
    }
}
