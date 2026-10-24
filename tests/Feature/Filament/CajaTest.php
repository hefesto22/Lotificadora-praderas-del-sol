<?php

declare(strict_types=1);

use App\Domain\Enums\ConceptoDeRecibo;
use App\Domain\Enums\FormaDePago;
use App\Domain\Reportes\CajaPorDia;
use App\Filament\Pages\Caja;
use App\Filament\Widgets\ComoVaElNegocio;
use App\Models\Bloque;
use App\Models\Cliente;
use App\Models\Compromiso;
use App\Models\Lote;
use App\Models\Proyecto;
use App\Models\Recibo;
use App\Models\User;
use App\Models\Venta;
use App\Support\ProyectoActivo;
use App\Support\Roles;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| La caja, día por día — 24-oct-2026
|--------------------------------------------------------------------------
| Mauricio: «función de caja, aunque solo será para reportes de lo que se
| recibió qué día … el 1 de octubre, el 2 de octubre y así … solo de un
| proyecto o de dos o de más».
|
| Lo que cuidan estos tests son las cuatro maneras en que un reporte de
| dinero miente sin que nadie lo note: contar un anulado, perder la seña de
| un apartado al recortar por proyecto, mostrarle a un receptor lo de otro,
| y decir un número distinto del que dice el Escritorio para el mismo mes.
|
| El reloj queda quieto en el sábado 24 de octubre de 2026: la pantalla abre
| en «este mes», y los días de abajo tienen que caer adentro.
*/

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-24 10:00:00'));

    actingAsAdmin();

    $this->norte = Proyecto::factory()->create(['codigo' => 'NOR', 'nombre' => 'NORTE']);
    $this->sur = Proyecto::factory()->create(['codigo' => 'SUR', 'nombre' => 'SUR']);
    $this->este = Proyecto::factory()->create(['codigo' => 'EST', 'nombre' => 'ESTE']);

    $this->elder = User::factory()->create(['name' => 'Elder Dionel', 'is_active' => true]);
    $this->edwin = User::factory()->create(['name' => 'Edwin Adonay', 'is_active' => true]);

    $this->cobrar = function (Proyecto $proyecto, string $monto, string $fecha, array $extra = []): Recibo {
        $venta = Venta::factory()->delProyecto($proyecto)->create();

        $forma = $extra['forma_pago'] ?? FormaDePago::Efectivo;

        return Recibo::factory()->create(array_merge([
            'venta_id'     => $venta->getKey(),
            'cliente_id'   => Cliente::factory()->create()->getKey(),
            'concepto'     => ConceptoDeRecibo::Cuota,
            'forma_pago'   => $forma,
            'referencia'   => $forma === FormaDePago::Efectivo ? null : 'REF-123',
            'monto'        => $monto,
            'fecha'        => $fecha,
            'recibido_por' => $this->elder->getKey(),
        ], $extra));
    };

    // La seña de un apartado: NO cuelga de ninguna venta, llega a su
    // proyecto por el compromiso (R13).
    $this->senia = function (Proyecto $proyecto, string $monto, string $fecha): Recibo {
        $bloque = Bloque::factory()->create(['proyecto_id' => $proyecto->getKey(), 'nombre' => 'A']);
        $lote = Lote::factory()->enBloque($bloque)->conMedidas('250.0000', '1400.00')->create(['numero' => '1']);
        $apartado = Compromiso::factory()->paraLote($lote)->create();

        return Recibo::factory()->create([
            'venta_id'      => null,
            'compromiso_id' => $apartado->getKey(),
            'cliente_id'    => $apartado->getAttribute('cliente_id'),
            'concepto'      => ConceptoDeRecibo::Senia,
            'monto'         => $monto,
            'fecha'         => $fecha,
            'recibido_por'  => $this->edwin->getKey(),
        ]);
    };
});

/**
 * La caja del 1 al 24 de octubre.
 *
 * Función con nombre y no un closure en `$this`: un closure no puede llevar
 * `@param`, y su `array $proyectos` pelado le borraría la forma `list<int>`
 * que pide `CajaPorDia::de()`.
 *
 * @param list<int> $proyectos
 */
