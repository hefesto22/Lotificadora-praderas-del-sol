<?php

declare(strict_types=1);

namespace App\Domain\Plano\Dxf;

/**
 * Geometria de poligonos planos. Sin estado y sin dependencias.
 *
 * Todo en float a proposito: son coordenadas de un dibujo, no dinero. El
 * area que sale de aca sirve para proponer y para conciliar; el area que
 * se cobra sigue siendo la de `lotes.area_varas`, en varas y con bcmath.
 */
final class GeometriaPlana
{
    /**
     * Grados de arco por segmento al aproximar una curva.
     *
     * El numero NO es cosmetico. Una poligonal inscrita siempre encierra
     * MENOS area que el arco, y en un lote importado de un DXF esa area es
     * la que despues multiplica el precio por vara. Medido sobre un
     * semicirculo de radio 5:
     *
     *   12 grados (15 segmentos) → 0.73 % de area de menos
     *    3 grados (60 segmentos) → 0.036 % de area de menos
     *
     * A 3 grados el error queda por debajo del redondeo de los 4 decimales
     * con que se guardan las areas, a cambio de unos pocos vertices mas en
     * el poligono. Es un intercambio facil: el peso del dibujo es barato y
     * un lote mal medido no.
     */
    private const float GRADOS_POR_SEGMENTO = 3.0;

    /** Tope por arco. Un circulo completo a 3 grados son 120 segmentos. */
    private const int SEGMENTOS_MAXIMOS = 180;

    /**
     * Area encerrada por el poligono, por la formula del cordon de zapato.
     *
     * @param list<array{float, float}> $puntos
     */
    public static function area(array $puntos): float
    {
        $total = count($puntos);

        if ($total < 3) {
            return 0.0;
        }

        $suma = 0.0;

        for ($i = 0; $i < $total; $i++) {
            [$x1, $y1] = $puntos[$i];
            [$x2, $y2] = $puntos[($i + 1) % $total];

            $suma += ($x1 * $y2) - ($x2 * $y1);
        }

        return abs($suma) / 2.0;
    }

    /**
     * Perimetro del poligono cerrado.
     *
     * @param list<array{float, float}> $puntos
     */
    public static function perimetro(array $puntos): float
    {
        $total = count($puntos);

        if ($total < 2) {
            return 0.0;
        }

        $largo = 0.0;

        for ($i = 0; $i < $total; $i++) {
            [$x1, $y1] = $puntos[$i];
            [$x2, $y2] = $puntos[($i + 1) % $total];

            $largo += hypot($x2 - $x1, $y2 - $y1);
        }

        return $largo;
    }

    /**
     * ¿El punto cae adentro del poligono? Por lanzamiento de rayo.
     *
     * Es lo que decide a que lote pertenece cada rotulo del plano. Un
     * punto exactamente sobre el borde puede caer para cualquier lado; no
     * importa, porque los numeros de lote se rotulan al centro.
     *
     * @param list<array{float, float}> $puntos
     */
    public static function contiene(array $puntos, float $x, float $y): bool
    {
        $total = count($puntos);

        if ($total < 3) {
            return false;
        }

        $adentro = false;

        for ($i = 0, $j = $total - 1; $i < $total; $j = $i++) {
            [$xi, $yi] = $puntos[$i];
            [$xj, $yj] = $puntos[$j];

            $cruza = ($yi > $y) !== ($yj > $y)
                && $x < ($xj - $xi) * ($y - $yi) / ($yj - $yi) + $xi;

            if ($cruza) {
                $adentro = ! $adentro;
            }
        }

        return $adentro;
    }

    /**
     * Caja que encierra a todos los puntos: [minX, minY, maxX, maxY].
     *
     * @param list<array{float, float}> $puntos
     *
     * @return array{float, float, float, float}|null
     */
    public static function caja(array $puntos): ?array
    {
        if ($puntos === []) {
            return null;
        }

        $xs = array_map(static fn (array $p): float => $p[0], $puntos);
        $ys = array_map(static fn (array $p): float => $p[1], $puntos);

        return [min($xs), min($ys), max($xs), max($ys)];
    }

    /**
     * Promedio de los vertices.
     *
     * ⚠️ NO SIRVE PARA COLGAR UNA ETIQUETA, aunque lo dijo este docblock
     * hasta el 25-ago-2026. Un promedio de vertices pondera por CUANTOS
     * hay, no por donde estan: en un lote con un lado curvo, el arco entra
     * teselado en 30 o 60 vertices y arrastra el promedio hacia esa
     * pared. Medido sobre RESIDENCIAL ALTAMIRA, 268 lotes: 64 rotulos
     * corridos mas de 1.5 m y TRES fuera de su propio lote -y como el
     * rotulo se dibuja en blanco, afuera cae sobre la calle y desaparece-.
     *
     * En un cuadrilatero regular coincide con el centroide, y por eso el
     * error vivio meses invisible: los 309 lotes de Praderas del Sol
     * tienen cuatro vertices.
     *
     * Para poner una etiqueta va centroide(). Este metodo sigue existiendo
     * porque el importador lo usa para otra cosa: decidir cual de los
     * rotulos que caen ADENTRO de un contorno es el numero del lote, donde
     * lo que importa es una referencia estable, no el centro de masa.
     *
     * @param list<array{float, float}> $puntos
     *
     * @return array{float, float}
     */
    public static function centro(array $puntos): array
    {
        $total = count($puntos);

        if ($total === 0) {
            return [0.0, 0.0];
        }

        return [
            array_sum(array_map(static fn (array $p): float => $p[0], $puntos)) / $total,
            array_sum(array_map(static fn (array $p): float => $p[1], $puntos)) / $total,
        ];
    }

