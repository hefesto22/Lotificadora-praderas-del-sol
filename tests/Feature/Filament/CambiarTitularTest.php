<?php

declare(strict_types=1);

use App\Domain\Enums\EstadoVenta;
use App\Domain\ValueObjects\Monto;
use App\Domain\Ventas\RegistroDeVentas;
use App\Filament\Resources\Ventas\Pages\ViewVenta;
use App\Models\Bloque;
use App\Models\Cliente;
use App\Models\Lote;
use App\Models\Proyecto;
use App\Models\Recibo;
use App\Support\Roles;
use Database\Seeders\RoleSeeder;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| Cambiar el titular del expediente — cesión de derechos, 22-ago-2026
|--------------------------------------------------------------------------
| El dominio tiene los suyos en CambioDeTitularTest. Estos son de la
| PANTALLA: «el dominio verde no significa la pantalla viva». Renderizar no
| alcanza — hay que DISPARAR la acción (§9.E9).
|
| Un lote de 250 vr² a L 1,400.00 son L 350,000.00; con L 50,000.00 de prima
| quedan cuotas de L 25,000.00 a 12 meses.
*/

beforeEach(function (): void {
    actingAsAdmin();

    $proyecto = Proyecto::factory()->create(['codigo' => 'RPS']);
    $bloque = Bloque::factory()->create(['proyecto_id' => $proyecto->getKey(), 'nombre' => 'A']);
    $lote = Lote::factory()->enBloque($bloque)->conMedidas('250.0000', '1400.00')->create(['numero' => '1']);

    $this->juan = Cliente::factory()->create(['nombre' => 'JUAN PEREZ']);
    $this->rosa = Cliente::factory()->create(['nombre' => 'ROSA ELENA MEJIA']);

    $this->venta = app(RegistroDeVentas::class)->activar(
        proyecto: $proyecto,
        lotes: [$lote],
        clientes: [$this->juan],
        prima: new Monto('50000.00'),
        plazoMeses: 12,
        diaPago: 5,
    );

    $this->expediente = fn (): object => Livewire::test(
        ViewVenta::class,
        ['record' => $this->venta->getKey()],
    );
});

test('la administradora cambia el titular desde el expediente', function (): void {
    ($this->expediente)()
        ->callAction('cambiar_titular', ['cliente_id' => $this->rosa->getKey()])
        ->assertHasNoActionErrors();

    expect($this->venta->refresh()->titular()?->getKey())->toBe($this->rosa->getKey())
        ->and($this->venta->titularesAnteriores()->pluck('id')->all())->toBe([$this->juan->getKey()]);
});

test('🔴 el recibo de la prima sigue a nombre de quien pagó', function (): void {
    /*
    | `activar()` YA emitió el recibo de la prima. Se busca acotado y por el
    | último a propósito: un `firstOrFail()` pelado agarra cualquiera.
    */
    $prima = Recibo::query()
        ->where('venta_id', '=', $this->venta->getKey())
        ->latest('id')
        ->firstOrFail();

    ($this->expediente)()
        ->callAction('cambiar_titular', ['cliente_id' => $this->rosa->getKey()])
        ->assertHasNoActionErrors();

    expect($prima->refresh()->getAttribute('cliente_id'))->toBe($this->juan->getKey())
        ->and($prima->nombreDelPapel())->toBe('JUAN PEREZ');
});

test('el lote del contrato pasa a nombre del titular nuevo', function (): void {
    ($this->expediente)()
        ->callAction('cambiar_titular', ['cliente_id' => $this->rosa->getKey()])
        ->assertHasNoActionErrors();

    /*
    | `compromisos.cliente_id` es lo que rotula el plano
    | (`PlanoDelProyecto::lotes()`). Sin esto el mapa —donde más se pregunta
    | «¿de quién es este lote?»— seguiría diciendo el nombre viejo.
    */
    expect($this->venta->compromisos()->firstOrFail()->getAttribute('cliente_id'))
        ->toBe($this->rosa->getKey());
});

