<?php

declare(strict_types=1);

use App\Domain\Enums\ConceptoDeRecibo;
use App\Domain\Enums\FormaDePago;
use App\Domain\Reportes\CierreDelMes;
use App\Models\Cliente;
use App\Models\Gasto;
use App\Models\Proyecto;
use App\Models\Recibo;
use App\Models\Socio;
use App\Models\Venta;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;

/*
|--------------------------------------------------------------------------
| Un gasto cargado a un socio — 8-oct-2026
|--------------------------------------------------------------------------
| El cliente pidió registrar «a quién de los socios se le carga el gasto», y
| Mauricio eligió la regla: SE LE DESCUENTA. Lo paga solo ese socio:
|
|  1. No es egreso del proyecto: no baja la utilidad que se reparte.
|  2. En el reparto se le descuenta a él, igual que un adelanto.
|  3. La caja cierra: lo que queda por entregar entre todos es lo cobrado
|     menos los gastos del proyecto y menos lo cargado.
|
| Socios de 60 y 40 para que los números se verifiquen sin calculadora.
*/

beforeEach(function (): void {
    actingAsAdmin();

    $this->proyecto = Proyecto::factory()->create(['codigo' => 'RPS']);
    $this->mes = CarbonImmutable::parse('2026-07-15');

    $this->mayor = Socio::factory()->delProyecto($this->proyecto)->conParte('60.00')->create(['nombre' => 'ELDER']);
    $this->menor = Socio::factory()->delProyecto($this->proyecto)->conParte('40.00')->create(['nombre' => 'EDWIN']);

    /*
    | Un recibo del proyecto, sin pasar por el Service: lo que se prueba acá es
    | el REPARTO, no el cobro. Mismo atajo que `RepartoDelMesTest`.
    */
    $this->cobrar = function (string $monto): Recibo {
        $venta = Venta::factory()->create(['proyecto_id' => $this->proyecto->getKey()]);

        return Recibo::factory()->create([
            'venta_id'   => $venta->getKey(),
            'cliente_id' => Cliente::factory()->create()->getKey(),
            'concepto'   => ConceptoDeRecibo::Cuota,
            'forma_pago' => FormaDePago::Efectivo,
            'monto'      => $monto,
            'fecha'      => '2026-07-10',
        ]);
    };
});

/*
| El punto de partida, para que si el fixture cambia falle ESTE y no los de
| abajo: sin cargos, el reparto es el de siempre.
*/
test('un gasto del proyecto lo pagan todos según su parte', function (): void {
    ($this->cobrar)('100000.00');
    Gasto::factory()->delProyecto($this->proyecto)->de('20000.00')->conFecha('2026-07-05')->create();

    $cierre = CierreDelMes::de($this->proyecto, $this->mes);

    expect($cierre->utilidadDelMes)->toBeMonto('80000.00')
        ->and($cierre->reparto[0]->leToca)->toBeMonto('48000.00')
        ->and($cierre->reparto[0]->porEntregar)->toBeMonto('48000.00')
        ->and($cierre->reparto[1]->porEntregar)->toBeMonto('32000.00')
        ->and($cierre->hayGastosCargados())->toBeFalse();
});

test('un gasto cargado a un socio no baja la utilidad de los demás: se le descuenta a él', function (): void {
    ($this->cobrar)('100000.00');
    Gasto::factory()->delProyecto($this->proyecto)->de('20000.00')->conFecha('2026-07-05')->create();
    Gasto::factory()->cargadoA($this->mayor)->de('10000.00')->conFecha('2026-07-20')->create();

    $cierre = CierreDelMes::de($this->proyecto, $this->mes);

    [$elder, $edwin] = $cierre->reparto;

    // Los egresos del proyecto NO incluyen lo cargado: la utilidad es la misma
    // que sin el cargo.
    expect($cierre->gastadoDelMes)->toBeMonto('20000.00')
        ->and($cierre->utilidadDelMes)->toBeMonto('80000.00')
        ->and($cierre->cargadoDelMes)->toBeMonto('10000.00');

    // A Elder se le descuenta; Edwin no pierde un centavo.
    expect($elder->leToca)->toBeMonto('48000.00')
        ->and($elder->cargado)->toBeMonto('10000.00')
        ->and($elder->porEntregar)->toBeMonto('38000.00')
        ->and($edwin->leToca)->toBeMonto('32000.00')
        ->and($edwin->cargado)->toBeMonto('0.00')
        ->and($edwin->porEntregar)->toBeMonto('32000.00');

    // La caja cierra: 100,000 cobrados − 20,000 del proyecto − 10,000 cargados.
    expect($cierre->porEntregar)->toBeMonto('70000.00')
        ->and($cierre->cargadoEnElReparto())->toBeMonto('10000.00');
});

