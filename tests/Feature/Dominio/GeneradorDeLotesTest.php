<?php

declare(strict_types=1);

use App\Domain\Enums\Numeracion;
use App\Domain\Exceptions\GeneracionDeLotesException;
use App\Domain\Exceptions\ValueObjectInvalidoException;
use App\Domain\Plano\GeneradorDeLotes;
use App\Domain\Plano\ParametrosDeGeneracion;
use App\Models\Bloque;
use App\Models\Lote;
use App\Models\Proyecto;
use Illuminate\Database\Eloquent\Collection;

/**
 * Parametros de prueba: un bloque de 2x3 lotes de 10 x 25 varas.
 *
 * Todo tipado y nada de `mixed ...$args`: spread de un array suelto
 * dentro de un constructor tipado es justo lo que PHPStan nivel 7 no
 * puede verificar, y estos tests tambien se analizan.
 */
function parametrosDeGeneracion(
    int $filas = 2,
    int $columnas = 3,
    string $frenteVaras = '10',
    string $fondoVaras = '25',
    string $precioVara = '1000.00',
    float $origenX = 0.0,
    float $origenY = 0.0,
    string $separacionFilasVaras = '0',
    string $separacionColumnasVaras = '0',
    Numeracion $numeracion = Numeracion::Serpentina,
    int $numeroInicial = 1,
): ParametrosDeGeneracion {
    return new ParametrosDeGeneracion(
        filas: $filas,
        columnas: $columnas,
        frenteVaras: $frenteVaras,
        fondoVaras: $fondoVaras,
        precioVara: $precioVara,
        origenX: $origenX,
        origenY: $origenY,
        separacionFilasVaras: $separacionFilasVaras,
        separacionColumnasVaras: $separacionColumnasVaras,
        numeracion: $numeracion,
        numeroInicial: $numeroInicial,
    );
}

describe('Generador — numeracion', function (): void {
    test('emite filas x columnas lotes', function (): void {
        $lotes = new GeneradorDeLotes()->previsualizar(parametrosDeGeneracion(filas: 4, columnas: 5));

        expect($lotes)->toHaveCount(20);
    });

    /*
    | Serpentina: la fila de arriba va 1-2-3 y la de abajo vuelve 4-5-6 de
    | derecha a izquierda. El 3 y el 4 quedan pegados en el terreno, que es
    | para lo que existe esta numeracion.
    */
    test('en serpentina las filas impares se numeran al reves', function (): void {
        $lotes = new GeneradorDeLotes()->previsualizar(parametrosDeGeneracion());

        expect(array_column($lotes, 'numero'))->toBe(['1', '2', '3', '6', '5', '4']);
    });

    test('por filas siempre va de izquierda a derecha', function (): void {
        $lotes = new GeneradorDeLotes()->previsualizar(
            parametrosDeGeneracion(numeracion: Numeracion::PorFilas)
        );

        expect(array_column($lotes, 'numero'))->toBe(['1', '2', '3', '4', '5', '6']);
    });

    test('el numero inicial desplaza toda la tanda', function (): void {
        $lotes = new GeneradorDeLotes()->previsualizar(
            parametrosDeGeneracion(numeracion: Numeracion::PorFilas, numeroInicial: 101)
        );

        expect(array_column($lotes, 'numero'))->toBe(['101', '102', '103', '104', '105', '106']);
    });
});

describe('Generador — geometria', function (): void {
    test('el primer lote se dibuja en el origen', function (): void {
        $lotes = new GeneradorDeLotes()->previsualizar(parametrosDeGeneracion());

        expect($lotes[0]['poligono'])->toBe([[0.0, 0.0], [10.0, 0.0], [10.0, 25.0], [0.0, 25.0]]);
    });

    test('las columnas avanzan un frente cada una', function (): void {
        $lotes = new GeneradorDeLotes()->previsualizar(parametrosDeGeneracion());

        expect($lotes[1]['poligono'][0])->toBe([10.0, 0.0])
            ->and($lotes[2]['poligono'][0])->toBe([20.0, 0.0]);
    });

    /*
    | La separacion entre filas es como se abre el espacio de una calle sin
    | tener que dibujarla dos veces: los lotes de la fila 2 arrancan un
    | fondo mas la separacion abajo.
    */
    test('la separacion entre filas abre espacio para la calle', function (): void {
        $lotes = new GeneradorDeLotes()->previsualizar(parametrosDeGeneracion(separacionFilasVaras: '5'));

        expect($lotes[3]['poligono'][0])->toBe([0.0, 30.0]);
    });

    test('la separacion NO cambia el area del lote', function (): void {
        $conSeparacion = new GeneradorDeLotes()->previsualizar(parametrosDeGeneracion(separacionFilasVaras: '5'));
        $sinSeparacion = new GeneradorDeLotes()->previsualizar(parametrosDeGeneracion());

        expect($conSeparacion[0]['area_varas'])->toBe($sinSeparacion[0]['area_varas'])
            ->and($conSeparacion[0]['area_varas'])->toBe('250.0000');
    });

    test('el area sale de frente x fondo por bcmath, con sus 4 decimales', function (): void {
        $lotes = new GeneradorDeLotes()->previsualizar(
            parametrosDeGeneracion(frenteVaras: '10.5000', fondoVaras: '25.2500')
        );

        expect($lotes[0]['area_varas'])->toBe('265.1250');
    });
});

