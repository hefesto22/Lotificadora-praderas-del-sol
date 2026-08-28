<?php

declare(strict_types=1);

use App\Domain\Plano\Dxf\GeometriaPlana;

describe('Geometria — area y perimetro', function (): void {
    test('el cordon de zapato mide bien un cuadrado', function (): void {
        expect(GeometriaPlana::area([[0.0, 0.0], [10.0, 0.0], [10.0, 10.0], [0.0, 10.0]]))->toBe(100.0);
    });

    test('el sentido del trazo no cambia el area', function (): void {
        $horario = [[0.0, 10.0], [10.0, 10.0], [10.0, 0.0], [0.0, 0.0]];

        expect(GeometriaPlana::area($horario))->toBe(100.0);
    });

    test('mide bien una figura concava', function (): void {
        $ele = [[0.0, 0.0], [10.0, 0.0], [10.0, 4.0], [4.0, 4.0], [4.0, 10.0], [0.0, 10.0]];

        expect(GeometriaPlana::area($ele))->toBe(64.0)
            ->and(GeometriaPlana::perimetro($ele))->toBe(40.0);
    });

    test('menos de tres puntos no encierran area', function (): void {
        expect(GeometriaPlana::area([[0.0, 0.0], [10.0, 0.0]]))->toBe(0.0);
    });
});

describe('Geometria — punto adentro del poligono', function (): void {
    /*
    | Esto es lo que decide a que lote pertenece cada rotulo del plano. Si
    | se rompe, los numeros de lote se mezclan entre vecinos.
    */
    test('distingue adentro de afuera', function (): void {
        $cuadrado = [[0.0, 0.0], [10.0, 0.0], [10.0, 10.0], [0.0, 10.0]];

        expect(GeometriaPlana::contiene($cuadrado, 5.0, 5.0))->toBeTrue()
            ->and(GeometriaPlana::contiene($cuadrado, 15.0, 5.0))->toBeFalse()
            ->and(GeometriaPlana::contiene($cuadrado, -0.1, 5.0))->toBeFalse()
            ->and(GeometriaPlana::contiene($cuadrado, 5.0, 20.0))->toBeFalse();
    });

    test('la escotadura de una figura concava queda afuera', function (): void {
        $ele = [[0.0, 0.0], [10.0, 0.0], [10.0, 4.0], [4.0, 4.0], [4.0, 10.0], [0.0, 10.0]];

        expect(GeometriaPlana::contiene($ele, 8.0, 8.0))->toBeFalse()
            ->and(GeometriaPlana::contiene($ele, 2.0, 8.0))->toBeTrue()
            ->and(GeometriaPlana::contiene($ele, 8.0, 2.0))->toBeTrue();
    });
});

describe('Geometria — arcos por bulge', function (): void {
    /*
    | Una poligonal inscrita SIEMPRE encierra menos area que el arco. En un
    | lote importado esa area multiplica al precio por vara, asi que el
    | error tiene que quedar por debajo del redondeo de la columna.
    */
    test('un semicirculo queda dentro del 0.05 % del area exacta', function (): void {
        $arco = GeometriaPlana::arcoPorBulge(0.0, 0.0, 10.0, 0.0, 1.0);
        $area = GeometriaPlana::area([[0.0, 0.0], ...$arco, [10.0, 0.0]]);
        $exacta = M_PI * 25.0 / 2.0;

        expect(abs($area - $exacta) / $exacta)->toBeLessThan(0.0005);
    });

    test('todos los puntos del arco estan sobre la circunferencia', function (): void {
        $arco = GeometriaPlana::arcoPorBulge(0.0, 0.0, 10.0, 0.0, 1.0);
        /** @var non-empty-list<float> $radios */
        $radios = array_map(static fn (array $p): float => hypot($p[0] - 5.0, $p[1]), $arco);

        expect(max($radios) - min($radios))->toBeLessThan(1e-9)
            ->and(abs($radios[0] - 5.0))->toBeLessThan(1e-9);
    });

    test('un cuarto de circulo conserva el radio alrededor de su centro', function (): void {
        $bulge = tan(deg2rad(90.0) / 4.0);
        $arco = GeometriaPlana::arcoPorBulge(0.0, 0.0, 10.0, 10.0, $bulge);
        /** @var non-empty-list<float> $radios */
        $radios = array_map(static fn (array $p): float => hypot($p[0], $p[1] - 10.0), $arco);

        expect(max($radios) - min($radios))->toBeLessThan(1e-9)
            ->and(abs($radios[0] - 10.0))->toBeLessThan(1e-9);
    });

    test('bulge positivo y negativo curvan hacia lados opuestos', function (): void {
        $medio = static fn (array $arco): float => $arco[intdiv(count($arco), 2)][1];

        $positivo = $medio(GeometriaPlana::arcoPorBulge(0.0, 0.0, 10.0, 0.0, 1.0));
        $negativo = $medio(GeometriaPlana::arcoPorBulge(0.0, 0.0, 10.0, 0.0, -1.0));

        expect($positivo * $negativo)->toBeLessThan(0.0);
    });

    test('bulge cero es un segmento recto y no agrega puntos', function (): void {
        expect(GeometriaPlana::arcoPorBulge(0.0, 0.0, 10.0, 0.0, 0.0))->toBe([]);
    });

    test('un segmento de largo cero no genera arco', function (): void {
        expect(GeometriaPlana::arcoPorBulge(5.0, 5.0, 5.0, 5.0, 1.0))->toBe([]);
    });
});

