<?php

declare(strict_types=1);

use App\Domain\ValueObjects\Monto;
use App\Models\Proyecto;
use App\Models\Socio;
use Illuminate\Database\QueryException;

/*
|--------------------------------------------------------------------------
| Los socios del proyecto — 13-ago-2026
|--------------------------------------------------------------------------
| «Pueden ser dos propietarios, no de compra de un lote sino del proyecto en
| sí» — Mauricio. Un socio no compra: puso el terreno o el dinero y le toca un
| porcentaje de lo que el proyecto produzca.
|
| Lo que se prueba acá es la aritmética del reparto, porque es la única parte
| que decide a dónde va dinero.
*/

beforeEach(function (): void {
    $this->proyecto = Proyecto::factory()->create(['codigo' => 'RPS']);
});

test('la parte de cada socio sale exacta, sin float', function (): void {
    $socio = Socio::factory()->delProyecto($this->proyecto)->conParte('33.5')->create();

    // 33.5% de L 100,000.00 son L 33,500.00 exactos.
    expect($socio->suParteDe(new Monto('100000.00'))->redondeado())->toBe('33500.00')
        // Y sobre un número que no cierra redondo, tampoco se pierde nada:
        // el 33.5% de L 1,000.01 son L 335.0034 antes de redondear.
        ->and($socio->suParteDe(new Monto('1000.01'))->redondeado(4))->toBe('335.0034');
});

/*
| Tres socios se acomodan con medios: 33.5 + 33.5 + 33.
*/
test('tres socios en medios suman 100 y el reparto cierra', function (): void {
    foreach (['33.5', '33.5', '33.0'] as $parte) {
        Socio::factory()->delProyecto($this->proyecto)->conParte($parte)->create();
    }

    expect($this->proyecto->partesDeLosSocios()->redondeado(2))->toBe('100.00')
        ->and($this->proyecto->elRepartoCierra())->toBeTrue();
});

/*
| Y dos socios en dos tercios y un tercio se acomodan con centésimos: 66.67 +
| 33.33. Es el pedido de Mauricio del 27-ago-2026 y la razón de que la columna
| haya dejado de ser numeric(5,1): con medios, lo más cerca era 66.5 + 33.5, que
| no es «casi lo mismo» sino otro reparto.
*/
test('dos socios en centesimos suman 100 y el reparto cierra', function (): void {
    Socio::factory()->delProyecto($this->proyecto)->conParte('66.67')->create();
    Socio::factory()->delProyecto($this->proyecto)->conParte('33.33')->create();

    expect($this->proyecto->partesDeLosSocios()->redondeado(2))->toBe('100.00')
        ->and($this->proyecto->elRepartoCierra())->toBeTrue();
});

/*
| Y el centésimo tiene que llegar entero hasta el dinero. Con la escala vieja
| —un decimal— el 66.67% se cobraba como 66.7%: sobre L 300,000.00 son L 90.00
| que le tocaban al otro socio.
*/
test('el centesimo llega hasta el dinero, no se redondea en el camino', function (): void {
    $socio = Socio::factory()->delProyecto($this->proyecto)->conParte('66.67')->create();

    expect($socio->suParteDe(new Monto('300000.00'))->redondeado())->toBe('200010.00');
});

test('si las partes no llegan a 100 el reparto no cierra', function (): void {
    Socio::factory()->delProyecto($this->proyecto)->conParte('60.0')->create();
    Socio::factory()->delProyecto($this->proyecto)->conParte('30.0')->create();

    expect($this->proyecto->partesDeLosSocios()->redondeado(2))->toBe('90.00')
        ->and($this->proyecto->elRepartoCierra())->toBeFalse();
});

/*
| Un socio que salió no se borra —lo que ya cobró es historia— pero deja de
| contar. Si contara, el reparto de los que quedan pasaría de 100.
*/
test('un socio inactivo no entra en el reparto', function (): void {
    Socio::factory()->delProyecto($this->proyecto)->conParte('100.0')->create();
    Socio::factory()->delProyecto($this->proyecto)->conParte('50.0')->inactivo()->create();

    expect($this->proyecto->partesDeLosSocios()->redondeado(2))->toBe('100.00')
        ->and($this->proyecto->elRepartoCierra())->toBeTrue();
});

/*
| Un proyecto de una sola persona no tiene socios cargados, y eso NO es un
| reparto mal hecho: es que no hay reparto. Avisar ahí sería pedirle a todo el
| mundo que cargue algo que quizá no tiene.
*/
test('un proyecto sin socios no cuenta como reparto roto', function (): void {
    expect($this->proyecto->elRepartoCierra())->toBeTrue();
});

describe('Lo que la base no deja pasar', function (): void {
    test('un porcentaje de cero, negativo o mayor a cien', function (string $parte): void {
        expect(fn () => Socio::factory()->delProyecto($this->proyecto)->conParte($parte)->create())
            ->toThrow(QueryException::class);
    })->with([['0.0'], ['100.5'], ['-5.0']]);

    /*
    | El CHECK de «enteros o medios» se fue el 27-ago-2026: los centésimos son
    | exactamente lo que Mauricio vino a pedir. Lo que la base sigue mirando es
    | la escala —numeric(5,2)— y que el número sea un porcentaje posible.
    */
    test('los centesimos pasan y llegan enteros', function (string $parte): void {
        $socio = Socio::factory()->delProyecto($this->proyecto)->conParte($parte)->create();

        expect($socio->porcentaje()->redondeado(2))->toBe($parte);
    })->with([['0.50'], ['10.00'], ['20.50'], ['33.33'], ['66.67'], ['100.00']]);

    /*
    | ⚠️ Un tercer decimal NO explota: numeric(5,2) lo REDONDEA en silencio.
    | Por eso el formulario tiene su propia regla —`hastaDosDecimales`— que
    | avisa en vez de dejar que la base decida por el usuario.
    */
    test('un tercer decimal lo redondea la base, no lo rechaza', function (): void {
        $socio = Socio::factory()->delProyecto($this->proyecto)->conParte('33.333')->create();

        expect($socio->fresh()?->porcentaje()->redondeado(2))->toBe('33.33');
    });

    /*
    | Dos renglones del mismo socio en el mismo proyecto son un error de carga
    | que después nadie distingue de dos socios distintos.
    */
    test('el mismo socio dos veces en el mismo proyecto', function (): void {
        Socio::factory()->delProyecto($this->proyecto)->create(['nombre' => 'ADONAY ESPINOZA']);

        expect(fn () => Socio::factory()->delProyecto($this->proyecto)->create(['nombre' => '  adonay espinoza  ']))
            ->toThrow(QueryException::class);
    });

    test('pero el mismo nombre si puede ser socio de OTRO proyecto', function (): void {
        $otro = Proyecto::factory()->create(['codigo' => 'REB']);

        Socio::factory()->delProyecto($this->proyecto)->create(['nombre' => 'ADONAY ESPINOZA']);
        Socio::factory()->delProyecto($otro)->create(['nombre' => 'ADONAY ESPINOZA']);

        expect(Socio::query()->where('nombre', 'ADONAY ESPINOZA')->count())->toBe(2);
    });

    test('un nombre en blanco', function (): void {
        expect(fn () => Socio::factory()->delProyecto($this->proyecto)->create(['nombre' => '   ']))
            ->toThrow(QueryException::class);
    });
});
