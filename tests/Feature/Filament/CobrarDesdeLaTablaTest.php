<?php

declare(strict_types=1);

use App\Domain\Enums\ConceptoDeRecibo;
use App\Domain\Enums\EstadoVenta;
use App\Domain\Enums\FormaDePago;
use App\Domain\ValueObjects\Monto;
use App\Domain\Ventas\RegistroDeVentas;
use App\Filament\Resources\Clientes\Pages\ViewCliente;
use App\Filament\Resources\Clientes\RelationManagers\VentasRelationManager;
use App\Filament\Resources\Ventas\Pages\ListVentas;
use App\Models\Bloque;
use App\Models\Cliente;
use App\Models\Cuota;
use App\Models\Lote;
use App\Models\Proyecto;
use App\Models\Recibo;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| Cobrar desde la tabla, sin salir de la pantalla donde se está
|--------------------------------------------------------------------------
| Lo pidió Mauricio el 10-ago-2026 y lo dijo dos veces. Primero: «en la tabla
| de ventas que esté el botón para pagar, ese que se abra así en modal
| también». Y después de probar el atajo por URL —que abría el expediente con
| el modal ya abierto— lo bajó en el acto: «acá no debe de redirigirme a la
| vista de ventas, siempre en la vista de cliente ahí debe de abrirse el
| modal».
|
| Por eso estos tests EJECUTAN la acción desde la fila. Un botón que fuera un
| link pasaría `assertActionVisible` y fallaría acá, que es exactamente la
| diferencia que importa.
|
| Un lote de 250 vr² a L 1,400.00 son L 350,000.00; con L 50,000.00 de prima
| quedan L 300,000.00 a 12 meses, o sea cuotas de L 25,000.00 exactas.
*/

beforeEach(function (): void {
    actingAsAdmin();

    $proyecto = Proyecto::factory()->create(['codigo' => 'RPS']);
    $bloque = Bloque::factory()->create(['proyecto_id' => $proyecto->getKey(), 'nombre' => 'A']);

    $lote = Lote::factory()->enBloque($bloque)->conMedidas('250.0000', '1400.00')->create(['numero' => '1']);

    $this->cliente = Cliente::factory()->create(['nombre' => 'Leticia Romero']);

    $this->venta = app(RegistroDeVentas::class)->activar(
        proyecto: $proyecto,
        lotes: [$lote],
        clientes: [$this->cliente],
        prima: new Monto('50000.00'),
        plazoMeses: 12,
        diaPago: 5,
    );

    $this->renglon = $this->venta->compromisos()->firstOrFail();

    $this->cobro = fn (array $extra = []): array => array_merge([
        'cobrar_'.$this->renglon->getKey() => true,
        'monto_'.$this->renglon->getKey()  => '25000.00',
        'forma_pago'                       => FormaDePago::Efectivo->value,
        'fecha'                            => today()->toDateString(),
    ], $extra);

    $this->enElListado = fn (): TestAction => TestAction::make('cobrar')->table($this->venta);
});

test('el pago se registra desde la fila del listado, sin navegar', function (): void {
    Livewire::test(ListVentas::class)
        ->callAction(($this->enElListado)(), ($this->cobro)())
        ->assertHasNoActionErrors();

    $recibo = Recibo::query()->where('concepto', ConceptoDeRecibo::Cuota)->sole();

    expect($recibo->montoTotal())->toBeMonto('25000.00')
        ->and($recibo->getAttribute('compromiso_id'))->toBe($this->renglon->getKey())
        ->and(Cuota::query()->where('compromiso_id', $this->renglon->getKey())->where('numero', 1)->value('monto_pagado'))
        ->toBe('25000.00');
});

/*
| La pantalla que de verdad importa. `VentasRelationManager` reusa entera la
| `VentasTable` vía `$relatedResource`, así que el modal llega solo — pero eso
| es justamente lo que hay que probar, porque es la pantalla donde Mauricio
| dijo que NO lo saquen.
*/
test('y también desde la pestaña Ventas de la ficha del cliente', function (): void {
    Livewire::test(VentasRelationManager::class, [
        'ownerRecord' => $this->cliente,
        'pageClass'   => ViewCliente::class,
    ])
        ->callAction(($this->enElListado)(), ($this->cobro)())
        ->assertHasNoActionErrors();

    expect(Recibo::query()->where('concepto', ConceptoDeRecibo::Cuota)->count())->toBe(1);
});

/*
| El modal abre proponiendo la cuota del mes de cada lote que debe, así que el
| caso de todos los días es abrir y confirmar. Sin datos, el `fillForm` es lo
| único que llena el formulario — y si se perdiera al mudarse a la clase
| compartida, esto se cae.
*/
test('abre con la cuota del mes ya propuesta', function (): void {
    Livewire::test(ListVentas::class)
        ->callAction(($this->enElListado)())
        ->assertHasNoActionErrors();

    expect(Recibo::query()->where('concepto', ConceptoDeRecibo::Cuota)->sole()->montoTotal())
        ->toBeMonto('25000.00');
});

describe('Quién ve el botón en la fila', function (): void {
    /*
    | La misma condición que adentro del expediente: un botón que abre un modal
    | que no se va a poder usar es peor que no tener botón.
    */
    test('quien no puede cobrar no lo ve', function (): void {
        $soloMira = rol('solo_mira');
        $soloMira->syncPermissions(['ViewAny:Venta', 'View:Venta']);

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($soloMira);

        resolve(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($user);

        Livewire::test(ListVentas::class)->assertActionHidden(($this->enElListado)());
    });

    test('un expediente rescindido no lo muestra', function (): void {
        $this->venta->update([
            'estado'     => EstadoVenta::Rescindida,
            'cerrada_el' => today(),
        ]);

        /*
         * 🔴 El `set('activeTab')` es lo que hace que este test pruebe algo.
         * Desde el 22-ago la pantalla abre en «Vigente», y un rescindido no
         * está en esa lista: sin pararse en «Todas», `assertActionHidden`
         * pasa porque la FILA no existe, no porque el botón esté oculto.
         */
        Livewire::test(ListVentas::class)
            ->set('activeTab', ListVentas::TODAS)
            ->assertActionHidden(($this->enElListado)());
    });
});

/*
| El atajo desde la ficha del cliente se cayó en silencio el 10-ago al agregar
| el botón: el `SelectFilter` del cliente se borró y esta pantalla se abría
| ENTERA. `QueTieneElClienteTest` lo cuenta con filas; esto lo dice con el
| nombre del filtro, que es el contrato con `ListadoDelCliente`.
*/
test('el filtro por cliente sigue en pie', function (): void {
    Livewire::test(ListVentas::class)
        ->filterTable('cliente', $this->cliente->getKey())
        ->assertCountTableRecords(1);
});
