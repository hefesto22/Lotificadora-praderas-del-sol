<?php

declare(strict_types=1);

use App\Domain\Enums\FormaDePago;
use App\Domain\Pagos\RegistroDePagos;
use App\Domain\ValueObjects\Monto;
use App\Domain\Ventas\PrecioPactado;
use App\Domain\Ventas\RegistroDeVentas;
use App\Filament\Resources\Ventas\Pages\ViewVenta;
use App\Filament\Resources\Ventas\RelationManagers\ReprogramacionesRelationManager;
use App\Models\Bloque;
use App\Models\Cliente;
use App\Models\Compromiso;
use App\Models\Cuota;
use App\Models\Lote;
use App\Models\Proyecto;
use App\Models\Reestructuracion;
use App\Support\Roles;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| «Reestructurar el contrato» desde el expediente — 25-oct-2026
|--------------------------------------------------------------------------
| El botón vive en el ⋮ «Más trámites» de la ficha. La cuenta está probada
| en `PlanUnificadoTest` y el Service en `ReestructurarElContratoTest` de
| Dominio; acá, que el camino de la PANTALLA llega al Service con lo que se
| tecleó, que el acta se imprime, y quién ve el botón — con los roles
| SEMBRADOS, no con uno armado a mano.
|
| El escenario es el del 0033: dos lotes que deben L 240,000.00 cada uno, con
| L 40,000.00 pagados en cada uno.
*/

beforeEach(function (): void {
    actingAsAdmin();

    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));

    $proyecto = Proyecto::factory()->create(['codigo' => 'RPS']);
    $bloque = Bloque::factory()->create(['proyecto_id' => $proyecto->getKey(), 'nombre' => 'A']);
    $lotes = [
        Lote::factory()->enBloque($bloque)->conMedidas('250.0000', '1200.000000')->create(['numero' => '1']),
        Lote::factory()->enBloque($bloque)->conMedidas('250.0000', '1200.000000')->create(['numero' => '2']),
    ];

    $this->cliente = Cliente::factory()->create(['nombre' => 'CLIENTE DEL EXPEDIENTE 0033']);

    $this->venta = app(RegistroDeVentas::class)->activar(
        proyecto: $proyecto,
        lotes: $lotes,
        clientes: [$this->cliente],
        prima: new Monto('120000.00'),
        plazoMeses: 48,
        diaPago: 15,
        fechaContrato: CarbonImmutable::parse('2026-06-15'),
        precios: array_map(static fn (Lote $lote): PrecioPactado => new PrecioPactado(
            loteId: (int) $lote->getKey(),
            precioVara: new Monto('1200.000000'),
            prima: new Monto('60000.00'),
        ), $lotes),
    );

    $this->compromisos = Compromiso::query()->where('venta_id', $this->venta->getKey())->orderBy('id')->get()->all();

    $this->travelTo(CarbonImmutable::parse('2026-07-10 09:00:00'));

    app(RegistroDePagos::class)->cobrarVariosLotes(
        venta: $this->venta,
        cliente: $this->cliente,
        renglones: array_values(array_map(static fn (Compromiso $lote): array => ['lote' => $lote, 'monto' => new Monto('40000.00')], $this->compromisos)),
        forma: FormaDePago::Efectivo,
    );

    $this->travelTo(CarbonImmutable::parse('2026-10-25 11:00:00'));

    $this->expediente = fn (): object => Livewire::test(
        ViewVenta::class,
        ['record' => $this->venta->getKey()],
    );

    $this->cadaLoteDesdeLaUno = fn (): array => [
        'lotes'              => array_map(static fn (Compromiso $lote): string => (string) $lote->getKey(), $this->compromisos),
        'agrupacion'         => 'cada_lote',
        'desde_la_primera'   => true,
        'meses'              => '24',
        'primer_vencimiento' => '2026-07-15',
        'motivo'             => 'Cada lote a 24 meses, con lo pagado desde la cuota 1',
    ];
});

test('el modal abre con cada lote con su cuota, sin meses: no muestra un plan que nadie pidió', function (): void {
    ($this->expediente)()
        ->mountAction('reestructurar_plan')
        ->assertActionDataSet([
            'agrupacion'       => 'cada_lote',
            'desde_la_primera' => false,
            'meses'            => null,
            'cuota'            => null,
        ]);
});

test('🔴 la administradora reestructura el 0033 desde el expediente: cada lote a L 10,000.00 y sale el acta', function (): void {
    ($this->expediente)()
        ->callAction('reestructurar_plan', ($this->cadaLoteDesdeLaUno)())
        ->assertHasNoActionErrors()
        ->assertNotified();

    $acta = Reestructuracion::query()->sole();

    expect($acta->cadaLoteConSuCuota())->toBeTrue()
        ->and((int) $acta->getAttribute('meses_despues'))->toBe(24)
        ->and($acta->montoCuotaNueva())->toBeMonto('20000.00');

    foreach ($this->compromisos as $lote) {
        $montos = Cuota::query()->where('compromiso_id', $lote->getKey())->orderBy('numero')->pluck('monto')->map(static fn (mixed $monto): string => is_scalar($monto) ? (string) $monto : '')->unique()->values()->all();

        expect($montos)->toBe(['10000.00']);
    }

    $this->get(route('documentos.reestructuracion', $acta))
        ->assertOk()
        ->assertSee('Acta de reestructuración del plan')
        ->assertSee($acta->folio())
        ->assertSee('Cómo queda cada lote')
        ->assertSee('Cada lote queda con su propio plan y su propia cuota')
        ->assertSee('El plan no lleva interés')
        ->assertSee('Cada lote a 24 meses, con lo pagado desde la cuota 1');
});

test('unificados piden la cuota: sin ella no se reestructura', function (): void {
    ($this->expediente)()
        ->callAction('reestructurar_plan', [...($this->cadaLoteDesdeLaUno)(), 'agrupacion' => 'unificados'])
        ->assertHasActionErrors(['cuota' => 'required']);

    expect(Reestructuracion::query()->count())->toBe(0);
});

test('en Reprogramaciones queda una constancia por lote, con el botón del acta', function (): void {
    ($this->expediente)()
        ->callAction('reestructurar_plan', ($this->cadaLoteDesdeLaUno)())
        ->assertHasNoActionErrors();

    $acta = Reestructuracion::query()->sole();

    Livewire::test(ReprogramacionesRelationManager::class, [
        'ownerRecord' => $this->venta->refresh(),
        'pageClass'   => ViewVenta::class,
    ])
        ->assertSee('Reestructuración del plan')
        ->assertSee(route('documentos.reestructuracion', $acta), escape: false);
});

/*
| El receptor cobra lo que el contrato dice: no lo rehace. Se siembra el
| RoleSeeder de verdad, no un rol armado a mano: así, el día que alguien
| meta `Reestructurar` en el cruce del receptor, esta línea se pone roja.
*/
test('el receptor NO ve «Reestructurar el contrato», la administradora sí', function (): void {
    $this->seed(RoleSeeder::class);

    $receptor = crearUsuarioConRol(Roles::RECEPTOR);
    $administradora = crearUsuarioConRol(Roles::ADMINISTRADORA);

    resolve(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->actingAs($receptor);

    expect($receptor->can('Reestructurar:Venta'))->toBeFalse();
    ($this->expediente)()->assertActionHidden('reestructurar_plan');

    $this->actingAs($administradora);

    expect($administradora->can('Reestructurar:Venta'))->toBeTrue();
    ($this->expediente)()->assertActionVisible('reestructurar_plan');
});