function cajaDeOctubre(array $proyectos = [], ?int $recibidoPor = null): CajaPorDia
{
    return CajaPorDia::de(
        desde: CarbonImmutable::parse('2026-10-01'),
        hasta: CarbonImmutable::parse('2026-10-24'),
        proyectos: $proyectos,
        recibidoPor: $recibidoPor,
    );
}

describe('el cálculo', function (): void {
    test('un renglón por día, del 1 en adelante, abierto por forma de pago', function (): void {
        ($this->cobrar)($this->norte, '1000.00', '2026-10-01');
        ($this->cobrar)($this->norte, '500.00', '2026-10-01', ['forma_pago' => FormaDePago::Transferencia]);
        ($this->cobrar)($this->norte, '2000.00', '2026-10-02');

        $caja = cajaDeOctubre();

        [$primero, $segundo] = $caja->dias;

        expect($caja->dias)->toHaveCount(2)
            ->and($primero->fecha->toDateString())->toBe('2026-10-01')
            ->and($primero->recibos)->toBe(2)
            ->and($primero->deLaForma(FormaDePago::Efectivo))->toBeMonto('1000.00')
            ->and($primero->deLaForma(FormaDePago::Transferencia))->toBeMonto('500.00')
            ->and($primero->total)->toBeMonto('1500.00')
            ->and($segundo->fecha->toDateString())->toBe('2026-10-02')
            ->and($segundo->deLaForma(FormaDePago::Transferencia))->toBeMonto('0.00')
            ->and($segundo->total)->toBeMonto('2000.00')
            ->and($caja->total)->toBeMonto('3500.00')
            ->and($caja->recibos)->toBe(3)
            ->and($caja->efectivo())->toBeMonto('3000.00')
            ->and($caja->porBanco())->toBeMonto('500.00')
            ->and($caja->formas())->toBe([FormaDePago::Efectivo, FormaDePago::Transferencia])
            ->and($caja->elDiaQueMasEntro()?->fecha->toDateString())->toBe('2026-10-02');
    });

    /*
    | 🔴 Un recibo anulado sigue en la serie —su número no puede faltar—, pero
    | su dinero volvió a deberse. Y lo de fuera del rango, fuera.
    */
    test('los anulados y los de fuera del rango no cuentan', function (): void {
        ($this->cobrar)($this->norte, '1000.00', '2026-10-05');
        ($this->cobrar)($this->norte, '9999.00', '2026-10-05', [
            'anulado_el'       => '2026-10-06',
            'motivo_anulacion' => 'Error de carga.',
        ]);
        ($this->cobrar)($this->norte, '7777.00', '2026-09-30');
        ($this->cobrar)($this->norte, '8888.00', '2026-10-25');

        $caja = cajaDeOctubre();

        expect($caja->dias)->toHaveCount(1)
            ->and($caja->total)->toBeMonto('1000.00')
            ->and($caja->recibos)->toBe(1);
    });

    test('un día sin cobros no sale, y un rango sin cobros dice que no hubo', function (): void {
        $caja = cajaDeOctubre();

        expect($caja->huboMovimiento())->toBeFalse()
            ->and($caja->total)->toBeMonto('0.00')
            ->and($caja->formas())->toBe([])
            ->and($caja->elDiaQueMasEntro())->toBeNull();
    });

    /*
    | «Solo de un proyecto o de dos o de más.» Y la seña de un apartado sin
    | contrato no cuelga de ninguna venta: si el recorte fuera solo por la
    | venta, ese dinero no aparecería en ningún proyecto.
    */
    test('uno, dos o todos los proyectos — y la seña cuenta en el suyo', function (): void {
        ($this->cobrar)($this->norte, '1000.00', '2026-10-03');
        ($this->cobrar)($this->sur, '2000.00', '2026-10-03');
        ($this->senia)($this->este, '3000.00', '2026-10-03');

        $id = static fn (Proyecto $proyecto): int => (int) $proyecto->getKey();

        expect(cajaDeOctubre([$id($this->norte)])->total)->toBeMonto('1000.00')
            ->and(cajaDeOctubre([$id($this->norte), $id($this->sur)])->total)->toBeMonto('3000.00')
            ->and(cajaDeOctubre([$id($this->este)])->total)->toBeMonto('3000.00')
            ->and(cajaDeOctubre([$id($this->norte), $id($this->este)])->total)->toBeMonto('4000.00')
            ->and(cajaDeOctubre()->total)->toBeMonto('6000.00');
    });

    test('quién recibió el dinero de un día, de mayor a menor, con los sin anotar', function (): void {
        ($this->cobrar)($this->norte, '1000.00', '2026-10-07');
        ($this->senia)($this->sur, '3000.00', '2026-10-07');

        /*
         * Un recibo sin «quién recibió» solo existe en la cartera vieja, que se
         * cargó sin sesión. Con sesión, el `creating` de `Recibo` lo llena con
         * quien está conectado aunque se le pase null, así que se borra después
         * con una consulta, que no pasa por los eventos del modelo.
         */
        $sinAnotar = ($this->cobrar)($this->norte, '250.00', '2026-10-07');
        Recibo::query()->whereKey($sinAnotar->getKey())->update(['recibido_por' => null]);

        $quienes = CajaPorDia::quienesRecibieron(CarbonImmutable::parse('2026-10-07'));

        expect(array_column($quienes, 'nombre'))->toBe(['EDWIN ADONAY', 'ELDER DIONEL', 'Sin anotar'])
            ->and($quienes[0]['total'])->toBeMonto('3000.00')
            ->and($quienes[2]['total'])->toBeMonto('250.00');
    });

    /*
    | 🔴 El mismo número en dos pantallas. «Cobrado este mes» del Escritorio
    | y el total del mes de esta pantalla son la misma pregunta: si un día
    | dijeran cosas distintas, la administradora dejaría de creerle a las dos.
    | Montos con centavos y una seña adentro, para que no coincidan de casualidad.
    */
    test('el total del mes es exactamente el «Cobrado este mes» del Escritorio', function (): void {
        ($this->cobrar)($this->norte, '12345.67', '2026-10-02');
        ($this->cobrar)($this->sur, '2000.10', '2026-10-15', ['forma_pago' => FormaDePago::Deposito]);
        ($this->senia)($this->este, '3000.00', '2026-10-20');
        ($this->cobrar)($this->norte, '4444.44', '2026-10-21', [
            'anulado_el'       => '2026-10-21',
            'motivo_anulacion' => 'Error de carga.',
        ]);

        $caja = cajaDeOctubre();

        expect($caja->total)->toBeMonto('17345.77');

        Livewire::test(ComoVaElNegocio::class)->assertSee($caja->total->formateado());
    });
});

