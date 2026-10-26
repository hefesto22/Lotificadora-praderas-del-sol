<?php

declare(strict_types=1);

use App\Domain\Enums\FormaDePago;
use App\Domain\Pagos\RegistroDePagos;
use App\Domain\ValueObjects\Monto;
use App\Domain\Ventas\PrecioPactado;
use App\Domain\Ventas\RegistroDeVentas;
use App\Filament\Resources\Ventas\Pages\ViewVenta;
use App\Filament\Resources\Ventas\RelationManagers\CuotasRelationManager;
use App\Models\Bloque;
use App\Models\Cliente;
use App\Models\Compromiso;
use App\Models\Cuota;
use App\Models\Lote;
use App\Models\Proyecto;
use App\Models\Reestructuracion;
use App\Models\Venta;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| El plan de cuotas, una pestaña por lote — 26-oct-2026
|--------------------------------------------------------------------------
| «Que me aparezcan las cuotas en un toggle de cada lote para que no se
| confunda con esa intercalación; si no está unificada, como es este caso,
| un toggle para las cuotas de cada uno; si es unificada, solo uno para los
| lotes unificados» — Mauricio, mirando el 0033 recién reestructurado.
|
| El escenario es el del 0033: cada lote debe L 240,000.00 a 48 meses y
| tiene L 40,000.00 pagados. Se reestructura desde la cuota 1, con el primer
| vencimiento el 15/07/2026: lo pagado cubre las cuotas 1 a 4 y el contrato
| queda al día.
*/

beforeEach(function (): void {
    actingAsAdmin();

    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));

    $proyecto = Proyecto::factory()->create(['codigo' => 'RPS']);
    $bloque = Bloque::factory()->create(['proyecto_id' => $proyecto->getKey(), 'nombre' => 'A']);
    $cliente = Cliente::factory()->create(['nombre' => 'CLIENTE DEL PLAN DE CUOTAS']);

    $this->lotesDe = static fn (Venta $venta): array => Compromiso::query()
        ->where('venta_id', $venta->getKey())
        ->orderBy('id')
        ->get()
        ->all();

    $this->primeraDe = static fn (Compromiso $lote): Cuota => Cuota::query()
        ->where('compromiso_id', $lote->getKey())
        ->orderBy('numero')
        ->firstOrFail();

    $this->contratoDe = function (int $cuantos) use ($proyecto, $bloque, $cliente): Venta {
        $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));

        $lotes = [];

        for ($numero = 1; $numero <= $cuantos; $numero++) {
            $lotes[] = Lote::factory()->enBloque($bloque)->conMedidas('250.0000', '1200.000000')->create(['numero' => (string) $numero]);
        }

        $venta = app(RegistroDeVentas::class)->activar(
            proyecto: $proyecto,
            lotes: $lotes,
            clientes: [$cliente],
            prima: new Monto(bcmul('60000.00', (string) $cuantos, 2)),
            plazoMeses: 48,
            diaPago: 15,
            fechaContrato: CarbonImmutable::parse('2026-06-15'),
            precios: array_map(static fn (Lote $lote): PrecioPactado => new PrecioPactado(
                loteId: (int) $lote->getKey(),
                precioVara: new Monto('1200.000000'),
                prima: new Monto('60000.00'),
            ), $lotes),
        );

        $this->travelTo(CarbonImmutable::parse('2026-07-10 09:00:00'));

        app(RegistroDePagos::class)->cobrarVariosLotes(
            venta: $venta,
            cliente: $cliente,
            renglones: array_values(array_map(static fn (Compromiso $lote): array => ['lote' => $lote, 'monto' => new Monto('40000.00')], ($this->lotesDe)($venta))),
            forma: FormaDePago::Efectivo,
        );

        $this->travelTo(CarbonImmutable::parse('2026-10-25 11:00:00'));

        return $venta->refresh();
    };

    /*
     * Por la pantalla, como lo hizo la administradora: el camino de verdad
     * llega al Service con lo que se tecleó.
     */
    $this->reestructurar = function (Venta $venta, array $lotes, array $datos): void {
        Livewire::test(ViewVenta::class, ['record' => $venta->getKey()])
            ->callAction('reestructurar_plan', [
                'lotes'              => array_map(static fn (Compromiso $lote): string => (string) $lote->getKey(), $lotes),
                'desde_la_primera'   => true,
                'meses'              => '24',
                'primer_vencimiento' => '2026-07-15',
                'motivo'             => 'Con lo pagado desde la cuota 1',
                ...$datos,
            ])
            ->assertHasNoActionErrors();
    };

    $this->planDeCuotas = static fn (Venta $venta) => Livewire::test(CuotasRelationManager::class, [
        'ownerRecord' => $venta->refresh(),
        'pageClass'   => ViewVenta::class,
    ]);
});

