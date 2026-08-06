<?php

declare(strict_types=1);

use App\Domain\Enums\FormaDePago;
use App\Domain\Pagos\RegistroDePagos;
use App\Domain\ValueObjects\Monto;
use App\Domain\Ventas\RegistroDeVentas;
use App\Filament\Resources\Ventas\Pages\ViewVenta;
use App\Models\Bloque;
use App\Models\Cliente;
use App\Models\Cuota;
use App\Models\Lote;
use App\Models\Proyecto;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| El papel del estado de cuenta
|--------------------------------------------------------------------------
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

    $this->papel = fn () => $this->get(route('documentos.estado-de-cuenta', $this->venta));
});

test('sale con el contrato, el titular y el saldo', function (): void {
    ($this->papel)()
        ->assertOk()
        ->assertSee((string) $this->venta->getAttribute('numero_contrato'))
        // El nombre se compara contra lo que la BASE guardó: pasa por un
        // mutador que lo lleva a MAYÚSCULAS (docs/mayusculas.md).
        ->assertSee((string) $this->cliente->getAttribute('nombre'))
        ->assertSee('Estado de cuenta')
        ->assertSee('L. 300,000.00');
});

/*
| La fecha de corte no es decorativa: el mismo expediente impreso mañana dice
| otra cosa, y sin la fecha al lado parecería que el documento cambió solo.
*/
test('lleva la fecha de corte', function (): void {
    ($this->papel)()->assertSee('Al '.today()->format('d/m/Y'));
});

test('imprime la escalera completa, cuota por cuota', function (): void {
    ($this->papel)()
        ->assertOk()
        ->assertSee('Pendiente')
        // Las doce cuotas, no solo las pendientes.
        ->assertSeeInOrder(['Vence', 'Falta', 'Pendiente']);
});

test('con pagos encima muestra lo pagado y lo que falta', function (): void {
    app(RegistroDePagos::class)->cobrarCuotas(
        venta: $this->venta,
        lote: $this->renglon,
        cliente: $this->cliente,
        monto: new Monto('60000.00'),
        forma: FormaDePago::Efectivo,
    );

    ($this->papel)()
        ->assertOk()
        ->assertSee('Pagada')
        // 300,000 − 60,000 = 240,000
        ->assertSee('L. 240,000.00')
        // La prima entra en el total pagado: 50,000 + 60,000
        ->assertSee('L. 110,000.00');
});

/*
| R2: se muestran los días de atraso porque la administración los necesita,
| pero el papel tiene que decir con todas las letras que no cuestan.
*/
test('avisa del atraso y aclara que no genera recargo', function (): void {
    Cuota::query()
        ->where('compromiso_id', $this->renglon->getKey())
        ->whereIn('numero', [1, 2])
        ->update(['fecha_vencimiento' => today()->subMonths(2)->toDateString()]);

    ($this->papel)()
        ->assertOk()
        ->assertSee('2 cuotas vencidas')
        ->assertSee('L. 50,000.00')
        ->assertSee('no genera ningún recargo', escape: false);
});

test('un contrato al día lo dice, con su próximo pago', function (): void {
    ($this->papel)()
        ->assertOk()
        ->assertSee('Al día', escape: false)
        ->assertSee('L. 25,000.00');
});

test('dice que no es comprobante fiscal (R10)', function (): void {
    ($this->papel)()->assertSee('No es comprobante fiscal');
});

describe('Quién puede sacarlo', function (): void {
    /*
    | El receptor lo necesita: el cliente llega al mostrador y lo pide, y
    | hacerlo esperar a la administradora para ver su propio saldo no tiene
    | sentido. `View:Venta` ya se lo da el RoleSeeder.
    */
    test('el receptor puede', function (): void {
        $receptor = rol('receptor_de_prueba');
        $receptor->syncPermissions(['ViewAny:Venta', 'View:Venta']);

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($receptor);

        resolve(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($user);

        ($this->papel)()->assertOk();
    });

    test('quien no puede ver el expediente recibe un 403', function (): void {
        $ajeno = rol('sin_ventas');
        $ajeno->syncPermissions(['ViewAny:Recibo']);

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($ajeno);

        resolve(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($user);

        ($this->papel)()->assertForbidden();
    });

    /*
    | La misma trampa que el recibo: esta ruta vive fuera del panel y la cuenta
    | activa la cuida `UsuarioActivoDelPanel`, no Filament.
    */
    test('una cuenta dada de baja no lo saca', function (): void {
        $user = User::factory()->create(['is_active' => false]);
        $user->assignRole(rol('receptor_de_baja'));

        resolve(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($user);

        ($this->papel)()->assertForbidden();
    });

    test('un invitado va al panel a identificarse', function (): void {
        Auth::logout();

        ($this->papel)()
            ->assertRedirect('/')
            ->assertDontSee((string) $this->cliente->getAttribute('nombre'));
    });
});

/*
| §9.E9: el dominio verde no significa la pantalla viva. El botón tiene que
| existir y apuntar a donde se cree que apunta.
*/
test('el botón está en el expediente y lleva al papel', function (): void {
    Livewire::test(ViewVenta::class, ['record' => $this->venta->getKey()])
        ->assertActionVisible('estado_de_cuenta')
        ->assertActionHasUrl('estado_de_cuenta', route('documentos.estado-de-cuenta', $this->venta));
});