describe('la pantalla', function (): void {
    test('se llama Caja y va justo debajo del Escritorio, sin grupo', function (): void {
        expect(Caja::getNavigationLabel())->toBe('Caja')
            ->and(Caja::getNavigationGroup())->toBeNull()
            ->and(Caja::getNavigationSort())->toBe(-1);

        $this->get(Caja::getUrl())->assertOk();
    });

    test('abre en este mes y en el proyecto del interruptor de la barra', function (): void {
        ($this->cobrar)($this->norte, '1000.00', '2026-10-03');
        ($this->cobrar)($this->sur, '2000.00', '2026-10-03');
        ($this->cobrar)($this->norte, '5000.00', '2026-09-30');

        app(ProyectoActivo::class)->elegir((int) $this->norte->getKey());

        $pantalla = Livewire::test(Caja::class)->assertOk();

        $periodo = $pantalla->instance()->periodo();

        expect($periodo['desde']->toDateString())->toBe('2026-10-01')
            ->and($periodo['hasta']->toDateString())->toBe('2026-10-24')
            ->and($pantalla->instance()->caja()->total)->toBeMonto('1000.00');
    });

    test('elegir dos proyectos los suma; vaciar la lista suma todos', function (): void {
        ($this->cobrar)($this->norte, '1000.00', '2026-10-03');
        ($this->cobrar)($this->sur, '2000.00', '2026-10-03');
        ($this->cobrar)($this->este, '4000.00', '2026-10-03');

        $pantalla = Livewire::test(Caja::class)
            ->set('filtros.proyectos', [(string) $this->norte->getKey(), (string) $this->sur->getKey()]);

        expect($pantalla->instance()->caja()->total)->toBeMonto('3000.00');

        $pantalla->set('filtros.proyectos', []);

        expect($pantalla->instance()->caja()->total)->toBeMonto('7000.00');
    });

    test('tocar un día abre sus recibos; tocarlo de nuevo lo cierra', function (): void {
        ($this->cobrar)($this->norte, '1000.00', '2026-10-03', ['numero' => 4321]);
        ($this->cobrar)($this->norte, '2000.00', '2026-10-04', ['numero' => 8765]);

        Livewire::test(Caja::class)
            ->assertDontSee('004321')
            ->call('abrir', '2026-10-03')
            ->assertSet('diaAbierto', '2026-10-03')
            ->assertSee('004321')
            ->assertDontSee('008765')
            ->call('abrir', '2026-10-03')
            ->assertSet('diaAbierto', null)
            ->call('abrir', 'no-es-una-fecha')
            ->assertSet('diaAbierto', null);
    });

    test('«Hoy» deja un solo día y lo abre', function (): void {
        $pantalla = Livewire::test(Caja::class)->callAction('hoy');

        $periodo = $pantalla->instance()->periodo();

        expect($periodo['desde']->toDateString())->toBe('2026-10-24')
            ->and($periodo['hasta']->toDateString())->toBe('2026-10-24');

        $pantalla->assertSet('diaAbierto', '2026-10-24');
    });

    test('«Mes pasado» es septiembre entero', function (): void {
        $periodo = Livewire::test(Caja::class)->callAction('mes_pasado')->instance()->periodo();

        expect($periodo['desde']->toDateString())->toBe('2026-09-01')
            ->and($periodo['hasta']->toDateString())->toBe('2026-09-30');
    });

    test('las fechas al revés se dan vuelta, y más de un año se recorta', function (): void {
        $pantalla = Livewire::test(Caja::class)
            ->set('filtros.desde', '2026-10-20')
            ->set('filtros.hasta', '2026-10-05');

        $periodo = $pantalla->instance()->periodo();

        expect($periodo['desde']->toDateString())->toBe('2026-10-05')
            ->and($periodo['hasta']->toDateString())->toBe('2026-10-20')
            ->and($periodo['recortado'])->toBeFalse();

        $pantalla->set('filtros.desde', '2024-01-01')->set('filtros.hasta', '2026-10-24');

        $periodo = $pantalla->instance()->periodo();

        expect($periodo['recortado'])->toBeTrue()
            ->and($periodo['desde']->toDateString())->toBe('2025-10-24')
            ->and($periodo['hasta']->toDateString())->toBe('2026-10-24');
    });
});