/*
| §9.E3 y la misma frontera que R21: el receptor cobra todo el día —tiene
| `Create:Recibo`— pero no cede un contrato.
|
| Se siembra el RoleSeeder de verdad y NO un rol inventado con permisos
| elegidos a mano: un rol a medida no tiene `CambiarTitular:Venta` por
| construcción, así que el test pasaría siempre y no verificaría la matriz.
| Así, el día que alguien meta esta acción en el cruce de RECURSOS o en
| ACCIONES_RECEPTOR «para que sea consistente», esta línea se pone roja.
*/
test('el receptor NO puede cambiar el titular, la administradora sí', function (): void {
    $this->seed(RoleSeeder::class);

    $receptor = crearUsuarioConRol(Roles::RECEPTOR);
    $administradora = crearUsuarioConRol(Roles::ADMINISTRADORA);

    resolve(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->actingAs($receptor);

    expect($receptor->can('CambiarTitular:Venta'))->toBeFalse();
    ($this->expediente)()->assertActionHidden('cambiar_titular');

    $this->actingAs($administradora);

    expect($administradora->can('CambiarTitular:Venta'))->toBeTrue();
    ($this->expediente)()->assertActionVisible('cambiar_titular');
});

/*
| Registrar el cambio y no mostrarlo en ningún lado sería tener la respuesta
| guardada donde nadie la puede leer. La pregunta —«¿este expediente no era
| de fulano?»— se hace en ventanilla, no en Registros de actividad.
*/
test('el expediente queda diciendo de quién era antes, y desde cuándo', function (): void {
    $pagina = ($this->expediente)();

    $pagina->assertDontSeeText('Fue de');

    /*
    | 🔴 Se encadena sobre la MISMA instancia, no sobre una pagina recien
    | montada. Con `($this->expediente)()` de nuevo, el test lee un
    | componente nuevo y nunca ejerce el `$this->getRecord()->refresh()` de
    | la accion: si alguien lo borra, esto sigue verde y quien atiende se
    | queda mirando el nombre viejo hasta recargar a mano.
    |
    | La fecha es la mitad de lo que se pidio: sin el pivot con casts sale
    | el nombre pelado y nadie lo nota.
    */
    $pagina
        ->callAction('cambiar_titular', ['cliente_id' => $this->rosa->getKey()])
        ->assertHasNoActionErrors()
        ->assertSeeText('Fue de')
        ->assertSeeText('JUAN PEREZ')
        ->assertSeeText('hasta el '.today()->format('d/m/Y'))
        ->assertSeeText('ROSA ELENA MEJIA');
});

test('el titular nuevo sale primero, aunque haya entrado último', function (): void {
    $maria = Cliente::factory()->create(['nombre' => 'MARIA LOPEZ']);
    $this->venta->clientes()->attach($maria->getKey(), ['titular' => false, 'orden' => 2]);

    ($this->expediente)()
        ->callAction('cambiar_titular', ['cliente_id' => $this->rosa->getKey()])
        ->assertHasNoActionErrors();

    /*
    | La lista viene ordenada por `orden` —la posición en el contrato— y
    | quien entra por una cesión se sienta al final. Sin el sortByDesc del
    | infolist, un rótulo que promete «Titular y copropietarios» mostraría
    | «MARIA LOPEZ · ROSA ELENA MEJIA (titular)».
    */
    ($this->expediente)()->assertSeeTextInOrder(['ROSA ELENA MEJIA (titular)', 'MARIA LOPEZ']);
});

test('un expediente cerrado no ofrece cambiar el titular', function (): void {
    $this->venta->update(['estado' => EstadoVenta::Rescindida, 'cerrada_el' => today()]);

    ($this->expediente)()->assertActionHidden('cambiar_titular');
});
