<?php

declare(strict_types=1);

use App\Domain\Enums\ConceptoDeRecibo;
use App\Domain\Enums\FormaDePago;
use App\Domain\Reportes\CierreDelMes;
use App\Filament\Widgets\CorteDeCajaDeHoy;
use App\Models\Cliente;
use App\Models\Gasto;
use App\Models\Proyecto;
use App\Models\Recibo;
use App\Models\Socio;
use App\Models\Venta;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Un gasto que un socio pagó de su bolsa — 8-oct, corregido el 10-oct-2026
|--------------------------------------------------------------------------
| El 8-oct se construyó «a quién se le carga un gasto» y se le DESCONTABA al
| socio. Era al revés. Mauricio, el 10-oct: «es dinero que ellos ponen de su
| bolsa al proyecto, ese dinero es a favor de ellos». Y eligió devolvérselo en
| el reparto del mes:
|
|  1. Es gasto del proyecto: baja la utilidad y cada socio carga con su parte.
|  2. No salió de la caja: al que lo puso se le devuelve encima de lo suyo.
|  3. Si la caja del mes no alcanza para devolverlo, lo que falta lo cubren
|     todos según su parte.
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
| abajo: sin nada puesto por un socio, el reparto es el de siempre.
*/
test('un gasto pagado con la caja lo pagan todos según su parte', function (): void {
    ($this->cobrar)('100000.00');
    Gasto::factory()->delProyecto($this->proyecto)->de('20000.00')->conFecha('2026-07-05')->create();

    $cierre = CierreDelMes::de($this->proyecto, $this->mes);

    expect($cierre->utilidadDelMes)->toBeMonto('80000.00')
        ->and($cierre->reparto[0]->leToca)->toBeMonto('48000.00')
        ->and($cierre->reparto[0]->porEntregar)->toBeMonto('48000.00')
        ->and($cierre->reparto[1]->porEntregar)->toBeMonto('32000.00')
        ->and($cierre->hayGastosDeSocios())->toBeFalse();
});

/*
| El ejemplo con el que Mauricio eligió la regla, al céntimo.
*/
test('lo que puso un socio es gasto del proyecto, y se le devuelve encima de lo suyo', function (): void {
    ($this->cobrar)('100000.00');
    Gasto::factory()->delProyecto($this->proyecto)->de('20000.00')->conFecha('2026-07-05')->create();
    Gasto::factory()->pagadoPor($this->mayor)->de('10000.00')->conFecha('2026-07-20')->create();

    $cierre = CierreDelMes::de($this->proyecto, $this->mes);

    [$elder, $edwin] = $cierre->reparto;

    // Los dos gastos son del proyecto: la utilidad es 100,000 − 30,000.
    expect($cierre->gastadoDelMes)->toBeMonto('30000.00')
        ->and($cierre->utilidadDelMes)->toBeMonto('70000.00')
        ->and($cierre->puestoPorSociosDelMes)->toBeMonto('10000.00');

    // A Elder se le devuelve lo que puso; Edwin carga con su 40 % del gasto.
    expect($elder->leToca)->toBeMonto('42000.00')
        ->and($elder->puso)->toBeMonto('10000.00')
        ->and($elder->porEntregar)->toBeMonto('52000.00')
        ->and($edwin->leToca)->toBeMonto('28000.00')
        ->and($edwin->puso)->toBeMonto('0.00')
        ->and($edwin->porEntregar)->toBeMonto('28000.00');

    // La caja cierra: 100,000 cobrados − 20,000 que pagó la caja.
    expect($cierre->porEntregar)->toBeMonto('80000.00')
        ->and($cierre->puestoEnElReparto())->toBeMonto('10000.00')
        ->and($cierre->hayQueCubrir())->toBeFalse();
});

/*
| Mes flojo: la caja no alcanza a devolver lo que puso Edwin. La caja entera
| va para él, y los 3,000 que faltan los cubren los dos según su parte.
*/
test('si la caja no alcanza para devolverlo, lo que falta lo cubren todos según su parte', function (): void {
    ($this->cobrar)('2000.00');
    Gasto::factory()->pagadoPor($this->menor)->de('5000.00')->conFecha('2026-07-12')->create();

    $cierre = CierreDelMes::de($this->proyecto, $this->mes);

    [$elder, $edwin] = $cierre->reparto;

    expect($cierre->perdidaDelMes)->toBeMonto('3000.00')
        ->and($cierre->hayQueCubrir())->toBeTrue()
        ->and($cierre->cubiertoEnElReparto())->toBeMonto('3000.00');

    // Elder no puso nada y le toca cubrir el 60 % de los 3,000.
    expect($elder->cubre)->toBeMonto('1800.00')
        ->and($elder->porEntregar)->toBeMonto('0.00')
        ->and($elder->entregadoDeMas)->toBeMonto('1800.00')
        ->and($elder->marcaDelSobregiro())->toBe('Queda debiendo');

    // Edwin puso 5,000 y cubre su 40 %: se le devuelven 3,800.
    expect($edwin->puso)->toBeMonto('5000.00')
        ->and($edwin->cubre)->toBeMonto('1200.00')
        ->and($edwin->porEntregar)->toBeMonto('3800.00')
        ->and($edwin->estaSobregirado())->toBeFalse();

    // 3,800 por entregar − 1,800 que debe Elder = los 2,000 de la caja.
});

