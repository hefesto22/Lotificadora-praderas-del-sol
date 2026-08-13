<?php

declare(strict_types=1);

use App\Domain\Enums\ConceptoDeRecibo;
use App\Domain\Enums\FormaDePago;
use App\Domain\Socios\RepartoDelMes;
use App\Domain\ValueObjects\Monto;
use App\Models\Cliente;
use App\Models\Gasto;
use App\Models\Proyecto;
use App\Models\Recibo;
use App\Models\Socio;
use App\Models\Venta;
use Carbon\CarbonImmutable;

/*
|--------------------------------------------------------------------------
| El reparto del mes — 13-ago-2026
|--------------------------------------------------------------------------
| Las tres reglas las decidió Mauricio y las tres mueven plata:
|
|  1. Se muestran los DOS números: lo cobrado y lo cobrado menos gastos.
|  2. Un gasto entra por su fecha.
|  3. La prima cuenta; la seña no, hasta que el apartado se formalice.
|
| Los números de abajo salen de ahí y se pueden verificar sin calculadora.
*/

beforeEach(function (): void {
    actingAsAdmin();

    $this->proyecto = Proyecto::factory()->create(['codigo' => 'RPS']);
    $this->mes = CarbonImmutable::parse('2026-07-15');

    /*
    | Un recibo del proyecto, sin pasar por el Service: lo que se prueba acá es
    | el REPARTO, no el cobro. El cobro ya tiene sus propios tests.
    */
    $this->cobrar = function (string $monto, ConceptoDeRecibo $concepto, string $fecha): Recibo {
        $venta = Venta::factory()->create(['proyecto_id' => $this->proyecto->getKey()]);

        return Recibo::factory()->create([
            'venta_id'   => $venta->getKey(),
            'cliente_id' => Cliente::factory()->create()->getKey(),
            'concepto'   => $concepto,
            'forma_pago' => FormaDePago::Efectivo,
            'monto'      => $monto,
            'fecha'      => $fecha,
        ]);
    };
});

test('lo cobrado del mes suma primas, cuotas y abonos', function (): void {
    ($this->cobrar)('50000.00', ConceptoDeRecibo::Prima, '2026-07-03');
    ($this->cobrar)('25000.00', ConceptoDeRecibo::Cuota, '2026-07-20');
    ($this->cobrar)('10000.00', ConceptoDeRecibo::AbonoCapital, '2026-07-28');

    expect(RepartoDelMes::para($this->proyecto, $this->mes)->cobrado)->toBeMonto('85000.00');
});

/*
| 🔴 La regla que más importa: mientras es seña, esa plata todavía puede tener
| que devolverse (R14). Repartirla sería repartir dinero ajeno.
*/
test('la seña NO entra al reparto', function (): void {
    ($this->cobrar)('50000.00', ConceptoDeRecibo::Prima, '2026-07-03');
    ($this->cobrar)('5000.00', ConceptoDeRecibo::Senia, '2026-07-10');

    expect(RepartoDelMes::para($this->proyecto, $this->mes)->cobrado)->toBeMonto('50000.00');
});

test('un recibo anulado no cuenta: esa plata no entró', function (): void {
    ($this->cobrar)('50000.00', ConceptoDeRecibo::Cuota, '2026-07-03');
    ($this->cobrar)('30000.00', ConceptoDeRecibo::Cuota, '2026-07-04')
        ->update(['anulado_el' => '2026-07-05', 'motivo_anulacion' => 'Error de carga.']);

    expect(RepartoDelMes::para($this->proyecto, $this->mes)->cobrado)->toBeMonto('50000.00');
});

test('lo de otro mes queda afuera', function (): void {
    ($this->cobrar)('50000.00', ConceptoDeRecibo::Cuota, '2026-07-31');
    ($this->cobrar)('99000.00', ConceptoDeRecibo::Cuota, '2026-08-01');
    ($this->cobrar)('88000.00', ConceptoDeRecibo::Cuota, '2026-06-30');

    expect(RepartoDelMes::para($this->proyecto, $this->mes)->cobrado)->toBeMonto('50000.00');
});

test('el neto resta los gastos del mes, por su fecha', function (): void {
    ($this->cobrar)('100000.00', ConceptoDeRecibo::Cuota, '2026-07-10');

    Gasto::factory()->create(['proyecto_id' => $this->proyecto->getKey(), 'monto' => '30000.00', 'fecha' => '2026-07-15']);
    Gasto::factory()->create(['proyecto_id' => $this->proyecto->getKey(), 'monto' => '77000.00', 'fecha' => '2026-08-02']);

    $reparto = RepartoDelMes::para($this->proyecto, $this->mes);

    expect($reparto->cobrado)->toBeMonto('100000.00')
        ->and($reparto->gastos)->toBeMonto('30000.00')
        ->and($reparto->neto)->toBeMonto('70000.00');
});

