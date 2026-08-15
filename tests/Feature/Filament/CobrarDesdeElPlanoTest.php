<?php

declare(strict_types=1);

use App\Domain\Enums\EstadoVenta;
use App\Domain\Enums\FormaDePago;
use App\Domain\Plano\PlanoDelProyecto;
use App\Domain\Plano\PlanoPublico;
use App\Domain\ValueObjects\Monto;
use App\Domain\Ventas\PrecioPactado;
use App\Domain\Ventas\RegistroDeVentas;
use App\Filament\Resources\Proyectos\Pages\VerPlano;
use App\Filament\Support\CobrarUnPago;
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
| Cobrar DESDE EL PLANO
|--------------------------------------------------------------------------
| Pedido de Mauricio, 13-ago-2026: «cuando ya esté vendido, que aparezca
| para pagar la cuota desde acá, o abonar a capital; así se maneja mejor
| todo desde acá y ya se tiene toda la información del comprador».
|
| El modal es el MISMO de `CobrarUnPago` que ya prueban
| `CobrarDesdeElExpedienteTest` y `CobrarDesdeLaTablaTest`. Lo que se prueba
| acá es lo único distinto y lo único que se puede romper: que la venta se
| resuelva desde el lote, que los números del panel sean los del CONTRATO y
| que el plano público no se entere de nada de esto.
|
| Dos ventas: una de DOS lotes —el caso que obliga al aviso— y otra de uno.
| 250 vr² a L 1,400.00 = L 350,000.00 cada lote.
*/

beforeEach(function (): void {
    actingAsAdmin();

    $this->proyecto = Proyecto::factory()->create(['codigo' => 'RPS', 'slug' => 'praderas', 'plano_publico' => true]);
    $bloque = Bloque::factory()->create(['proyecto_id' => $this->proyecto->getKey(), 'nombre' => 'A']);

    $lote = static fn (Bloque $b, string $numero, int $x): Lote => Lote::factory()
        ->enBloque($b)
        ->conMedidas('250.0000', '1400.00')
        ->create([
            'numero'   => $numero,
            'poligono' => [[$x, 0], [$x + 10, 0], [$x + 10, 25], [$x, 25]],
        ]);

    $this->uno = $lote($bloque, '1', 0);
    $this->dos = $lote($bloque, '2', 20);
    $this->solo = $lote($bloque, '3', 40);
    $this->libre = $lote($bloque, '4', 60);

    $this->cliente = Cliente::factory()->create(['nombre' => 'Leticia Romero']);

    $condicion = static fn (Lote $l): PrecioPactado => new PrecioPactado(
        loteId: (int) $l->getKey(),
        precioVara: new Monto('1400.00'),
        plazoMeses: 12,
        prima: new Monto('50000.00'),
    );

    // El contrato de DOS lotes: el que obliga a avisar antes de cobrar.
    $this->contratoDoble = app(RegistroDeVentas::class)->activar(
        proyecto: $this->proyecto,
        lotes: [$this->uno, $this->dos],
        clientes: [$this->cliente],
        prima: new Monto('100000.00'),
        plazoMeses: 12,
        diaPago: 5,
        precios: [$condicion($this->uno), $condicion($this->dos)],
    );

    // Y el de un solo lote, que es el caso normal.
    $this->contratoSimple = app(RegistroDeVentas::class)->activar(
        proyecto: $this->proyecto,
        lotes: [$this->solo],
        clientes: [$this->cliente],
        prima: new Monto('50000.00'),
        plazoMeses: 12,
        diaPago: 5,
        precios: [$condicion($this->solo)],
    );

    $this->delPlano = function (Lote $l): array {
        foreach (new PlanoDelProyecto()->para($this->proyecto)['lotes'] as $fila) {
            if ($fila['id'] === $l->getKey()) {
                return $fila;
            }
        }

        throw new RuntimeException('El lote no salio dibujado en el plano.');
    };
});