/*
| `Roles::RECEPTOR` promete que «NO ve el arqueo de otro receptor». Se siembra
| el RoleSeeder de verdad: un rol armado a mano no prueba la matriz.
*/
describe('quién la ve', function (): void {
    beforeEach(function (): void {
        $this->seed(RoleSeeder::class);
        resolve(PermissionRegistrar::class)->forgetCachedPermissions();
    });

    test('el receptor entra, pero ve solo lo que recibió él', function (): void {
        $receptor = crearUsuarioConRol(Roles::RECEPTOR);

        ($this->cobrar)($this->norte, '1000.00', '2026-10-03', ['recibido_por' => $receptor->getKey()]);
        ($this->cobrar)($this->norte, '2000.00', '2026-10-03');

        $this->actingAs($receptor);

        $pantalla = Livewire::test(Caja::class)
            ->assertOk()
            ->assertSee('Lo que recibiste');

        expect($pantalla->instance()->caja()->total)->toBeMonto('1000.00');
    });

    test('la administradora ve la caja entera', function (): void {
        $receptor = crearUsuarioConRol(Roles::RECEPTOR);

        ($this->cobrar)($this->norte, '1000.00', '2026-10-03', ['recibido_por' => $receptor->getKey()]);
        ($this->cobrar)($this->norte, '2000.00', '2026-10-03');

        $this->actingAs(crearUsuarioConRol(Roles::ADMINISTRADORA));

        $pantalla = Livewire::test(Caja::class)
            ->assertOk()
            ->assertSee('Lo que entró');

        expect($pantalla->instance()->caja()->total)->toBeMonto('3000.00');
    });
});
