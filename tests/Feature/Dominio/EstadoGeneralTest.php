<?php

declare(strict_types=1);

use App\Domain\Enums\ConceptoDeRecibo;
use App\Domain\Enums\EstadoCompromiso;
use App\Domain\Enums\EstadoLote;
use App\Domain\Enums\EstadoVenta;
use App\Domain\Enums\FormaDePago;
use App\Domain\Enums\TipoCompromiso;
use App\Domain\Reportes\CierreDelMes;
use App\Domain\Reportes\EstadoGeneral;
use App\Filament\Pages\EstadoMensual;
use App\Models\Bloque;
use App\Models\Cliente;
use App\Models\Compromiso;
use App\Models\Cuota;
use App\Models\EntregaASocio;
use App\Models\Gasto;
use App\Models\Lote;
use App\Models\Proyecto;
use App\Models\Recibo;
use App\Models\Socio;
use App\Models\Venta;
use App\Support\Roles;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| El estado general del proyecto — 10-oct-2026
|--------------------------------------------------------------------------
| Mauricio: «un estado de resultados general de todo: ganancias, gastos,
| cuánto recibió cada socio y en qué, con proyección de lo que ya se vendió y
| falta de cobrar».
|
| Lo que cuidan estos tests:
|  1. Que cuente TODO, no un mes, con las mismas reglas del estado mensual.
|  2. Que los años sumen los totales, y que la suma de los meses dé lo mismo.
|  3. El reparto sobre la utilidad de toda la vida, y lo entregado por forma.
|  4. La cartera: vendido, lo que falta cobrar, lo atrasado y por año.
|  5. Los lotes y la proyección.
|  6. Quién la puede ver, y que la hoja se dibuje.
|
| El día de corte va fijo —10-oct-2026— para que lo atrasado y los años no
| dependan de cuándo se corra la suite.
*/

beforeEach(function (): void {
    actingAsAdmin();

    $this->proyecto = Proyecto::factory()->create(['codigo' => 'RPS']);
    $this->hoy = CarbonImmutable::parse('2026-10-10');

    $this->mayor = Socio::factory()->delProyecto($this->proyecto)->conParte('60.00')->create(['nombre' => 'ELDER']);
    $this->menor = Socio::factory()->delProyecto($this->proyecto)->conParte('40.00')->create(['nombre' => 'EDWIN']);

    // Un recibo del proyecto sin pasar por el Service, como en los tests del
    // estado mensual: lo que se prueba es la cuenta, no el cobro.
    $this->cobrar = function (string $monto, string $fecha, array $extra = []): Recibo {
        $venta = Venta::factory()->create(['proyecto_id' => $this->proyecto->getKey()]);

        return Recibo::factory()->create(array_merge([
            'venta_id'   => $venta->getKey(),
            'cliente_id' => Cliente::factory()->create()->getKey(),
            'concepto'   => ConceptoDeRecibo::Cuota,
            'forma_pago' => FormaDePago::Efectivo,
            'monto'      => $monto,
            'fecha'      => $fecha,
        ], $extra));
    };

    $this->entregar = fn (Socio $socio, string $monto, FormaDePago $forma = FormaDePago::Efectivo): EntregaASocio => EntregaASocio::query()->create([
        'proyecto_id' => $this->proyecto->getKey(),
        'socio_id'    => $socio->getKey(),
        'monto'       => $monto,
        'forma_pago'  => $forma,
        'referencia'  => $forma === FormaDePago::Efectivo ? null : 'TRF-1',
        'fecha'       => '2026-07-15',
        'mes'         => '2026-07-01',
    ]);

    $this->general = fn (): EstadoGeneral => EstadoGeneral::de($this->proyecto, $this->hoy);
});

