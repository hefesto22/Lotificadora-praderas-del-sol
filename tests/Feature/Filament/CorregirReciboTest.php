<?php

declare(strict_types=1);

use App\Domain\Enums\FormaDePago;
use App\Domain\Pagos\RegistroDePagos;
use App\Domain\ValueObjects\Monto;
use App\Domain\Ventas\RegistroDeVentas;
use App\Filament\Resources\Recibos\Pages\ViewRecibo;
use App\Models\Bloque;
use App\Models\Cliente;
use App\Models\Lote;
use App\Models\Proyecto;
use App\Support\Roles;
use Database\Seeders\RoleSeeder;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| El botón «Corregir» — 4-sep-2026
|--------------------------------------------------------------------------
| El dominio tiene los suyos en CorreccionDeReciboTest. Estos son de la
| PANTALLA: «el dominio verde no significa la pantalla viva». Renderizar no
| alcanza — hay que DISPARAR la acción (§9.E9). Ya pasó dos veces que una
| función quedara perfecta en el código e invisible en el navegador.
*/

beforeEach(function (): void {
    actingAsAdmin();

    $proyecto = Proyecto::factory()->create(['codigo' => 'RPS']);
    $bloque = Bloque::factory()->create(['proyecto_id' => $proyecto->getKey(), 'nombre' => 'A']);
    $lote = Lote::factory()->enBloque($bloque)->conMedidas('250.0000', '1400.00')->create(['numero' => '1']);

    $this->cliente = Cliente::factory()->create(['nombre' => 'LETICIA ROMERO']);

    $venta = app(RegistroDeVentas::class)->activar(
        proyecto: $proyecto,
        lotes: [$lote],
        clientes: [$this->cliente],
        prima: new Monto('50000.00'),
        plazoMeses: 12,
        diaPago: 5,
    );

    $this->recibo = app(RegistroDePagos::class)->cobrarCuotas(
        venta: $venta,
        lote: $venta->compromisos()->firstOrFail(),
        cliente: $this->cliente,
        monto: new Monto('25000.00'),
        forma: FormaDePago::Efectivo,
    );

    $this->ficha = fn () => Livewire::test(ViewRecibo::class, ['record' => $this->recibo->getKey()]);

    /*
    | Se siembra el RoleSeeder de verdad y NO un rol inventado con permisos
    | elegidos a mano: un rol a medida no tiene `Corregir:Recibo` por
    | construcción, así que el test pasaría siempre sin verificar la matriz.
    | El día que alguien meta esta acción en el cruce de RECURSOS o en
    | ACCIONES_RECEPTOR «para que sea consistente», esto se pone rojo.
    */
    $this->reparto = function (): void {
        $this->seed(RoleSeeder::class);

        $this->receptor = crearUsuarioConRol(Roles::RECEPTOR, ['name' => 'ELDER MEJIA']);
        $this->administradora = crearUsuarioConRol(Roles::ADMINISTRADORA, ['name' => 'ROSA ELENA']);

        resolve(PermissionRegistrar::class)->forgetCachedPermissions();
    };
});

/*
| A nombre de quién quedó un cobro es justamente lo que quien cobró no
| debería poder cambiar solo.
*/
test('el receptor NO puede corregir, la administradora sí', function (): void {
    ($this->reparto)();

    $this->actingAs($this->receptor);

    expect($this->receptor->can('Corregir:Recibo'))->toBeFalse();
    ($this->ficha)()->assertActionHidden('corregir');

    $this->actingAs($this->administradora);

    expect($this->administradora->can('Corregir:Recibo'))->toBeTrue();
    ($this->ficha)()->assertActionVisible('corregir');
});

/*
| §9.E9: disparar la acción, no solo verla. Es el caso que la pidió — un
| recibo a nombre de quien no estuvo en la caja.
*/
test('la ficha corrige de verdad a nombre de quién quedó el cobro', function (): void {
    ($this->reparto)();
    $this->actingAs($this->administradora);

    ($this->ficha)()
        ->callAction('corregir', [
            'recibido_por' => $this->receptor->getKey(),
            'forma_pago'   => FormaDePago::Efectivo->value,
            'motivo'       => 'El dinero lo recibió don Elder en la caseta',
        ])
        ->assertHasNoActionErrors();

    expect($this->recibo->fresh()?->getAttribute('recibido_por'))->toBe($this->receptor->getKey());
});

/*
| El modal no puede exigir un motivo y después dejar guardar sin él: sería
| pedir la explicación de adorno.
*/
test('sin motivo el modal no deja guardar', function (): void {
    ($this->reparto)();
    $this->actingAs($this->administradora);

    ($this->ficha)()
        ->callAction('corregir', [
            'recibido_por' => $this->receptor->getKey(),
            'forma_pago'   => FormaDePago::Efectivo->value,
            'motivo'       => '',
        ])
        ->assertHasActionErrors(['motivo']);
});

/*
| Sobre un papel muerto no se ofrece: quien atiende lo descubriría recién
| después de escribir el motivo. Lo dice `ReciboPolicy::corregir()`.
*/
test('sobre un recibo anulado el botón no aparece', function (): void {
    app(RegistroDePagos::class)->anular($this->recibo, 'Se cobró de más');

    ($this->reparto)();
    $this->actingAs($this->administradora);

    ($this->ficha)()->assertActionHidden('corregir');
});