/*
| Un mes flojo con una factura grande queda EN ROJO, y se muestra así.
| Recortarlo a cero escondería justamente el mes que hay que mirar.
|
| El neto va en valor absoluto porque `Monto` es no negativo por diseño —un
| saldo negativo en la cartera es un error, y esa restricción es lo que hace que
| explote en vez de aparecer como un número raro—. El signo lo dice `enRojo`.
*/
test('el neto puede quedar en rojo y no se recorta', function (): void {
    ($this->cobrar)('10000.00', ConceptoDeRecibo::Cuota, '2026-07-10');
    Gasto::factory()->create(['proyecto_id' => $this->proyecto->getKey(), 'monto' => '25000.00', 'fecha' => '2026-07-11']);

    $reparto = RepartoDelMes::para($this->proyecto, $this->mes);

    expect($reparto->enRojo)->toBeTrue()
        ->and($reparto->neto)->toBeMonto('15000.00')
        ->and($reparto->netoFormateado())->toStartWith('-');
});

test('un mes con ganancia no está en rojo', function (): void {
    ($this->cobrar)('25000.00', ConceptoDeRecibo::Cuota, '2026-07-10');
    Gasto::factory()->create(['proyecto_id' => $this->proyecto->getKey(), 'monto' => '10000.00', 'fecha' => '2026-07-11']);

    $reparto = RepartoDelMes::para($this->proyecto, $this->mes);

    expect($reparto->enRojo)->toBeFalse()
        ->and($reparto->neto)->toBeMonto('15000.00');
});

describe('El reparto entre los socios', function (): void {
    test('cada uno se lleva su parte de los dos números', function (): void {
        Socio::factory()->delProyecto($this->proyecto)->conParte('60.0')->create(['nombre' => 'ADONAY']);
        Socio::factory()->delProyecto($this->proyecto)->conParte('40.0')->create(['nombre' => 'DIONEL']);

        ($this->cobrar)('100000.00', ConceptoDeRecibo::Cuota, '2026-07-10');
        Gasto::factory()->create(['proyecto_id' => $this->proyecto->getKey(), 'monto' => '20000.00', 'fecha' => '2026-07-12']);

        $partes = RepartoDelMes::para($this->proyecto, $this->mes)->partes;

        expect($partes)->toHaveCount(2)
            // Vienen ordenados de mayor a menor parte.
            ->and($partes[0]->nombre())->toBe('ADONAY')
            ->and($partes[0]->deLoCobrado)->toBeMonto('60000.00')
            ->and($partes[0]->deLoNeto)->toBeMonto('48000.00')
            ->and($partes[1]->deLoCobrado)->toBeMonto('40000.00')
            ->and($partes[1]->deLoNeto)->toBeMonto('32000.00');
    });

    /*
    | 🔴 El centavo que no existe: dos al 50% de L 100.01 dan L 50.005 cada uno,
    | que redondeado son L 50.01 y L 50.01 — un centavo de más. La diferencia se
    | le carga al de mayor parte y las partes vuelven a sumar el total exacto.
    */
    test('las partes suman EXACTAMENTE el total, sin centavos sueltos', function (string $total): void {
        Socio::factory()->delProyecto($this->proyecto)->conParte('50.0')->create(['nombre' => 'UNO']);
        Socio::factory()->delProyecto($this->proyecto)->conParte('50.0')->create(['nombre' => 'DOS']);

        ($this->cobrar)($total, ConceptoDeRecibo::Cuota, '2026-07-10');

        $reparto = RepartoDelMes::para($this->proyecto, $this->mes);
        $suma = Monto::cero();

        foreach ($reparto->partes as $parte) {
            $suma = $suma->sumar($parte->deLoCobrado);
        }

        expect($suma)->toBeMonto($total);
    })->with([['100.01'], ['0.01'], ['33.33'], ['999999.99']]);

    /*
    | Y con tres socios en medios, que es el caso que la regla de «enteros o
    | medios» vino a hacer posible: 33.5 + 33.5 + 33.
    */
    test('tres socios en medios tampoco pierden un centavo', function (): void {
        Socio::factory()->delProyecto($this->proyecto)->conParte('33.5')->create(['nombre' => 'UNO']);
        Socio::factory()->delProyecto($this->proyecto)->conParte('33.5')->create(['nombre' => 'DOS']);
        Socio::factory()->delProyecto($this->proyecto)->conParte('33.0')->create(['nombre' => 'TRES']);

        ($this->cobrar)('100000.07', ConceptoDeRecibo::Cuota, '2026-07-10');

        $suma = Monto::cero();

        foreach (RepartoDelMes::para($this->proyecto, $this->mes)->partes as $parte) {
            $suma = $suma->sumar($parte->deLoCobrado);
        }

        expect($suma)->toBeMonto('100000.07');
    });

    test('un proyecto sin socios no reparte y lo dice', function (): void {
        ($this->cobrar)('100000.00', ConceptoDeRecibo::Cuota, '2026-07-10');

        $reparto = RepartoDelMes::para($this->proyecto, $this->mes);

        expect($reparto->partes)->toBe([])
            ->and($reparto->repartoCompleto)->toBeFalse()
            // Pero los totales del mes se calculan igual.
            ->and($reparto->cobrado)->toBeMonto('100000.00');
    });

    test('si las partes no suman 100 el reparto se marca incompleto', function (): void {
        Socio::factory()->delProyecto($this->proyecto)->conParte('60.0')->create();

        expect(RepartoDelMes::para($this->proyecto, $this->mes)->repartoCompleto)->toBeFalse();
    });
});