describe('Lo que entró y lo que salió', function (): void {
    /*
    | Tres años, un anulado y un gasto de cada lado. Utilidad: 100,000 −
    | 20,000 = 80,000.
    */
    beforeEach(function (): void {
        ($this->cobrar)('10000.00', '2024-03-10');
        ($this->cobrar)('20000.00', '2025-07-10', ['forma_pago' => FormaDePago::Transferencia, 'referencia' => 'TRF-9']);
        ($this->cobrar)('70000.00', '2026-07-10');
        ($this->cobrar)('9999.00', '2026-07-11', ['anulado_el' => '2026-07-12', 'motivo_anulacion' => 'Error de carga.']);

        Gasto::factory()->delProyecto($this->proyecto)->de('15000.00')->conFecha('2024-05-01')->create();
        Gasto::factory()->delProyecto($this->proyecto)->de('5000.00')->conFecha('2026-07-05')->create();
    });

    test('cuenta toda la vida del proyecto, sin los anulados', function (): void {
        $general = ($this->general)();

        expect($general->cobrado)->toBeMonto('100000.00')
            ->and($general->recibos)->toBe(3)
            ->and($general->salidas)->toBeMonto('20000.00')
            ->and($general->gastos)->toBe(2)
            ->and($general->utilidad)->toBeMonto('80000.00')
            ->and($general->perdida)->toBeMonto('0.00')
            ->and($general->cobradoPorForma[FormaDePago::Efectivo->value])->toBeMonto('80000.00')
            ->and($general->cobradoPorForma[FormaDePago::Transferencia->value])->toBeMonto('20000.00')
            ->and($general->desde?->toDateString())->toBe('2024-03-10');
    });

    /*
    | 🔴 El que justifica que las consultas sean las del estado mensual: los
    | años suman los totales, y lo de un mes es lo que dice su hoja.
    */
    test('los años suman los totales, y el mes coincide con su estado mensual', function (): void {
        $general = ($this->general)();

        $anios = array_column($general->anioPorAnio, 'anio');
        $ingresos = array_map(static fn (array $fila): string => $fila['ingresos']->redondeado(), $general->anioPorAnio);
        $egresos = array_map(static fn (array $fila): string => $fila['egresos']->redondeado(), $general->anioPorAnio);

        expect($anios)->toBe([2024, 2025, 2026])
            ->and($ingresos)->toBe(['10000.00', '20000.00', '70000.00'])
            ->and($egresos)->toBe(['15000.00', '0.00', '5000.00'])
            ->and($general->anioPorAnio[0]['perdida'])->toBeMonto('5000.00')
            ->and(CierreDelMes::de($this->proyecto, CarbonImmutable::parse('2026-07-01'))->cobradoDelMes)->toBeMonto('70000.00');
    });
});

describe('Los socios', function (): void {
    test('reparte la utilidad de toda la vida y dice lo entregado en cada forma', function (): void {
        ($this->cobrar)('100000.00', '2026-07-10');
        Gasto::factory()->delProyecto($this->proyecto)->de('20000.00')->conFecha('2026-07-05')->create();

        ($this->entregar)($this->mayor, '30000.00');
        ($this->entregar)($this->mayor, '10000.00', FormaDePago::Transferencia);
        ($this->entregar)($this->menor, '40000.00');

        $general = ($this->general)();
        [$elder, $edwin] = $general->reparto->partes;

        // 80,000 × 60/40 = 48,000 y 32,000.
        expect($elder->leToca)->toBeMonto('48000.00')
            ->and($elder->entregado)->toBeMonto('40000.00')
            ->and($elder->porEntregar)->toBeMonto('8000.00')
            ->and($edwin->leToca)->toBeMonto('32000.00')
            ->and($edwin->entregadoDeMas)->toBeMonto('8000.00')
            ->and($edwin->marcaDelSobregiro())->toBe('Se le adelantó')
            ->and($general->formasDeEntrega())->toBe([FormaDePago::Efectivo->value, FormaDePago::Transferencia->value])
            ->and($general->entregadoA($this->mayor)[FormaDePago::Transferencia->value])->toBeMonto('10000.00')
            ->and($general->entregadoATodos())->toBeMonto('80000.00');
    });

    /*
    | La regla del 10-oct en la cuenta de toda la vida: lo que un socio puso
    | baja la utilidad de todos, y a él se le devuelve.
    */
    test('lo que un socio puso de su bolsa se le devuelve', function (): void {
        ($this->cobrar)('100000.00', '2026-07-10');
        Gasto::factory()->pagadoPor($this->menor)->de('10000.00')->conFecha('2026-07-20')->create();

        $general = ($this->general)();
        [$elder, $edwin] = $general->reparto->partes;

        // Utilidad 90,000: Elder 54,000; Edwin 36,000 + 10,000 que puso.
        expect($general->utilidad)->toBeMonto('90000.00')
            ->and($general->puestoPorSociosTotal)->toBeMonto('10000.00')
            ->and($elder->porEntregar)->toBeMonto('54000.00')
            ->and($edwin->porEntregar)->toBeMonto('46000.00');
    });

    test('quien ya no participa sale con lo que recibió, fuera del reparto', function (): void {
        $antes = Socio::factory()->delProyecto($this->proyecto)->conParte('10.00')->inactivo()->create(['nombre' => 'MARIO']);
        ($this->entregar)($antes, '5000.00');

        $general = ($this->general)();

        expect($general->reparto->partes)->toHaveCount(2)
            ->and($general->exSocios)->toHaveCount(1)
            ->and($general->exSocios[0]['nombre'])->toBe('MARIO')
            ->and($general->exSocios[0]['entregado'])->toBeMonto('5000.00');
    });
});

