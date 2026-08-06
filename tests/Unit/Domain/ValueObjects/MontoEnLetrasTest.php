<?php

declare(strict_types=1);

use App\Domain\ValueObjects\Monto;
use App\Domain\ValueObjects\MontoEnLetras;

/*
|--------------------------------------------------------------------------
| El monto en letras del recibo
|--------------------------------------------------------------------------
| Test unitario: no toca base de datos ni Laravel.
|
| La cantidad en letras no es decoracion. A «L 1,000.00» se le agrega un cero
| con un trazo y nadie lo nota; con la cantidad escrita al lado, las dos
| versiones tienen que coincidir. Por eso cada caso raro del castellano —el
| «cien» pelado, el «un mil» que no existe, el «veintiun mil» apocopado—
| tiene su test: un recibo que diga «UN MIL» lo hace ver hecho por una
| maquina que no sabe escribir.
*/

test('los numeros de todos los dias', function (string $monto, string $letras): void {
    expect(MontoEnLetras::de(new Monto($monto)))->toBe($letras);
})->with([
    ['25000.00', 'VEINTICINCO MIL LEMPIRAS CON 00/100'],
    ['350000.00', 'TRESCIENTOS CINCUENTA MIL LEMPIRAS CON 00/100'],
    ['5000.00', 'CINCO MIL LEMPIRAS CON 00/100'],
    ['12500.50', 'DOCE MIL QUINIENTOS LEMPIRAS CON 50/100'],
    ['3472.38', 'TRES MIL CUATROCIENTOS SETENTA Y DOS LEMPIRAS CON 38/100'],
]);

describe('Los casos que delatan a una maquina', function (): void {
    test('mil va solo, nunca «un mil»', function (): void {
        expect(MontoEnLetras::de(new Monto('1000.00')))->toBe('MIL LEMPIRAS CON 00/100');
    });

    test('cien va pelado, ciento en cuanto le sigue algo', function (): void {
        expect(MontoEnLetras::de(new Monto('100.00')))->toBe('CIEN LEMPIRAS CON 00/100')
            ->and(MontoEnLetras::de(new Monto('101.00')))->toBe('CIENTO UN LEMPIRAS CON 00/100');
    });

    test('el uno se apocopa antes de mil y de millones', function (): void {
        expect(MontoEnLetras::de(new Monto('21000.00')))->toBe('VEINTIÚN MIL LEMPIRAS CON 00/100')
            ->and(MontoEnLetras::de(new Monto('21000000.00')))->toBe('VEINTIÚN MILLONES DE LEMPIRAS CON 00/100');
    });

    /*
    | «Un millón DE lempiras», pero «dos millones quinientos mil lempiras»: el
    | «de» aparece solo cuando millon o millones es la ultima palabra antes de
    | la moneda. Si algo le sigue, se cae.
    */
    test('el «de» de un millon de lempiras aparece y desaparece', function (): void {
        expect(MontoEnLetras::de(new Monto('1000000.00')))->toBe('UN MILLÓN DE LEMPIRAS CON 00/100')
            ->and(MontoEnLetras::de(new Monto('2000000.00')))->toBe('DOS MILLONES DE LEMPIRAS CON 00/100')
            ->and(MontoEnLetras::de(new Monto('2500000.00')))->toBe('DOS MILLONES QUINIENTOS MIL LEMPIRAS CON 00/100')
            ->and(MontoEnLetras::de(new Monto('1000001.00')))->toBe('UN MILLÓN UN LEMPIRAS CON 00/100');
    });

    test('la y solo aparece de treinta para arriba', function (): void {
        expect(MontoEnLetras::de(new Monto('16.00')))->toBe('DIECISÉIS LEMPIRAS CON 00/100')
            ->and(MontoEnLetras::de(new Monto('31.00')))->toBe('TREINTA Y UN LEMPIRAS CON 00/100')
            ->and(MontoEnLetras::de(new Monto('999.00')))->toBe('NOVECIENTOS NOVENTA Y NUEVE LEMPIRAS CON 00/100');
    });
});

describe('Los bordes', function (): void {
    test('cero lleva plural', function (): void {
        expect(MontoEnLetras::de(new Monto('0.50')))->toBe('CERO LEMPIRAS CON 50/100');
    });

    test('un lempira exacto va en singular', function (): void {
        expect(MontoEnLetras::de(new Monto('1.00')))->toBe('UN LEMPIRA CON 00/100');
    });

    /*
    | Antes que una cantidad en letras incompleta, el numero: un recibo con la
    | cantidad a medias es peor que uno sin cantidad en letras.
    */
    test('arriba de mil millones devuelve el numero', function (): void {
        expect(MontoEnLetras::de(new Monto('1000000000.00')))->toContain('1,000,000,000.00');
    });

    test('nunca devuelve una cadena vacia, para ningun monto', function (): void {
        foreach (['0.00', '7.07', '70.70', '700.00', '7007.00', '70070.07', '700700.00', '7000007.00'] as $monto) {
            expect(MontoEnLetras::de(new Monto($monto)))
                ->not->toBeEmpty()
                ->toContain('/100');
        }
    });
});
