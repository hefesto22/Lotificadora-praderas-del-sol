<?php

declare(strict_types=1);

use App\Domain\Exceptions\ValueObjectInvalidoException;
use App\Domain\ValueObjects\Monto;

describe('Monto — invariantes del constructor', function (): void {
    test('rechaza valor negativo', function (): void {
        expect(fn () => new Monto(-1.0))->toThrow(ValueObjectInvalidoException::class);
    });

    test('rechaza moneda con longitud distinta a 3', function (): void {
        expect(fn () => new Monto(100.0, 'HONDURAS'))
            ->toThrow(ValueObjectInvalidoException::class);
    });

    test('acepta cero como valor válido', function (): void {
        expect(new Monto(0.0)->esCero())->toBeTrue();
    });
});

describe('Monto — aritmética', function (): void {
    test('suma dos montos de la misma moneda', function (): void {
        $a = new Monto(100.50);
        $b = new Monto(50.25);

        expect($a->sumar($b)->valor)->toBe(150.75);
    });

    test('rechaza suma entre monedas distintas', function (): void {
        $hnl = new Monto(100.00, 'HNL');
        $usd = new Monto(100.00, 'USD');

        expect(fn () => $hnl->sumar($usd))->toThrow(ValueObjectInvalidoException::class);
    });

    test('aplica porcentaje correctamente — caso ISV 15%', function (): void {
        $subtotal = new Monto(1000.00);
        $isv = $subtotal->aplicarPorcentaje(15);

        expect($isv->valor)->toBe(150.00);
    });

    test('resta produce error si el resultado sería negativo', function (): void {
        $a = new Monto(50.00);
        $b = new Monto(100.00);

        expect(fn () => $a->restar($b))->toThrow(ValueObjectInvalidoException::class);
    });

    test('multiplica sin perder precisión en redondeo', function (): void {
        $monto = new Monto(33.33);
        $resultado = $monto->multiplicarPor(3);

        expect($resultado->valor)->toBe(99.99);
    });
});

describe('Monto — comparación e inmutabilidad', function (): void {
    test('mayorQue compara correctamente', function (): void {
        expect(new Monto(200.00)->mayorQue(new Monto(100.00)))->toBeTrue();
        expect(new Monto(100.00)->mayorQue(new Monto(200.00)))->toBeFalse();
    });

    test('igualA verifica monto Y moneda', function (): void {
        expect(new Monto(100.00, 'HNL')->igualA(new Monto(100.00, 'HNL')))->toBeTrue();
        expect(new Monto(100.00, 'HNL')->igualA(new Monto(100.00, 'USD')))->toBeFalse();
    });

    test('sumar retorna nueva instancia, no muta', function (): void {
        $a = new Monto(100.00);
        $b = new Monto(50.00);
        $sum = $a->sumar($b);

        expect($a->valor)->toBe(100.00);
        expect($b->valor)->toBe(50.00);
        expect($sum)->not->toBe($a);
    });
});

describe('Monto — formato', function (): void {
    test('formateado usa el símbolo provisto', function (): void {
        expect(new Monto(1234.56)->formateado('L.'))->toBe('L. 1,234.56');
        expect(new Monto(1234.56)->formateado('$'))->toBe('$ 1,234.56');
    });

    test('formateado sin símbolo usa default L.', function (): void {
        expect(new Monto(99.99)->formateado())->toBe('L. 99.99');
    });

    test('toString delega en formateado con default', function (): void {
        expect((string) new Monto(99.99))->toBe('L. 99.99');
    });
});