describe('Lo que el panel del lote vendido sabe', function (): void {
    test('un lote vendido trae la cartera de su contrato', function (): void {
        $cartera = ($this->delPlano)($this->uno)['cartera'];

        expect($cartera)->not->toBeNull()
            ->and($cartera['venta'])->toBe($this->contratoDoble->getKey())
            ->and($cartera['lotes'])->toBe(2)
            ->and($cartera['seCobra'])->toBeTrue()
            // Dos lotes de L 350,000.00 menos L 100,000.00 de prima.
            ->and($cartera['saldo'])->toBe('L. 600,000.00')
            ->and($cartera['proximaCuota'])->toBeString();
    });

    /*
    | El número es del CONTRATO y por eso los dos lotes del mismo contrato
    | dicen lo mismo. Es la razón de que el panel lo escriba con esas
    | palabras: el recibo también es del contrato.
    */
    test('los dos lotes de un contrato muestran el mismo saldo', function (): void {
        expect(($this->delPlano)($this->dos)['cartera']['saldo'])
            ->toBe(($this->delPlano)($this->uno)['cartera']['saldo']);
    });

    test('un contrato de un solo lote no tiene nada que aclarar', function (): void {
        $cartera = ($this->delPlano)($this->solo)['cartera'];

        expect($cartera['lotes'])->toBe(1)
            ->and($cartera['saldo'])->toBe('L. 300,000.00');
    });

    test('un lote disponible no trae cartera', function (): void {
        expect(($this->delPlano)($this->libre)['cartera'])->toBeNull();
    });

    /*
    | Un expediente liquidado, rescindido o anulado no recibe dinero. El
    | panel deja de ofrecer el botón antes de que alguien lo apriete.
    */
    test('una venta que ya no está vigente no ofrece cobrar', function (): void {
        // `cerrada_el` no es opcional: el CHECK `ventas_cierre_segun_estado_chk`
        // no admite un expediente cerrado sin la fecha en que se cerro.
        $this->contratoSimple->update(['estado' => EstadoVenta::Anulada, 'cerrada_el' => today()]);

        expect(($this->delPlano)($this->solo)['cartera']['seCobra'])->toBeFalse();
    });
});

describe('Cobrar desde el plano', function (): void {
    test('el pago queda registrado con su recibo', function (): void {
        $compromiso = $this->contratoSimple->compromisos()->firstOrFail();

        Livewire::test(VerPlano::class, ['record' => $this->proyecto->getKey()])
            ->callAction(
                'cobrarDesdeElPlano',
                [
                    'cobrar_'.$compromiso->getKey() => true,
                    'monto_'.$compromiso->getKey()  => '25000.00',
                    'forma_pago'                    => FormaDePago::Efectivo->value,
                    'fecha'                         => today()->toDateString(),
                ],
                ['lote' => $this->solo->getKey()],
            )
            ->assertHasNoActionErrors();

        /*
         * El ULTIMO de ese contrato, no el primero de la tabla: activar una
         * venta ya emite el recibo de la prima, asi que un `firstOrFail()`
         * pelado agarra ese —y encima el de la otra venta— en vez del cobro
         * que se acaba de hacer.
         */
        $recibo = Recibo::query()
            ->where('venta_id', '=', $this->contratoSimple->getKey())
            ->latest('id')
            ->firstOrFail();

        expect($recibo->montoTotal()->formateado())->toBe('L. 25,000.00');
    });

    /*
    | El saldo del panel baja solo, porque sale de las cuotas y no de una
    | columna que alguien tenga que acordarse de actualizar.
    */
    test('el saldo del panel baja después de cobrar', function (): void {
        $compromiso = $this->contratoSimple->compromisos()->firstOrFail();

        Livewire::test(VerPlano::class, ['record' => $this->proyecto->getKey()])
            ->callAction(
                'cobrarDesdeElPlano',
                [
                    'cobrar_'.$compromiso->getKey() => true,
                    'monto_'.$compromiso->getKey()  => '25000.00',
                    'forma_pago'                    => FormaDePago::Efectivo->value,
                    'fecha'                         => today()->toDateString(),
                ],
                ['lote' => $this->solo->getKey()],
            )
            ->assertHasNoActionErrors();

        expect(($this->delPlano)($this->solo)['cartera']['saldo'])->toBe('L. 275,000.00');
    });

    /*
    | 🔴 R21: el receptor cobra, la administradora reprograma. Que el botón
    | salga en otra pantalla no cambia quién puede apretarlo.
    */
    test('el receptor cobra desde el plano pero no abona a capital', function (): void {
        $receptor = rol('receptor_del_plano');
        $receptor->syncPermissions(['ViewAny:Venta', 'View:Venta', 'Create:Recibo', 'ViewAny:Proyecto', 'View:Proyecto']);

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($receptor);

        resolve(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($user);

        expect(CobrarUnPago::seLePermiteAbonar())->toBeFalse();
    });
});

/*
|--------------------------------------------------------------------------
| Y la vidriera sigue sin enterarse
|--------------------------------------------------------------------------
| La lista blanca de `PlanoPublico` ya deja afuera `cliente` y `valor`. La
| cartera es lo mismo o peor: cuánto debe el vecino, cuántas cuotas lleva
| vencidas y cuándo le toca la próxima.
*/
describe('El plano público no ve la cartera', function (): void {
    test('ni la clave ni los números salen a la calle', function (): void {
        $publico = resolve(PlanoPublico::class)->para($this->proyecto);

        foreach ($publico['lotes'] as $lote) {
            expect($lote)->not->toHaveKey('cartera')
                ->and($lote)->not->toHaveKey('cliente')
                ->and($lote)->not->toHaveKey('valor');
        }
    });

    test('la página no publica el saldo de nadie', function (): void {
        $this->get(route('plano.publico', ['slug' => 'praderas']))
            ->assertOk()
            ->assertDontSee('600,000.00')
            ->assertDontSee('Saldo del contrato')
            ->assertDontSee('LETICIA ROMERO');
    });
});