/**
 * Un cuarto de disco de radio 20, con el arco partido en `segmentos`.
 *
 * Es la forma de un lote de esquina de los que trajo RESIDENCIAL
 * ALTAMIRA, y sirve de patron porque su centro de masa se sabe de
 * memoria: 4r/3pi en cada eje, o sea 8.4883.
 *
 * @return list<array{float, float}>
 */
function cuartoDeDiscoTeselado(int $segmentos): array
{
    $puntos = [[0.0, 0.0]];

    for ($paso = 0; $paso <= $segmentos; $paso++) {
        $angulo = M_PI_2 * ($paso / $segmentos);

        $puntos[] = [20.0 * cos($angulo), 20.0 * sin($angulo)];
    }

    return $puntos;
}

describe('Geometria — el centro del que se cuelga el rotulo', function (): void {
    /*
    | 25-ago-2026. Mauricio, mirando el plano de RESIDENCIAL ALTAMIRA:
    | «hay lotes donde no se ve bien el numero que les corresponde».
    |
    | El rotulo se colgaba del PROMEDIO de los vertices, que pondera por
    | cuantos hay y no por donde estan. La pared curva de un lote de
    | esquina entra teselada en decenas de vertices y se lleva el promedio
    | con ella: 64 de 268 rotulos corridos mas de 1.5 m, y tres FUERA de
    | su propio lote. Como el rotulo se dibuja en blanco, afuera cae sobre
    | la calle y el lote se queda sin numero.
    */

    /** 4r/3pi con r = 20. El centro de masa de un cuarto de disco. */
    $exacto = 4.0 * 20.0 / (3.0 * M_PI);

    test('el promedio se sigue moviendo cuanto mas fino se tesela el arco', function () use ($exacto): void {
        /*
        | Esta es la prueba de que el promedio no mide lo que dice medir:
        | la FIGURA no cambia entre estas tres, solo cuantos vertices la
        | describen, y el promedio se corre mas de una unidad mientras
        | tanto.
        */
        [$ocho] = GeometriaPlana::centro(cuartoDeDiscoTeselado(8));
        [$cuarenta] = GeometriaPlana::centro(cuartoDeDiscoTeselado(40));
        [$ciento] = GeometriaPlana::centro(cuartoDeDiscoTeselado(120));

        expect($ocho)->toBeLessThan($cuarenta)
            ->and($cuarenta)->toBeLessThan($ciento)
            ->and($ciento - $ocho)->toBeGreaterThan(1.0)
            // Y los tres estan lejos del centro de masa de verdad.
            ->and($cuarenta - $exacto)->toBeGreaterThan(3.0);
    });

    test('el centroide da el centro de masa, y no le importa la teselacion', function () use ($exacto): void {
        foreach ([8, 40, 120] as $segmentos) {
            [$x, $y] = GeometriaPlana::centroide(cuartoDeDiscoTeselado($segmentos));

            expect(abs($x - $exacto))->toBeLessThan(0.05)
                ->and(abs($y - $exacto))->toBeLessThan(0.05);
        }
    });

    test('en la figura en L el promedio cae en el hueco y el centroide no', function (): void {
        /*
        | El mismo ele de «mide bien una figura concava». El promedio de
        | sus seis vertices da (4.67, 4.67), que es justo el pedazo que la
        | figura NO tiene. Ahi el numero del lote se dibuja sobre la calle.
        */
        $ele = [[0.0, 0.0], [10.0, 0.0], [10.0, 4.0], [4.0, 4.0], [4.0, 10.0], [0.0, 10.0]];

        [$px, $py] = GeometriaPlana::centro($ele);
        [$cx, $cy] = GeometriaPlana::centroide($ele);

        expect(GeometriaPlana::contiene($ele, $px, $py))->toBeFalse()
            ->and(GeometriaPlana::contiene($ele, $cx, $cy))->toBeTrue();
    });

    test('en un cuadrilatero regular da exactamente lo mismo que el promedio', function (): void {
        /*
        | Los 309 lotes de Praderas del Sol tienen cuatro vertices. Este
        | test es la promesa de que arreglar Altamira no les mueve el
        | rotulo ni un milimetro.
        */
        $cuadrado = [[0.0, 0.0], [10.0, 0.0], [10.0, 10.0], [0.0, 10.0]];

        expect(GeometriaPlana::centroide($cuadrado))->toBe(GeometriaPlana::centro($cuadrado))
            ->and(GeometriaPlana::centroide($cuadrado))->toBe([5.0, 5.0]);
    });

    test('un poligono sin area devuelve el promedio en vez de romperse', function (): void {
        // Tres puntos alineados encierran area cero: la formula se
        // dividiria por cero. Ahi vale mas un punto del dibujo que un NaN.
        expect(GeometriaPlana::centroide([[0.0, 0.0], [5.0, 0.0], [10.0, 0.0]]))->toBe([5.0, 0.0])
            ->and(GeometriaPlana::centroide([[3.0, 4.0], [3.0, 4.0]]))->toBe([3.0, 4.0]);
    });
});
