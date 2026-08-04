<?php

declare(strict_types=1);

use App\Models\Bloque;
use App\Models\Cliente;
use App\Models\Lote;
use App\Models\Proyecto;

/*
| Todo se guarda en mayusculas, y se guarda asi en el MUTADOR del modelo:
| por eso estos tests escriben directo contra el modelo y no a traves de un
| formulario. Un dato que entra por un seeder, por un import o por tinker
| tiene que quedar igual que uno tipeado en el panel.
|
| Ver docs/mayusculas.md.
*/

describe('Mayusculas — clientes', function (): void {
    test('el nombre de una persona se guarda en mayusculas', function (): void {
        $cliente = Cliente::factory()->create(['nombre' => 'maría de los ángeles rodríguez']);

        expect($cliente->refresh()->getAttribute('nombre'))->toBe('MARÍA DE LOS ÁNGELES RODRÍGUEZ');
    });

    test('los espacios de mas se siguen colapsando', function (): void {
        $cliente = Cliente::factory()->create(['nombre' => '  mauricio    cruz  ']);

        expect($cliente->refresh()->getAttribute('nombre'))->toBe('MAURICIO CRUZ');
    });

    test('la direccion tambien va en mayusculas', function (): void {
        $cliente = Cliente::factory()->create(['direccion' => 'barrio el centro, calle principal']);

        expect($cliente->refresh()->getAttribute('direccion'))->toBe('BARRIO EL CENTRO, CALLE PRINCIPAL');
    });

    /*
    | La unica excepcion, y es tecnica: dos correos que difieren solo en
    | mayusculas son la misma casilla. Guardarlos distinto rompe cualquier
    | busqueda y permite duplicados invisibles.
    */
    test('el correo sigue yendo en minusculas', function (): void {
        $cliente = Cliente::factory()->create(['correo' => 'Rosa@Gmail.COM']);

        expect($cliente->refresh()->getAttribute('correo'))->toBe('rosa@gmail.com');
    });
});

describe('Mayusculas — proyectos y lotes', function (): void {
    test('el nombre y el municipio del proyecto', function (): void {
        $proyecto = Proyecto::factory()->create([
            'nombre'    => 'residencial praderas del sol',
            'municipio' => 'villanueva',
            'codigo'    => 'rps',
        ]);

        expect($proyecto->refresh()->getAttribute('nombre'))->toBe('RESIDENCIAL PRADERAS DEL SOL')
            ->and($proyecto->getAttribute('municipio'))->toBe('VILLANUEVA')
            ->and($proyecto->getAttribute('codigo'))->toBe('RPS');
    });

    test('el nombre del bloque y el numero del lote', function (): void {
        $proyecto = Proyecto::factory()->create(['codigo' => 'RPS']);
        $bloque = Bloque::factory()->create(['proyecto_id' => $proyecto->getKey(), 'nombre' => 'b']);
        $lote = Lote::factory()->enBloque($bloque)->create(['numero' => '12-a']);

        expect($bloque->refresh()->getAttribute('nombre'))->toBe('B')
            ->and($lote->refresh()->getAttribute('numero'))->toBe('12-A')
            ->and($lote->getAttribute('codigo'))->toBe('RPS-B-012-A');
    });
});