describe('La cartera y la proyección', function (): void {
    beforeEach(function (): void {
        $bloque = Bloque::factory()->create(['proyecto_id' => $this->proyecto->getKey()]);

        $lote = fn (string $area, string $precio, EstadoLote $estado): Lote => Lote::factory()
            ->enBloque($bloque)
            ->conMedidas($area, $precio)
            ->conEstado($estado)
            ->create()
            ->refresh();

        // Expediente vigente: un lote de 350,000 y uno rescindido.
        $vigente = Venta::factory()->delProyecto($this->proyecto)->vigente(1)->create();
        $vivo = Compromiso::factory()->paraLote($lote('250.0000', '1400.00', EstadoLote::Vendido))
            ->deTipo(TipoCompromiso::Venta)
            ->create(['venta_id' => $vigente->getKey()]);
        $caido = Compromiso::factory()->paraLote($lote('200.0000', '500.00', EstadoLote::Disponible))
            ->deTipo(TipoCompromiso::Venta)
            ->cerrado(EstadoCompromiso::Rescindido)
            ->create(['venta_id' => $vigente->getKey()]);

        $cuota = fn (int $numero, string $vence, string $monto, string $pagado = '0.00', ?Compromiso $de = null): Cuota => Cuota::factory()
            ->deLaVenta($vigente)
            ->create([
                'compromiso_id'     => ($de ?? $vivo)->getKey(),
                'numero'            => $numero,
                'fecha_vencimiento' => $vence,
                'monto'             => $monto,
                'monto_pagado'      => $pagado,
            ]);

        $cuota(1, '2026-07-05', '3000.00', '3000.00');   // pagada
        $cuota(2, '2026-08-05', '3000.00', '1000.00');   // atrasada a medias: debe 2,000
        $cuota(3, '2026-09-05', '3000.00');              // atrasada: debe 3,000
        $cuota(4, '2026-11-05', '3000.00');              // este año
        $cuota(5, '2027-03-05', '3000.00');              // próximos 12 meses
        $cuota(6, '2027-11-05', '3000.00');              // 2027, fuera de los 12 meses
        $cuota(7, '2028-01-05', '3000.00');
        $cuota(1, '2026-09-05', '9999.00', '0.00', $caido); // lote rescindido: no se cobra

        // Un expediente liquidado: su lote sigue vendido, no debe nada.
        $liquidado = Venta::factory()->delProyecto($this->proyecto)->vigente(2)->create();
        Compromiso::factory()->paraLote($lote('100.0000', '1000.00', EstadoLote::Vendido))
            ->deTipo(TipoCompromiso::Venta)
            ->create(['venta_id' => $liquidado->getKey()]);
        // `cerrada_el` no puede ser anterior al contrato, y el contrato de la
        // factory es de hoy (CHECK `ventas_cierre_posterior_al_contrato_chk`).
        $liquidado->forceFill(['estado' => EstadoVenta::Liquidada, 'cerrada_el' => today()->toDateString()])->saveQuietly();

        // Un apartado en curso con su seña, y un lote sin precio.
        $apartado = Compromiso::factory()->paraLote($lote('100.0000', '1000.00', EstadoLote::Apartado))->create();
        Recibo::factory()->create([
            'venta_id'      => null,
            'compromiso_id' => $apartado->getKey(),
            'cliente_id'    => $apartado->getAttribute('cliente_id'),
            'concepto'      => ConceptoDeRecibo::Senia,
            'monto'         => '5000.00',
            'fecha'         => '2026-10-01',
        ]);
        $lote('150.0000', '0.00', EstadoLote::Disponible);
    });

    test('lo vendido, lo que falta cobrar y lo atrasado', function (): void {
        $general = ($this->general)();

        // 3,000 + 2,000 atrasados; 3,000 × 4 por vencer. El rescindido no.
        expect($general->vendido)->toBeMonto('450000.00')
            ->and($general->lotesVendidos)->toBe(2)
            ->and($general->expedientes)->toBe(2)
            ->and($general->porCobrar)->toBeMonto('17000.00')
            ->and($general->atrasado)->toBeMonto('5000.00')
            ->and($general->expedientesAtrasados)->toBe(1)
            ->and($general->porVencer())->toBeMonto('12000.00')
            ->and($general->proximos12Meses)->toBeMonto('6000.00')
            ->and($general->yaPagadoDeLoVendido())->toBeMonto('433000.00');
    });

    test('lo que falta cobrar, año por año, sin lo atrasado', function (): void {
        $porAnio = ($this->general)()->porCobrarPorAnio;

        expect(array_column($porAnio, 'anio'))->toBe([2026, 2027, 2028])
            ->and(array_column($porAnio, 'cuotas'))->toBe([1, 2, 1])
            ->and($porAnio[1]['monto'])->toBeMonto('6000.00');
    });

    test('las señas en curso van aparte: no son ingreso todavía', function (): void {
        $general = ($this->general)();

        expect($general->seniasEnCurso)->toBeMonto('5000.00')
            ->and($general->apartadosEnCurso)->toBe(1)
            ->and($general->cobrado)->toBeMonto('0.00');
    });

    test('los lotes por estado, sin ponerle precio a lo que no se vendió', function (): void {
        $general = ($this->general)();
        $porEstado = [];

        foreach ($general->lotes as $fila) {
            $porEstado[$fila['estado']->value] = $fila['cuantos'];
        }

        expect($porEstado)->toBe([
            EstadoLote::Disponible->value => 2,
            EstadoLote::Apartado->value   => 1,
            EstadoLote::Vendido->value    => 2,
        ])
            ->and($general->totalDeLotes())->toBe(5)
            ->and($general->parteDeLotes(2))->toBe('40');
    });

    test('la proyección suma lo que falta cobrar de lo vendido', function (): void {
        Gasto::factory()->delProyecto($this->proyecto)->de('20000.00')->conFecha('2026-07-05')->create();

        $general = ($this->general)();

        // Sin un cobro y con 20,000 gastados: pérdida 20,000. Si entra lo
        // que falta (17,000), sigue en rojo por 3,000.
        expect($general->perdida)->toBeMonto('20000.00')
            ->and($general->siSeCobraTodo()['perdida'])->toBeMonto('3000.00');
    });

    /*
    | Mauricio, 10-oct-2026: «los que no se han vendido que no se proyecten,
    | ya que unos quizás no pertenezcan al proyecto». Acá hay dos lotes
    | disponibles y uno apartado, y la hoja no les pone precio.
    */
    test('lo que no se ha vendido no se proyecta', function (): void {
        $this->get(route('documentos.estado-general', ['proyecto' => $this->proyecto]))
            ->assertOk()
            ->assertSeeText('Si se cobra todo lo vendido')
            ->assertDontSeeText('Si además se vende todo')
            ->assertDontSeeText('precio de lista');
    });
});

