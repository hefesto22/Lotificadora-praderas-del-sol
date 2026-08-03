<?php

declare(strict_types=1);

use App\Domain\Exceptions\ValueObjectInvalidoException;
use App\Domain\ValueObjects\DNI;

describe('DNI — validación', function (): void {
    test('acepta 13 dígitos con año de nacimiento sensato', function (): void {
        $dni = new DNI('0801198501234');

        expect($dni->valor)->toBe('0801198501234');
        expect((string) $dni)->toBe('0801198501234');
    });

    test('rechaza cantidades de dígitos distintas de 13', function (string $malo): void {
        expect(fn (): DNI => new DNI($malo))->toThrow(ValueObjectInvalidoException::class);
    })->with([
        '080119850123',      // 12
        '08011985012345',    // 14
        '',
    ]);

    test('rechaza cualquier cosa que no sean dígitos', function (string $malo): void {
        expect(fn (): DNI => new DNI($malo))->toThrow(ValueObjectInvalidoException::class);
    })->with([
        '0801-1985-01234',
        '0801 1985 01234',
        'ABCD198501234',
    ]);

    /*
    | El año vive en las posiciones 5 a 8. Un '0000' ahí es un dedazo, no
    | una persona, y si entra a la base nadie lo vuelve a mirar.
    */
    test('rechaza un año de nacimiento imposible', function (string $malo): void {
        expect(fn (): DNI => new DNI($malo))->toThrow(ValueObjectInvalidoException::class);
    })->with([
        '0801000001234',   // año 0000
        '0801189201234',   // antes de que existiera el registro
        '0801299901234',   // futuro
    ]);
});

describe('DNI — normalización de entrada', function (): void {
    test('desdeEntrada limpia lo que la persona teclea', function (?string $entrada, ?string $esperado): void {
        expect(DNI::desdeEntrada($entrada)?->valor)->toBe($esperado);
    })->with([
        ['0801-1985-01234', '0801198501234'],
        ['0801 1985 01234', '0801198501234'],
        ['0801198501234', '0801198501234'],
        [null, null],
        ['', null],
        ['   ', null],
    ]);

    test('desdeEntrada sigue validando después de limpiar', function (): void {
        expect(fn (): ?DNI => DNI::desdeEntrada('0801-1985-0123'))
            ->toThrow(ValueObjectInvalidoException::class);
    });
});

describe('DNI — lectura y presentación', function (): void {
    test('descompone las partes del documento', function (): void {
        $dni = new DNI('0801198501234');

        expect($dni->departamento())->toBe('08');
        expect($dni->municipio())->toBe('01');
        expect($dni->anioNacimiento())->toBe(1985);
        expect($dni->correlativo())->toBe('01234');
    });

    test('formatea como se lee en el carnet', function (): void {
        expect(new DNI('0801198501234')->formateado())->toBe('0801-1985-01234');
    });

    /*
    | formatearCrudo existe para las pantallas: no valida, así que una fila
    | rara no tumba el listado entero al dibujarlo.
    */
    test('formatearCrudo no revienta con un valor que el constructor rechazaría', function (): void {
        expect(DNI::formatearCrudo('0801000001234'))->toBe('0801-0000-01234');
        expect(DNI::formatearCrudo('123'))->toBe('123');
    });

    test('compara por valor', function (): void {
        expect(new DNI('0801198501234')->igualA(new DNI('0801198501234')))->toBeTrue();
        expect(new DNI('0801198501234')->igualA(new DNI('0801198501235')))->toBeFalse();
    });
});
