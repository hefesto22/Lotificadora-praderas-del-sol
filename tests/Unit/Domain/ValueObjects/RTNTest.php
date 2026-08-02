<?php

declare(strict_types=1);

use App\Domain\Exceptions\ValueObjectInvalidoException;
use App\Domain\ValueObjects\RTN;

describe('RTN — invariantes', function (): void {
    test('rechaza valor con menos de 14 dígitos', function (): void {
        expect(fn () => new RTN('1234'))->toThrow(ValueObjectInvalidoException::class);
    });

    test('rechaza valor con caracteres no numéricos', function (): void {
        expect(fn () => new RTN('0801198501234X'))
            ->toThrow(ValueObjectInvalidoException::class);
    });

    test('rechaza RTN con guiones', function (): void {
        expect(fn () => new RTN('0801-1985-012345'))
            ->toThrow(ValueObjectInvalidoException::class);
    });

    test('acepta exactamente 14 dígitos', function (): void {
        expect(new RTN('08011985012345')->valor)->toBe('08011985012345');
    });
});

describe('RTN — descomposición', function (): void {
    test('extrae el código de departamento', function (): void {
        expect(new RTN('08011985012345')->departamento())->toBe('0801');
    });

    test('extrae el año de emisión', function (): void {
        expect(new RTN('08011985012345')->anioEmision())->toBe(1985);
    });

    test('extrae el correlativo y dígito verificador', function (): void {
        $rtn = new RTN('08011985012345');

        expect($rtn->correlativo())->toBe('01234');
        expect($rtn->digitoVerificador())->toBe('5');
    });
});

describe('RTN — formato y comparación', function (): void {
    test('formateado agrupa con guiones para reportes', function (): void {
        expect(new RTN('08011985012345')->formateado())
            ->toBe('0801-1985-012345');
    });

    test('toString retorna el valor crudo', function (): void {
        expect((string) new RTN('08011985012345'))->toBe('08011985012345');
    });

    test('igualA compara por valor', function (): void {
        $a = new RTN('08011985012345');
        $b = new RTN('08011985012345');
        $c = new RTN('05021990098765');

        expect($a->igualA($b))->toBeTrue();
        expect($a->igualA($c))->toBeFalse();
    });
});