test('🔴 el 0033, cada lote con su cuota: una pestaña por lote, abre en el primero y el contrato entero al final', function (): void {
    $venta = ($this->contratoDe)(2);
    [$uno, $dos] = ($this->lotesDe)($venta);

    ($this->reestructurar)($venta, [$uno, $dos], ['agrupacion' => 'cada_lote']);

    ($this->planDeCuotas)($venta)
        ->assertSet('activeTab', 'lote-'.$uno->getKey())
        ->assertSeeInOrder(['Lote RPS-A-001', 'Al día', 'Lote RPS-A-002', 'Al día', 'Todo el contrato'])
        // Las del primer lote, sin las del segundo intercaladas.
        ->assertCanSeeTableRecords([($this->primeraDe)($uno)])
        ->assertCanNotSeeTableRecords([($this->primeraDe)($dos)])
        ->assertSeeInOrder(['1 de 24', '15/07/2026', '10,000.00', '10,000.00', '0.00', 'Pagada'])
        ->set('activeTab', 'lote-'.$dos->getKey())
        ->assertCanSeeTableRecords([($this->primeraDe)($dos)])
        ->assertCanNotSeeTableRecords([($this->primeraDe)($uno)])
        // «Todo el contrato» es la lista de siempre: los dos lotes.
        ->set('activeTab', 'todo')
        ->assertCanSeeTableRecords([($this->primeraDe)($uno), ($this->primeraDe)($dos)]);
});

test('el lote que se atrasa lo dice en su pestaña, sin entrar', function (): void {
    $venta = ($this->contratoDe)(2);
    [$uno, $dos] = ($this->lotesDe)($venta);

    ($this->reestructurar)($venta, [$uno, $dos], ['agrupacion' => 'cada_lote']);

    // Vence la cuota 5 el 15/11; para el 1/12 solo el primero la pagó.
    $this->travelTo(CarbonImmutable::parse('2026-11-10 09:00:00'));

    app(RegistroDePagos::class)->cobrarCuotas(
        venta: $venta,
        lote: $uno,
        cliente: $venta->clientes()->firstOrFail(),
        monto: new Monto('10000.00'),
        forma: FormaDePago::Efectivo,
    );

    $this->travelTo(CarbonImmutable::parse('2026-12-01 09:00:00'));

    ($this->planDeCuotas)($venta)
        ->assertSeeInOrder(['Lote RPS-A-001', 'Al día', 'Lote RPS-A-002', 'Atrasado', 'Todo el contrato']);
});

test('los unificados van en UNA pestaña: un renglón por mes con lo de cada lote, y el otro lote aparte', function (): void {
    $venta = ($this->contratoDe)(3);
    [$uno, $dos, $tres] = ($this->lotesDe)($venta);

    ($this->reestructurar)($venta, [$uno, $dos], ['agrupacion' => 'unificados', 'cuota' => '20000.00']);

    $acta = Reestructuracion::query()->sole();

    ($this->planDeCuotas)($venta)
        ->assertSet('activeTab', 'plan-'.$acta->getKey())
        ->assertSeeInOrder(['Plan único: RPS-A-001 y RPS-A-002', 'Lote RPS-A-003', 'Todo el contrato'])
        ->assertSeeInOrder(['Cuota', 'Vence', 'RPS-A-001', 'RPS-A-002', 'A pagar', 'Pagado', 'Falta', 'Estado'])
        ->assertSeeInOrder(['1 de 24', '15/07/2026', '10,000.00', '10,000.00', '20,000.00', '20,000.00', '0.00', 'Pagada'])
        // Un renglón por mes: el del primer lote lleva el mes entero.
        ->assertCanSeeTableRecords([($this->primeraDe)($uno)])
        ->assertCanNotSeeTableRecords([($this->primeraDe)($dos), ($this->primeraDe)($tres)])
        ->set('activeTab', 'lote-'.$tres->getKey())
        ->assertCanSeeTableRecords([($this->primeraDe)($tres)])
        ->assertCanNotSeeTableRecords([($this->primeraDe)($uno)])
        ->assertSee('1 de 48');
});

test('con todos los lotes en una sola cuota no hay pestañas: hay un plan solo', function (): void {
    $venta = ($this->contratoDe)(2);
    [$uno, $dos] = ($this->lotesDe)($venta);

    ($this->reestructurar)($venta, [$uno, $dos], ['agrupacion' => 'unificados', 'cuota' => '20000.00']);

    ($this->planDeCuotas)($venta)
        ->assertSet('activeTab', null)
        ->assertDontSee('Todo el contrato')
        ->assertSeeInOrder(['RPS-A-001', 'RPS-A-002', 'A pagar', '1 de 24', '10,000.00', '10,000.00', '20,000.00'])
        ->assertCanSeeTableRecords([($this->primeraDe)($uno)])
        ->assertCanNotSeeTableRecords([($this->primeraDe)($dos)]);
});

test('un contrato de un solo lote no lleva pestañas, como siempre', function (): void {
    $venta = ($this->contratoDe)(1);

    ($this->planDeCuotas)($venta)
        ->assertSet('activeTab', null)
        ->assertDontSee('Todo el contrato')
        ->assertSee('1 de 48');
});
