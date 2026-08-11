<?php

declare(strict_types=1);

use App\Domain\Enums\ConceptoDeRecibo;
use App\Domain\Enums\EstadoVenta;
use App\Domain\Enums\FormaDePago;
use App\Domain\Enums\ModalidadDeReprogramacion;
use App\Domain\Pagos\RegistroDePagos;
use App\Domain\ValueObjects\Monto;
use App\Domain\Ventas\RegistroDeVentas;
use App\Filament\Resources\Ventas\Pages\ViewVenta;
use App\Filament\Resources\Ventas\RelationManagers\ReprogramacionesRelationManager;
use App\Models\Bloque;
use App\Models\Cliente;
use App\Models\Cuota;
use App\Models\Lote;
use App\Models\Proyecto;
use App\Models\Recibo;
use App\Models\Reprogramacion;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| Abonar a capital desde el expediente — R21
|--------------------------------------------------------------------------
| El dominio ya tiene los suyos. Estos son de la PANTALLA, que es otra cosa:
| «el dominio verde no significa la pantalla viva». Renderizar no alcanza —
| hay que DISPARAR la acción (§9.E9).
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

    $this->expediente = fn (): object => Livewire::test(
        ViewVenta::class,
        ['record' => $this->venta->getKey()],
    );

    /*
     * ⚠️ Los campos cambiaron el 10-ago-2026 y es un cambio DELIBERADO: el
     * abono pasó de un `Select` de un lote a un renglón por lote —casilla,
     * monto y modalidad—, porque ahora se puede repartir entre varios.
     *
     * Las assertions de este archivo NO se tocaron: lo que se prueba sigue
     * siendo lo mismo, y por eso siguen sirviendo de red. Lo único que se movió
     * es cómo se llenan los campos.
     */
    $this->datos = function (array $extra = []): array {
        $id = $this->renglon->getKey();

        return array_merge([
            "abonar_{$id}"    => true,
            "abono_{$id}"     => '75000.00',
            "modalidad_{$id}" => ModalidadDeReprogramacion::AcortarPlazo->value,
            'forma_pago'      => FormaDePago::Efectivo->value,
            'fecha'           => today()->toDateString(),
            'motivo'          => 'El cliente quiere terminar antes',
        ], $extra);
    };
});

test('el abono entra por la pantalla y reescribe el plan', function (): void {
    ($this->expediente)()
        ->callAction('abonar_a_capital', ($this->datos)())
        ->assertHasNoActionErrors();

    // 300,000 − 75,000 = 225,000, que en cuotas de 25,000 son 9 exactas.
    expect(Cuota::query()->where('compromiso_id', $this->renglon->getKey())->count())->toBe(9)
        ->and($this->venta->refresh()->saldoPendiente())->toBeMonto('225000.00')
        ->and(Recibo::query()->where('concepto', '!=', ConceptoDeRecibo::Prima)->count())->toBe(1)
        ->and(Reprogramacion::query()->count())->toBe(1);
});

/*
| Los dos caminos los elige el cliente, así que el formulario tiene que poder
| mandar el otro y que llegue hasta la base. Es el campo que más fácil se
| pierde entre la pantalla y el Service.
*/
test('la modalidad que elige el cliente llega hasta el plan', function (): void {
    ($this->expediente)()
        ->callAction('abonar_a_capital', ($this->datos)([
            'abono_'.$this->renglon->getKey()     => '60000.00',
            'modalidad_'.$this->renglon->getKey() => ModalidadDeReprogramacion::BajarCuota->value,
        ]))
        ->assertHasNoActionErrors();

    $cuotas = Cuota::query()
        ->where('compromiso_id', $this->renglon->getKey())
        ->orderBy('numero')
        ->pluck('monto')
        ->all();

    // 240,000 entre los mismos 12 meses = 20,000 exactos.
    expect($cuotas)->toHaveCount(12)
        ->and($cuotas[0])->toBe('20000.00')
        ->and(Reprogramacion::query()->firstOrFail()->getAttribute('modalidad'))
        ->toBe(ModalidadDeReprogramacion::BajarCuota);
});