test('si lo cargado pasa lo que le toca en el mes, queda debiendo', function (): void {
    ($this->cobrar)('10000.00');
    Gasto::factory()->cargadoA($this->menor)->de('5000.00')->conFecha('2026-07-12')->create();

    [$elder, $edwin] = CierreDelMes::de($this->proyecto, $this->mes)->reparto;

    expect($edwin->leToca)->toBeMonto('4000.00')
        ->and($edwin->porEntregar)->toBeMonto('0.00')
        ->and($edwin->entregadoDeMas)->toBeMonto('1000.00')
        ->and($edwin->estaSobregirado())->toBeTrue()
        ->and($edwin->marcaDelSobregiro())->toBe('Queda debiendo')
        // Elder no se entera del cargo de Edwin.
        ->and($elder->porEntregar)->toBeMonto('6000.00')
        ->and($elder->estaSobregirado())->toBeFalse();
});

test('la tabla del año usa los mismos egresos que la hoja del mes', function (): void {
    ($this->cobrar)('100000.00');
    Gasto::factory()->delProyecto($this->proyecto)->de('20000.00')->conFecha('2026-07-05')->create();
    Gasto::factory()->cargadoA($this->mayor)->de('10000.00')->conFecha('2026-07-20')->create();

    $julio = collect(CierreDelMes::de($this->proyecto, $this->mes)->mesAMesDelAnio())
        ->firstWhere('mes', 'julio');

    expect($julio)->not->toBeNull()
        ->and($julio['egresos'] ?? null)->toBeMonto('20000.00')
        ->and($julio['utilidad'] ?? null)->toBeMonto('80000.00');
});

/*
| Un socio que ya no participa no sale en el reparto, pero lo que se le cargó
| mientras estaba sigue saliendo con su nombre. Es justo el renglón que
| alguien va a preguntar.
*/
test('lo cargado a un socio que ya salió se sigue viendo, con su nombre', function (): void {
    Gasto::factory()->cargadoA($this->menor)->de('5000.00')->conFecha('2026-07-12')->create();
    $this->menor->update(['activo' => false]);

    $cierre = CierreDelMes::de($this->proyecto, $this->mes);

    expect($cierre->cargadoASocios)->toHaveCount(1)
        ->and($cierre->cargadoASocios[0]['nombre'])->toBe('EDWIN')
        ->and($cierre->cargadoASocios[0]['monto'])->toBeMonto('5000.00')
        ->and($cierre->huboMovimiento())->toBeTrue();
});

/*
| §9.F9 y la FK compuesta: un gasto de un proyecto no se le carga a un socio
| de OTRO. Sería plata que sale de un reparto y se descuenta en otro.
*/
test('la base no deja cargarle un gasto a un socio de otro proyecto', function (): void {
    $deOtro = Socio::factory()->delProyecto(Proyecto::factory()->create(['codigo' => 'RRB']))->create();

    expect(fn (): Gasto => Gasto::factory()->delProyecto($this->proyecto)->create(['socio_id' => $deOtro->getKey()]))
        ->toThrow(QueryException::class);
});

describe('La hoja impresa', function (): void {
    test('trae la tabla de cargos y la columna del reparto cuando hubo cargos', function (): void {
        ($this->cobrar)('100000.00');
        Gasto::factory()->cargadoA($this->mayor)->de('10000.00')->conFecha('2026-07-20')->create();

        $this->get(route('documentos.estado-mensual', ['proyecto' => $this->proyecto, 'mes' => '2026-07']))
            ->assertOk()
            ->assertSeeText('Gastos cargados a un socio')
            ->assertSeeText('Gastos a su cuenta');
    });

    // El par del de arriba: sin cargos, la hoja sale igual que antes. Si no,
    // el de arriba podría estar pasando por la razón equivocada.
    test('sin cargos, la hoja sale como siempre', function (): void {
        ($this->cobrar)('100000.00');
        Gasto::factory()->delProyecto($this->proyecto)->de('20000.00')->conFecha('2026-07-05')->create();

        $this->get(route('documentos.estado-mensual', ['proyecto' => $this->proyecto, 'mes' => '2026-07']))
            ->assertOk()
            ->assertDontSeeText('Gastos cargados a un socio')
            ->assertDontSeeText('Gastos a su cuenta');
    });
});