    /**
     * Centro de masa del poligono: el punto donde se cuelga la etiqueta.
     *
     * Sale de los mismos productos cruzados que area(), ponderando cada
     * arista por lo que encierra. Es INVARIANTE a como este teselado el
     * contorno -da igual si una pared curva entro en cuatro segmentos o en
     * sesenta-, que es exactamente lo que le falta a centro().
     *
     * Medido sobre los dos planos reales del sistema: de los 268 lotes de
     * Altamira ninguno queda con el centroide afuera (con el promedio,
     * tres), y el mas apretado tiene 4.60 m libres hasta su lindero mas
     * cercano, contra los ~1.2 m que mide de alto el rotulo. En los 309 de
     * Praderas del Sol la mediana del movimiento es CERO: sus lotes son
     * cuadrilateros y ahi las dos formulas coinciden.
     *
     * Un poligono degenerado -area cero, o menos de tres vertices- no
     * tiene centro de masa: ahi devuelve el promedio, que al menos es un
     * punto del dibujo. No se inventa nada.
     *
     * @param list<array{float, float}> $puntos
     *
     * @return array{float, float}
     */
    public static function centroide(array $puntos): array
    {
        $total = count($puntos);

        if ($total < 3) {
            return self::centro($puntos);
        }

        $doble = 0.0;
        $x = 0.0;
        $y = 0.0;

        for ($i = 0; $i < $total; $i++) {
            [$x1, $y1] = $puntos[$i];
            [$x2, $y2] = $puntos[($i + 1) % $total];

            $cruz = ($x1 * $y2) - ($x2 * $y1);

            $doble += $cruz;
            $x += ($x1 + $x2) * $cruz;
            $y += ($y1 + $y2) * $cruz;
        }

        if (abs($doble) < 1e-9) {
            return self::centro($puntos);
        }

        return [$x / (3.0 * $doble), $y / (3.0 * $doble)];
    }

    /**
     * Convierte un segmento con bulge en una sucesion de puntos rectos.
     *
     * El bulge de DXF es la tangente de un cuarto del angulo del arco, y
     * es negativo si el arco va en sentido horario del principio al fin.
     * De ahi sale todo lo demas:
     *
     *   angulo = 4 * atan(bulge)
     *   radio  = cuerda / (2 * sin(angulo/2))
     *   centro = punto medio de la cuerda, corrido perpendicularmente
     *            radio * cos(angulo/2)
     *
     * El angulo de arranque y el radio se recalculan DESDE el centro y el
     * punto inicial en vez de usar el radio con signo: asi el codigo se
     * comporta igual para bulge positivo y negativo sin casos especiales.
     *
     * Los extremos NO se incluyen — los pone quien llama, para no
     * duplicar vertices al encadenar segmentos.
     *
     * @return list<array{float, float}>
     */
    public static function arcoPorBulge(float $x1, float $y1, float $x2, float $y2, float $bulge): array
    {
        if (abs($bulge) < 1e-9) {
            return [];
        }

        $dx = $x2 - $x1;
        $dy = $y2 - $y1;
        $cuerda = hypot($dx, $dy);

        if ($cuerda < 1e-9) {
            return [];
        }

        $angulo = 4.0 * atan($bulge);
        $mitad = sin($angulo / 2.0);

        if (abs($mitad) < 1e-9) {
            return [];
        }

        $radio = $cuerda / (2.0 * $mitad);
        $apotema = $radio * cos($angulo / 2.0);

        // Perpendicular a la cuerda, girada 90 grados en sentido positivo.
        $centroX = ($x1 + $x2) / 2.0 + $apotema * (-$dy / $cuerda);
        $centroY = ($y1 + $y2) / 2.0 + $apotema * ($dx / $cuerda);

        $radioReal = hypot($x1 - $centroX, $y1 - $centroY);
        $desde = atan2($y1 - $centroY, $x1 - $centroX);

        $segmentos = (int) min(
            self::SEGMENTOS_MAXIMOS,
            max(2, ceil(abs($angulo) / deg2rad(self::GRADOS_POR_SEGMENTO)))
        );

        $puntos = [];

        for ($paso = 1; $paso < $segmentos; $paso++) {
            $t = $desde + $angulo * ($paso / $segmentos);
            $puntos[] = [$centroX + $radioReal * cos($t), $centroY + $radioReal * sin($t)];
        }

        return $puntos;
    }
}