/*
| R21 pide el motivo. La base lo exige con un CHECK y el Service con una
| excepción; esto prueba que la pantalla no deja llegar hasta allá con las
| manos vacías.
*/
test('sin motivo no pasa del formulario', function (): void {
    ($this->expediente)()
        ->callAction('abonar_a_capital', ($this->datos)(['motivo' => '']))
        ->assertHasActionErrors(['motivo']);

    expect(Recibo::query()->where('concepto', '!=', ConceptoDeRecibo::Prima)->count())->toBe(0)
        ->and(Reprogramacion::query()->count())->toBe(0);
});

/*
| El error del dominio se muestra como notificación y el formulario queda como
| estaba. Lo que NO puede pasar es una pantalla de error 500 con el cliente
| enfrente.
*/
test('un abono que supera el saldo no rompe la pantalla', function (): void {
    ($this->expediente)()
        ->callAction('abonar_a_capital', ($this->datos)(['abono_'.$this->renglon->getKey() => '999999.00']))
        ->assertHasNoActionErrors();

    expect(Recibo::query()->where('concepto', '!=', ConceptoDeRecibo::Prima)->count())->toBe(0)
        ->and(Reprogramacion::query()->count())->toBe(0)
        ->and($this->venta->refresh()->saldoPendiente())->toBeMonto('300000.00');
});

/*
| El caso del 6-ago: no alcanza ni para lo vencido. Se registra igual —el
| dinero ya está sobre el mostrador— pero no se reescribe ningún plan.
*/
test('si no alcanza para lo vencido se registra como pago normal', function (): void {
    Cuota::query()
        ->where('compromiso_id', $this->renglon->getKey())
        ->whereIn('numero', [1, 2, 3])
        ->update(['fecha_vencimiento' => today()->subMonths(2)->toDateString()]);

    ($this->expediente)()
        ->callAction('abonar_a_capital', ($this->datos)(['abono_'.$this->renglon->getKey() => '50000.00']))
        ->assertHasNoActionErrors();

    expect(Recibo::query()->where('concepto', '!=', ConceptoDeRecibo::Prima)->count())->toBe(1)
        ->and(Reprogramacion::query()->count())->toBe(0)
        ->and(Cuota::query()->where('compromiso_id', $this->renglon->getKey())->count())->toBe(12);
});

describe('Quién ve el botón', function (): void {
    test('la administradora lo ve', function (): void {
        ($this->expediente)()->assertActionVisible('abonar_a_capital');
    });

    /*
    | §9.E3 en la práctica, y la frontera que R21 dibuja: el receptor cobra
    | —tiene `Create:Recibo` y ve el botón de pagar— pero NO reescribe un plan
    | de cuotas. Los dos botones emiten un recibo; solo uno cambia el contrato.
    */
    test('el receptor cobra pero no abona a capital', function (): void {
        $receptor = rol('receptor_de_prueba');
        $receptor->syncPermissions(['ViewAny:Venta', 'View:Venta', 'Create:Recibo']);

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($receptor);

        resolve(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($user);

        ($this->expediente)()
            ->assertActionVisible('cobrar')
            ->assertActionHidden('abonar_a_capital');
    });

    /*
    | Un expediente cerrado no recibe dinero. El Service lo rechaza igual, pero
    | ofrecer el botón sería invitar a un movimiento que no se puede hacer.
    */
    test('un expediente rescindido no lo muestra', function (): void {
        $this->venta->update([
            'estado'     => EstadoVenta::Rescindida,
            'cerrada_el' => today(),
        ]);

        ($this->expediente)()->assertActionHidden('abonar_a_capital');
    });
});

/*
| Registrar la reprogramación y no mostrarla en ningún lado sería tener la
| respuesta guardada donde nadie la puede leer.
*/
test('la constancia queda a la vista en el expediente', function (): void {
    app(RegistroDePagos::class)->abonarACapital(
        venta: $this->venta,
        lote: $this->renglon,
        cliente: $this->cliente,
        monto: new Monto('75000.00'),
        modalidad: ModalidadDeReprogramacion::AcortarPlazo,
        motivo: 'El cliente quiere terminar antes',
        forma: FormaDePago::Efectivo,
    );

    Livewire::test(ReprogramacionesRelationManager::class, [
        'ownerRecord' => $this->venta,
        'pageClass'   => ViewVenta::class,
    ])
        ->assertSuccessful()
        ->assertCanSeeTableRecords(Reprogramacion::query()->get())
        ->assertSee('El cliente quiere terminar antes');
});