/*
| Si la caja ya estaba en rojo por sí sola, ese rojo no se le cobra a nadie
| —el cierre es mes a mes, sin acumulado—. Solo se cubre lo que puso el socio.
*/
test('el rojo que la caja traía por sí sola no se reparte: solo lo que puso el socio', function (): void {
    ($this->cobrar)('1000.00');
    Gasto::factory()->delProyecto($this->proyecto)->de('4000.00')->conFecha('2026-07-05')->create();
    Gasto::factory()->pagadoPor($this->menor)->de('2000.00')->conFecha('2026-07-12')->create();

    [$elder, $edwin] = CierreDelMes::de($this->proyecto, $this->mes)->reparto;

    expect($elder->cubre)->toBeMonto('1200.00')
        ->and($elder->entregadoDeMas)->toBeMonto('1200.00')
        ->and($edwin->cubre)->toBeMonto('800.00')
        ->and($edwin->porEntregar)->toBeMonto('1200.00');
});

test('la tabla del año usa los mismos egresos que la hoja del mes', function (): void {
    ($this->cobrar)('100000.00');
    Gasto::factory()->delProyecto($this->proyecto)->de('20000.00')->conFecha('2026-07-05')->create();
    Gasto::factory()->pagadoPor($this->mayor)->de('10000.00')->conFecha('2026-07-20')->create();

    $julio = collect(CierreDelMes::de($this->proyecto, $this->mes)->mesAMesDelAnio())
        ->firstWhere('mes', 'julio');

    expect($julio)->not->toBeNull()
        ->and($julio['egresos'] ?? null)->toBeMonto('30000.00')
        ->and($julio['utilidad'] ?? null)->toBeMonto('70000.00');
});

/*
| Un socio que ya no participa no sale en el reparto, pero lo que puso
| mientras estaba sigue saliendo con su nombre. Es justo el renglón que
| alguien va a preguntar.
*/
test('lo que puso un socio que ya salió se sigue viendo, con su nombre', function (): void {
    Gasto::factory()->pagadoPor($this->menor)->de('5000.00')->conFecha('2026-07-12')->create();
    $this->menor->update(['activo' => false]);

    $cierre = CierreDelMes::de($this->proyecto, $this->mes);

    expect($cierre->puestoPorSocios)->toHaveCount(1)
        ->and($cierre->puestoPorSocios[0]['nombre'])->toBe('EDWIN')
        ->and($cierre->puestoPorSocios[0]['monto'])->toBeMonto('5000.00')
        ->and($cierre->huboMovimiento())->toBeTrue();
});

/*
| §9.F9 y la FK compuesta: un gasto de un proyecto no lo puede haber pagado un
| socio de OTRO. Sería plata que se le devuelve en un reparto que no es suyo.
*/
test('la base no deja que un gasto lo pague un socio de otro proyecto', function (): void {
    $deOtro = Socio::factory()->delProyecto(Proyecto::factory()->create(['codigo' => 'RRB']))->create();

    expect(fn (): Gasto => Gasto::factory()->delProyecto($this->proyecto)->create(['socio_id' => $deOtro->getKey()]))
        ->toThrow(QueryException::class);
});

/*
| No salió de la gaveta: el corte de caja no lo resta. El par de abajo
| prueba que el primero pasa por la razón correcta.
*/
describe('El corte de caja', function (): void {
    test('no resta lo que un socio pagó de su bolsa', function (): void {
        Gasto::factory()->pagadoPor($this->mayor)->de('12000.00')->create();

        Livewire::test(CorteDeCajaDeHoy::class)
            ->assertSee('Es lo que tiene que estar en la caja al cerrar')
            ->assertDontSee('Salió de la caja hoy');
    });

    test('sí resta lo que pagó la caja', function (): void {
        Gasto::factory()->delProyecto($this->proyecto)->de('12000.00')->create();

        Livewire::test(CorteDeCajaDeHoy::class)
            ->assertSee('Salió de la caja hoy');
    });
});

describe('La hoja impresa', function (): void {
    test('trae la tabla de lo puesto y la columna del reparto cuando un socio puso', function (): void {
        ($this->cobrar)('100000.00');
        Gasto::factory()->pagadoPor($this->mayor)->de('10000.00')->conFecha('2026-07-20')->create();

        $this->get(route('documentos.estado-mensual', ['proyecto' => $this->proyecto, 'mes' => '2026-07']))
            ->assertOk()
            ->assertSeeText('Pagado por socios, de su bolsa')
            ->assertSeeText('Puso de su bolsa')
            ->assertDontSeeText('Cubre');
    });

    // Con pérdida y algo que devolver, el reparto se imprime igual.
    test('con pérdida y algo que devolver, imprime el reparto con lo que cubre cada uno', function (): void {
        ($this->cobrar)('2000.00');
        Gasto::factory()->pagadoPor($this->menor)->de('5000.00')->conFecha('2026-07-12')->create();

        $this->get(route('documentos.estado-mensual', ['proyecto' => $this->proyecto, 'mes' => '2026-07']))
            ->assertOk()
            ->assertSeeText('Cubre')
            ->assertSeeText('Queda debiendo')
            ->assertDontSeeText('no hay utilidad que repartir');
    });

    // El par del primero: sin nada puesto, la hoja sale igual que antes. Si
    // no, el de arriba podría estar pasando por la razón equivocada.
    test('sin nada puesto por socios, la hoja sale como siempre', function (): void {
        ($this->cobrar)('100000.00');
        Gasto::factory()->delProyecto($this->proyecto)->de('20000.00')->conFecha('2026-07-05')->create();

        $this->get(route('documentos.estado-mensual', ['proyecto' => $this->proyecto, 'mes' => '2026-07']))
            ->assertOk()
            ->assertDontSeeText('Pagado por socios, de su bolsa')
            ->assertDontSeeText('Puso de su bolsa');
    });
});