describe('Generador — se niega a hacer un desastre', function (): void {
    test('una tanda absurda se rechaza antes de calcular nada', function (): void {
        expect(fn (): array => new GeneradorDeLotes()->previsualizar(parametrosDeGeneracion(filas: 100, columnas: 100)))
            ->toThrow(GeneracionDeLotesException::class);
    });

    test('un frente de cero no es un lote', function (): void {
        expect(fn (): ParametrosDeGeneracion => parametrosDeGeneracion(frenteVaras: '0'))
            ->toThrow(ValueObjectInvalidoException::class);
    });

    test('una medida que no es numero se rechaza', function (): void {
        expect(fn (): ParametrosDeGeneracion => parametrosDeGeneracion(fondoVaras: 'veinticinco'))
            ->toThrow(ValueObjectInvalidoException::class);
    });

    test('filas en cero se rechaza', function (): void {
        expect(fn (): ParametrosDeGeneracion => parametrosDeGeneracion(filas: 0))->toThrow(ValueObjectInvalidoException::class);
    });
});

describe('Generador — creacion en la base', function (): void {
    beforeEach(function (): void {
        $this->proyecto = Proyecto::factory()->create(['codigo' => 'RPS']);
        $this->bloque = Bloque::factory()->create([
            'proyecto_id' => $this->proyecto->getKey(),
            'nombre'      => 'A',
        ]);
    });

    test('crea los lotes con su codigo ya derivado', function (): void {
        $creados = new GeneradorDeLotes()->generar($this->bloque, parametrosDeGeneracion());

        expect($creados)->toHaveCount(6)
            ->and(Lote::query()->orderBy('codigo')->pluck('codigo')->all())
            ->toBe(['RPS-A-001', 'RPS-A-002', 'RPS-A-003', 'RPS-A-004', 'RPS-A-005', 'RPS-A-006']);
    });

    test('el valor se calcula solo desde el area generada', function (): void {
        new GeneradorDeLotes()->generar($this->bloque, parametrosDeGeneracion());

        $lote = Lote::query()->where('numero', '1')->firstOrFail();

        expect($lote->getAttribute('area_varas'))->toBe('250.0000')
            ->and($lote->getAttribute('valor'))->toBe('250000.00');
    });

    /*
    | El test que amarra el generador con la conciliacion de area: lo que
    | el generador dibuja y lo que carga como area TIENEN que coincidir. Si
    | alguien toca el calculo de uno de los dos lados, esto se pone rojo.
    */
    test('ningun lote generado nace desalineado', function (): void {
        $creados = new GeneradorDeLotes()->generar(
            $this->bloque,
            parametrosDeGeneracion(frenteVaras: '10.5000', fondoVaras: '25.2500')
        );

        foreach ($creados as $lote) {
            expect($lote->tienePoligono())->toBeTrue()
                ->and($lote->discrepanciaDeAreaEnPorcentaje())->toBe(0.0)
                ->and($lote->poligonoDesalineado())->toBeFalse();
        }
    });

    test('si un numero ya existe no se crea NINGUNO', function (): void {
        Lote::factory()->enBloque($this->bloque)->create(['numero' => '2']);

        expect(fn (): Collection => new GeneradorDeLotes()->generar($this->bloque, parametrosDeGeneracion()))
            ->toThrow(GeneracionDeLotesException::class);

        expect(Lote::query()->count())->toBe(1);
    });

    test('un numero inicial libre si permite ampliar el bloque', function (): void {
        new GeneradorDeLotes()->generar($this->bloque, parametrosDeGeneracion());

        new GeneradorDeLotes()->generar(
            $this->bloque,
            parametrosDeGeneracion(filas: 1, columnas: 3, numeroInicial: 7)
        );

        expect(Lote::query()->count())->toBe(9);
    });
});