describe('La hoja y quién la ve', function (): void {
    test('la hoja se dibuja con todas sus partes', function (): void {
        ($this->cobrar)('100000.00', '2026-07-10');
        ($this->entregar)($this->mayor, '30000.00');

        $this->get(route('documentos.estado-general', ['proyecto' => $this->proyecto]))
            ->assertOk()
            ->assertSeeText('Estado general de resultados')
            ->assertSeeText('Quién recibió el dinero')
            ->assertSeeText('Lo que se le ha entregado a cada socio, y en qué forma')
            ->assertSeeText('Lo vendido y lo que falta cobrar')
            ->assertSeeText('Hacia dónde va el proyecto')
            ->assertSeeText('ELDER');
    });

    test('un proyecto sin nada se dibuja igual, con sus avisos', function (): void {
        $this->get(route('documentos.estado-general', ['proyecto' => $this->proyecto]))
            ->assertOk()
            ->assertSeeText('todavía no tiene cobros ni gastos registrados');
    });

    test('la administradora la ve; el receptor no', function (): void {
        $this->seed(RoleSeeder::class);
        resolve(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs(crearUsuarioConRol(Roles::ADMINISTRADORA));
        $this->get(route('documentos.estado-general', ['proyecto' => $this->proyecto]))->assertOk();

        $this->actingAs(crearUsuarioConRol(Roles::RECEPTOR));
        $this->get(route('documentos.estado-general', ['proyecto' => $this->proyecto]))->assertForbidden();
    });

    test('la pantalla del Estado mensual tiene el botón', function (): void {
        Livewire::test(EstadoMensual::class)->assertActionVisible('general');
    });
});
