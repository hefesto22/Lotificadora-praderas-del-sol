<?php

declare(strict_types=1);

use App\Domain\Exceptions\ValueObjectInvalidoException;
use App\Domain\ValueObjects\Monto;

describe('Monto — invariantes del constructor', function (): void {
    test('rechaza valor negativo', function (): void {
        expect(fn () => new Monto('-1.00'))->toThrow(ValueObjectInvalidoException::class);
    });

    test('rechaza moneda con longitud distinta a 3', function (): void {
        expect(fn () => new Monto('100.00', 'HONDURAS'))
            ->toThrow(ValueObjectInvalidoException::class);
    });

    test('rechaza strings que no son decimales simples', function (string $entrada): void {
        expect(fn () => new Monto($entrada))->toThrow(ValueObjectInvalidoException::class);
    })->with(['1,234.56', '1e3', 'abc', '', '12.', '.5']);

    test('acepta enteros y strings decimales', function (): void {
        expect(new Monto(1500)->redondeado())->toBe('1500.00');
        expect(new Monto('1500.5')->redondeado())->toBe('1500.50');
    });

    test('acepta cero como valor valido', function (): void {
        expect(new Monto('0')->esCero())->toBeTrue();
    });
});

describe('Monto — golden test: area x precio al centimo (§9.C9)', function (): void {
    /*
    | Estos tres casos son reales: salieron de medir 300.000 pares de
    | area_varas x precio_vara contra aritmetica exacta. La version
    | anterior de Monto, que usaba float y hacia round($valor * $factor, 2),
    | devolvia un centavo de menos en los tres. Si alguien vuelve a meter
    | float en el camino del dinero, estos tests se ponen rojos.
    */
    test('calcula el valor del lote exacto', function (string $area, string $precio, string $esperado): void {
        $valor = new Monto($precio)->multiplicarPor($area);

        expect($valor->redondeado())->toBe($esperado);
    })->with([
        ['613.0405', '2530.00', '1550992.47'],
        ['390.9960', '631.25', '246816.23'],
        ['840.5740', '4317.50', '3629178.25'],
        ['112.5015', '490.00', '55125.74'],
        ['390.0875', '1198.00', '467324.83'],
    ]);

    test('no redondea en el medio de una cadena de operaciones', function (): void {
        // 0.005 x 3 = 0.015 -> half-up a 2 decimales = 0.02
        // Si redondeara en cada paso daria 0.01 x 3 = 0.03, o 0.00 x 3 = 0.00
        $resultado = new Monto('0.005')->multiplicarPor('3');

        expect($resultado->redondeado())->toBe('0.02');
    });

    test('conserva la precision exacta antes de exponer', function (): void {
        $tercio = new Monto('100.00')->dividirPor('3');

        expect($tercio->valor)->toStartWith('33.333333333333');
        expect($tercio->redondeado())->toBe('33.33');
    });
});

describe('Monto — redondeo half-up solo al exponer', function (): void {
    test('redondea half-up en el limite', function (string $entrada, string $esperado): void {
        expect(new Monto($entrada)->redondeado())->toBe($esperado);
    })->with([
        ['0.005', '0.01'],
        ['0.015', '0.02'],
        ['0.025', '0.03'],
        ['2.345', '2.35'],
        ['2.344', '2.34'],
        ['1550992.465', '1550992.47'],
    ]);

    test('redondeado acepta otra escala', function (): void {
        expect(new Monto('12.3456')->redondeado(4))->toBe('12.3456');
        expect(new Monto('12.34565')->redondeado(4))->toBe('12.3457');
        expect(new Monto('12.5')->redondeado(0))->toBe('13');
    });

    test('enCentavos convierte sin perder un centavo', function (): void {
        expect(new Monto('1550992.465')->enCentavos())->toBe(155099247);
        expect(new Monto('0.005')->enCentavos())->toBe(1);
    });
});

describe('Monto — aritmetica', function (): void {
    test('suma dos montos de la misma moneda', function (): void {
        expect(new Monto('100.50')->sumar(new Monto('50.25'))->redondeado())->toBe('150.75');
    });

    test('rechaza operar entre monedas distintas', function (): void {
        $hnl = new Monto('100.00', 'HNL');
        $usd = new Monto('100.00', 'USD');

        expect(fn () => $hnl->sumar($usd))->toThrow(ValueObjectInvalidoException::class);
    });

    test('aplica porcentaje — caso ISV 15%', function (): void {
        expect(new Monto('1000.00')->aplicarPorcentaje('15')->redondeado())->toBe('150.00');
    });

    test('resta produce error si el resultado seria negativo', function (): void {
        expect(fn () => new Monto('50.00')->restar(new Monto('100.00')))
            ->toThrow(ValueObjectInvalidoException::class);
    });

    test('multiplica sin perder precision', function (): void {
        expect(new Monto('33.33')->multiplicarPor('3')->redondeado())->toBe('99.99');
    });

    test('rechaza division entre cero', function (): void {
        expect(fn () => new Monto('100.00')->dividirPor('0'))
            ->toThrow(ValueObjectInvalidoException::class);
    });

    test('divide en cuotas iguales', function (): void {
        expect(new Monto('12000.00')->dividirPor('12')->redondeado())->toBe('1000.00');
    });
});

describe('Monto — comparacion e inmutabilidad', function (): void {
    test('mayorQue y menorQue comparan correctamente', function (): void {
        expect(new Monto('200.00')->mayorQue(new Monto('100.00')))->toBeTrue();
        expect(new Monto('100.00')->mayorQue(new Monto('200.00')))->toBeFalse();
        expect(new Monto('100.00')->menorQue(new Monto('200.00')))->toBeTrue();
    });

    test('igualA verifica monto Y moneda', function (): void {
        expect(new Monto('100.00', 'HNL')->igualA(new Monto('100.00', 'HNL')))->toBeTrue();
        expect(new Monto('100.00', 'HNL')->igualA(new Monto('100.00', 'USD')))->toBeFalse();
    });

    test('igualA compara el valor exacto, no el redondeado', function (): void {
        expect(new Monto('100.001')->igualA(new Monto('100.002')))->toBeFalse();
    });

    test('sumar retorna nueva instancia, no muta', function (): void {
        $a = new Monto('100.00');
        $b = new Monto('50.00');
        $suma = $a->sumar($b);

        expect($a->redondeado())->toBe('100.00');
        expect($b->redondeado())->toBe('50.00');
        expect($suma)->not->toBe($a);
    });
});

describe('Monto — formato', function (): void {
    test('formateado usa el simbolo provisto', function (): void {
        expect(new Monto('1234.56')->formateado('L.'))->toBe('L. 1,234.56');
        expect(new Monto('1234.56')->formateado('$'))->toBe('$ 1,234.56');
    });

    test('formateado separa miles sin pasar por float', function (): void {
        expect(new Monto('3629178.245')->formateado('L.'))->toBe('L. 3,629,178.25');
        expect(new Monto('999.00')->formateado('L.'))->toBe('L. 999.00');
        expect(new Monto('1000.00')->formateado('L.'))->toBe('L. 1,000.00');
    });

    test('formateado sin simbolo usa el default L.', function (): void {
        expect(new Monto('99.99')->formateado())->toBe('L. 99.99');
    });

    test('toString delega en formateado', function (): void {
        expect((string) new Monto('99.99'))->toBe('L. 99.99');
    });
});
