<?php

declare(strict_types=1);

namespace App\Domain\Plano\Dxf;

/**
 * Arma contornos cerrados a partir de lineas sueltas.
 *
 * ═══ POR QUE EXISTE ═══
 *
 * Porque no todos los topografos dibujan cada lote como una polilinea
 * cerrada. El 18-sep-2026 llegaron dos planos del mismo ingeniero -83 y
 * 95 lotes- sin UN solo contorno cerrado: el perimetro de cada manzana es
 * una polilinea abierta y las divisiones entre lotes son LINE sueltas que
 * mueren contra ese perimetro. En papel se ve igual que cualquier otro
 * plano; para el importador eran cero lotes.
 *
 * Pedirle al ingeniero que redibuje no es una respuesta: el plano ya esta
 * firmado asi, y el proximo cliente va a traer el suyo igual.
 *
 * ═══ COMO ═══
 *
 * Es el problema clasico de hallar las caras de un grafo plano:
 *
 *  1. Cada tramo se CORTA donde otro lo toca o lo cruza. La division
 *     entre dos lotes muere en el medio del lado de la manzana; sin
 *     cortar ese lado ahi, el grafo no sabe que se tocan.
 *  2. Los extremos que quedan a menos de la tolerancia se funden en un
 *     solo nodo. Un dibujante cierra "a ojo" con el snap apagado mas
 *     seguido de lo que parece: en uno de los dos planos habia huecos de
 *     entre 5 y 10 milimetros, invisibles en pantalla, que con tolerancia
 *     cero fundian dos lotes en uno.
 *  3. Se podan los tramos colgantes -los que no encierran nada-.
 *  4. Se recorre cada arista en sus dos sentidos doblando siempre lo mas
 *     a la derecha posible: cada vuelta completa es una cara. Las de area
 *     positiva son las de adentro; la negativa es el borde de afuera de
 *     cada isla del dibujo, y se descarta.
 *
 * Devuelve TODAS las caras: los lotes, pero tambien las calles, el
 * cajetin y cada recuadro del membrete. Decidir cuales son lotes no es
 * geometria y no vive aca; ver LotesDeLineasSueltas.
 *
 * Todo en float a proposito, igual que GeometriaPlana: son coordenadas de
 * un dibujo, no dinero.
 */
