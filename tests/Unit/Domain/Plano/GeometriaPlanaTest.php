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
