<?php

declare(strict_types=1);

use App\Domain\Enums\FormaDePago;
use App\Domain\Pagos\RegistroDePagos;
use App\Domain\ValueObjects\Monto;
use App\Domain\Ventas\RegistroDeVentas;
use App\Filament\Resources\Recibos\Pages\ListRecibos;
use App\Filament\Resources\Recibos\Pages\ViewRecibo;
use App\Filament\Resources\Ventas\Pages\ViewVenta;
use App\Filament\Resources\Ventas\RelationManagers\RecibosRelationManager;
use App\Models\Bloque;
use App\Models\Cliente;
use App\Models\Lote;
use App\Models\Proyecto;
use App\Models\Recibo;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| Dónde se ven los recibos — módulo h) del contrato
|--------------------------------------------------------------------------
| Hasta hoy se emitían con número y no había una sola pantalla que los
| mostrara. Dos lugares, para dos preguntas distintas: la lista general es
| para quien llega con un papel y solo sabe el número; la pestaña del
| expediente es para «¿qué ha pagado este cliente?».
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

    $this->recibo = app(RegistroDePagos::class)->cobrarCuotas(
        venta: $this->venta,
        lote: $this->venta->compromisos()->firstOrFail(),
        cliente: $this->cliente,
        monto: new Monto('25000.00'),
        forma: FormaDePago::Efectivo,
    );
});

describe('La lista general', function (): void {
    test('muestra lo cobrado', function (): void {
        Livewire::test(ListRecibos::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords(Recibo::query()->get())
            ->assertSee($this->recibo->folio());
    });

    /*
    | Es la razón de ser de esta pantalla: alguien llega con el papel y lo
    | único que sabe es el número.
    */
    test('se busca por número', function (): void {
        Livewire::test(ListRecibos::class)
            ->searchTable((string) $this->recibo->getAttribute('numero'))
            ->assertCanSeeTableRecords(Recibo::query()->get());
    });

    test('la ficha se abre sin imprimir nada', function (): void {
        Livewire::test(ViewRecibo::class, ['record' => $this->recibo->getKey()])
            ->assertSuccessful()
            ->assertSee('VEINTICINCO MIL LEMPIRAS CON 00/100');

        // Mirar no es imprimir: para el papel está el botón, y ese sí registra.
        expect($this->recibo->refresh()->vecesImpreso())->toBe(0);
    });
});

test('la pestaña del expediente muestra los recibos de ese contrato', function (): void {
    Livewire::test(RecibosRelationManager::class, [
        'ownerRecord' => $this->venta,
        'pageClass'   => ViewVenta::class,
    ])
        ->assertSuccessful()
        ->assertCanSeeTableRecords(Recibo::query()->get())
        ->assertSee('nunca');
});

describe('Quién entra', function (): void {
    /*
    | El receptor cobra, así que tiene que poder buscar y reimprimir lo que
    | cobró. Es su trabajo, y las dos lecturas ya se las da el RoleSeeder.
    */
    test('el receptor ve la lista', function (): void {
        $receptor = rol('receptor_de_prueba');
        $receptor->syncPermissions(['ViewAny:Recibo', 'View:Recibo', 'Create:Recibo']);

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($receptor);

        resolve(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($user);

        Livewire::test(ListRecibos::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords(Recibo::query()->get());
    });

    test('quien no tiene permiso de recibos no entra', function (): void {
        $soloVentas = rol('solo_ventas');
        $soloVentas->syncPermissions(['ViewAny:Venta', 'View:Venta']);

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($soloVentas);

        resolve(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($user);

        Livewire::test(ListRecibos::class)->assertForbidden();
    });
});