final readonly class ArmadorDeContornos
{
    /** Por debajo de esto una cara no es un contorno: es ruido de redondeo. */
    private const float AREA_MINIMA = 1e-6;

    /**
     * @param list<array{float, float, float, float}> $segmentos [x1, y1, x2, y2], en unidades del dibujo
     * @param float $tolerancia a que distancia dos extremos son el mismo punto
     *
     * @return list<ContornoArmado>
     */
    public function armar(array $segmentos, float $tolerancia): array
    {
        $utiles = [];

        foreach ($segmentos as $segmento) {
            // Un tramo mas corto que la tolerancia se funde en un solo
            // nodo: no aporta ningun lado y si puede aportar un nudo.
            if (hypot($segmento[2] - $segmento[0], $segmento[3] - $segmento[1]) > $tolerancia) {
                $utiles[] = $segmento;
            }
        }

        if (count($utiles) < 3) {
            return [];
        }

        $cortes = $this->cortes($utiles, $tolerancia);

        /** @var list<array{float, float}> $puntos */
        $puntos = [];
        /** @var array<string, list<int>> $rejilla */
        $rejilla = [];
        /** @var array<int, array<int, true>> $vecinos */
        $vecinos = [];

        foreach ($utiles as $i => [$x1, $y1, $x2, $y2]) {
            $parametros = $cortes[$i];
            sort($parametros);

            $anterior = null;

            foreach ($parametros as $t) {
                $nodo = $this->nodo(
                    $x1 + ($t * ($x2 - $x1)),
                    $y1 + ($t * ($y2 - $y1)),
                    $tolerancia,
                    $puntos,
                    $rejilla,
                );

                if ($anterior !== null && $anterior !== $nodo) {
                    $vecinos[$anterior][$nodo] = true;
                    $vecinos[$nodo][$anterior] = true;
                }

                $anterior = $nodo;
            }
        }

        return $this->caras($puntos, $this->sinColgantes($vecinos));
    }

    /**
     * En que puntos hay que partir cada tramo, como fraccion de su largo.
     *
     * Se barre de izquierda a derecha: ordenados por donde empiezan, un
     * tramo solo se compara con los que arrancan antes de que el termine.
     * Sin eso son todos contra todos, y un plano con el dibujo de un
     * arbolito en el membrete -mil quinientos tramos de centimetros- son
     * un millon de comparaciones para nada.
     *
     * @param list<array{float, float, float, float}> $segmentos
     *
     * @return array<int, list<float>>
     */
    private function cortes(array $segmentos, float $tolerancia): array
    {
        $cortes = [];
        $cajas = [];

        foreach ($segmentos as [$x1, $y1, $x2, $y2]) {
            $cortes[] = [0.0, 1.0];
            $cajas[] = [min($x1, $x2), min($y1, $y2), max($x1, $x2), max($y1, $y2)];
        }

        $orden = array_keys($segmentos);
        usort($orden, static fn (int $a, int $b): int => $cajas[$a][0] <=> $cajas[$b][0]);

        $total = count($orden);

        for ($p = 0; $p < $total; $p++) {
            $i = $orden[$p];
            $hasta = $cajas[$i][2] + $tolerancia;

            for ($q = $p + 1; $q < $total; $q++) {
                $j = $orden[$q];

                if ($cajas[$j][0] > $hasta) {
                    break;
                }

                if ($cajas[$j][1] > $cajas[$i][3] + $tolerancia) {
                    continue;
                }

                if ($cajas[$j][3] < $cajas[$i][1] - $tolerancia) {
                    continue;
                }

                $uno = $segmentos[$i];
                $otro = $segmentos[$j];

                // El caso de todos los dias: un tramo MUERE contra otro.
                foreach ([[$otro[0], $otro[1]], [$otro[2], $otro[3]]] as [$x, $y]) {
                    $t = $this->parametroSobre($uno, $x, $y, $tolerancia);

                    if ($t !== null) {
                        $cortes[$i][] = $t;
                    }
                }

                foreach ([[$uno[0], $uno[1]], [$uno[2], $uno[3]]] as [$x, $y]) {
                    $t = $this->parametroSobre($otro, $x, $y, $tolerancia);

                    if ($t !== null) {
                        $cortes[$j][] = $t;
                    }
                }

                // Y el raro: dos tramos que se CRUZAN sin que ninguno
                // termine en el otro.
                $cruce = $this->cruce($uno, $otro);

                if ($cruce !== null) {
                    $cortes[$i][] = $cruce[0];
                    $cortes[$j][] = $cruce[1];
                }
            }
        }

        return $cortes;
    }

    /**
     * A que fraccion del tramo cae el punto, si es que cae SOBRE el.
     *
     * @param array{float, float, float, float} $segmento
     */
    private function parametroSobre(array $segmento, float $x, float $y, float $tolerancia): ?float
    {
        [$x1, $y1, $x2, $y2] = $segmento;

        $dx = $x2 - $x1;
        $dy = $y2 - $y1;
        $largo = ($dx * $dx) + ($dy * $dy);

        if ($largo < 1e-18) {
            return null;
        }

        $t = max(0.0, min(1.0, ((($x - $x1) * $dx) + (($y - $y1) * $dy)) / $largo));

        return hypot($x - ($x1 + ($t * $dx)), $y - ($y1 + ($t * $dy))) <= $tolerancia ? $t : null;
    }

    /**
     * Donde se cruzan dos tramos por el medio, como fraccion de cada uno.
     *
     * @param array{float, float, float, float} $uno
     * @param array{float, float, float, float} $otro
     *
     * @return array{float, float}|null
     */
    private function cruce(array $uno, array $otro): ?array
    {
        [$x1, $y1, $x2, $y2] = $uno;
        [$x3, $y3, $x4, $y4] = $otro;

        $divisor = (($x2 - $x1) * ($y4 - $y3)) - (($y2 - $y1) * ($x4 - $x3));

        // Paralelos: o no se tocan, o se pisan y ya los partio parametroSobre().
        if (abs($divisor) < 1e-12) {
            return null;
        }

        $enUno = ((($x3 - $x1) * ($y4 - $y3)) - (($y3 - $y1) * ($x4 - $x3))) / $divisor;
        $enOtro = ((($x3 - $x1) * ($y2 - $y1)) - (($y3 - $y1) * ($x2 - $x1))) / $divisor;

        if ($enUno <= 0.0 || $enUno >= 1.0 || $enOtro <= 0.0 || $enOtro >= 1.0) {
            return null;
        }

        return [$enUno, $enOtro];
    }

    /**
     * El nodo que le toca a ese punto: uno que ya exista a menos de la
     * tolerancia, o uno nuevo.
     *
     * La rejilla tiene celdas del tamano de la tolerancia, asi que un
     * vecino a menos de esa distancia esta en la misma celda o en una de
     * las ocho que la rodean. Buscar entre todos los nodos seria
     * cuadratico sobre el plano entero.
     *
     * @param list<array{float, float}> $puntos
     * @param array<string, list<int>> $rejilla
     */
    private function nodo(float $x, float $y, float $tolerancia, array &$puntos, array &$rejilla): int
    {
        $columna = (int) floor($x / $tolerancia);
        $fila = (int) floor($y / $tolerancia);

        for ($dc = -1; $dc <= 1; $dc++) {
            for ($df = -1; $df <= 1; $df++) {
                foreach ($rejilla[($columna + $dc).':'.($fila + $df)] ?? [] as $candidato) {
                    if (hypot($puntos[$candidato][0] - $x, $puntos[$candidato][1] - $y) <= $tolerancia) {
                        return $candidato;
                    }
                }
            }
        }

        $puntos[] = [$x, $y];
        $nuevo = count($puntos) - 1;
        $rejilla[$columna.':'.$fila][] = $nuevo;

        return $nuevo;
    }

    /**
     * El grafo sin los tramos que no encierran nada.
     *
     * Una linea que sale de un lote y muere en el aire -una cota, una
     * flecha, el trazo de una calle que sigue fuera del plano- no separa
     * nada de nada, pero al recorrer las caras se la caminaria de ida y de
     * vuelta y el contorno saldria con una espiga. Se podan de a una hasta
     * que todo nodo tenga al menos dos vecinos.
     *
     * @param array<int, array<int, true>> $vecinos
     *
     * @return array<int, array<int, true>>
     */
    private function sinColgantes(array $vecinos): array
    {
        $pendientes = [];

        foreach ($vecinos as $nodo => $suyos) {
            if (count($suyos) < 2) {
                $pendientes[] = $nodo;
            }
        }

        while ($pendientes !== []) {
            $nodo = array_pop($pendientes);

            if (! isset($vecinos[$nodo])) {
                continue;
            }

            if (count($vecinos[$nodo]) >= 2) {
                continue;
            }

            $suyos = array_keys($vecinos[$nodo]);
            unset($vecinos[$nodo]);

            foreach ($suyos as $otro) {
                unset($vecinos[$otro][$nodo]);

                if (count($vecinos[$otro] ?? []) < 2) {
                    $pendientes[] = $otro;
                }
            }
        }

        return $vecinos;
    }

    /**
     * Las caras de adentro del grafo.
     *
     * @param list<array{float, float}> $puntos
     * @param array<int, array<int, true>> $vecinos
     *
     * @return list<ContornoArmado>
     */
    private function caras(array $puntos, array $vecinos): array
    {
        /** @var array<int, list<int>> $enOrden los vecinos de cada nodo, por angulo */
        $enOrden = [];
        /** @var array<int, array<int, int>> $lugar en que posicion de esa lista esta cada vecino */
        $lugar = [];

        foreach ($vecinos as $nodo => $suyos) {
            [$x, $y] = $puntos[$nodo];
            $lista = array_keys($suyos);

            usort($lista, static fn (int $a, int $b): int => atan2($puntos[$a][1] - $y, $puntos[$a][0] - $x)
                <=> atan2($puntos[$b][1] - $y, $puntos[$b][0] - $x));

            $enOrden[$nodo] = $lista;
            $lugar[$nodo] = array_flip($lista);
        }

        $recorridas = [];
        $caras = [];

        foreach ($enOrden as $desde => $lista) {
            foreach ($lista as $hacia) {
                if (isset($recorridas[$desde.'>'.$hacia])) {
                    continue;
                }

                $nodos = [];
                $a = $desde;
                $b = $hacia;

                while (! isset($recorridas[$a.'>'.$b])) {
                    $recorridas[$a.'>'.$b] = true;
                    $nodos[] = $a;

                    // Llegando a $b desde $a, se sale por el vecino que
                    // sigue a $a girando en sentido horario: es doblar lo
                    // mas a la derecha posible, que deja el interior de la
                    // cara siempre a la izquierda.
                    $salidas = $enOrden[$b];
                    $cuantas = count($salidas);
                    $c = $salidas[($lugar[$b][$a] - 1 + $cuantas) % $cuantas];

                    $a = $b;
                    $b = $c;
                }

                $contorno = [];

                foreach ($nodos as $nodo) {
                    $contorno[] = $puntos[$nodo];
                }

                if ($this->areaConSigno($contorno) > self::AREA_MINIMA) {
                    $caras[] = new ContornoArmado($contorno, $nodos);
                }
            }
        }

        return $caras;
    }

    /**
     * Positiva si el contorno gira en sentido antihorario.
     *
     * GeometriaPlana::area() devuelve el valor absoluto, y aca el signo es
     * justo lo que se necesita: separa las caras de adentro del borde de
     * afuera de cada isla.
     *
     * Se mide desde el primer punto y no desde el origen: un plano en UTM
     * tiene coordenadas de siete digitos, y multiplicarlas entre si se come
     * los decimales que despues deciden si una cara minuscula es positiva.
     *
     * @param list<array{float, float}> $puntos
     */
    private function areaConSigno(array $puntos): float
    {
        $total = count($puntos);

        if ($total < 3) {
            return 0.0;
        }

        [$origenX, $origenY] = $puntos[0];
        $suma = 0.0;

        for ($i = 0; $i < $total; $i++) {
            [$x1, $y1] = $puntos[$i];
            [$x2, $y2] = $puntos[($i + 1) % $total];

            $suma += (($x1 - $origenX) * ($y2 - $origenY)) - (($x2 - $origenX) * ($y1 - $origenY));
        }

        return $suma / 2.0;
    }
}
