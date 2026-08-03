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
     * Promedio de los vertices. Alcanza para colgar una etiqueta.
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
