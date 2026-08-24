<?php

declare(strict_types=1);

use App\Domain\Enums\FormaDePago;
use App\Domain\Enums\ModalidadDeReprogramacion;
use App\Domain\Pagos\RegistroDePagos;
use App\Domain\ValueObjects\Monto;
use App\Domain\Ventas\RegistroDeVentas;
use App\Models\Bloque;
use App\Models\Cliente;
use App\Models\Cuota;
use App\Models\ImpresionDeRecibo;
use App\Models\Lote;
use App\Models\Proyecto;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| El papel — módulo h) del contrato
|--------------------------------------------------------------------------
| Un lote de 250 vr² a L 1,400.00 son L 350,000.00; con L 50,000.00 de prima
| quedan L 300,000.00 a 12 meses, o sea cuotas de L 25,000.00 exactas.
|
| Esta ruta vive FUERA del panel, así que no hereda el `Authenticate` de
| Filament que verifica `canAccessPanel()`. Las dos condiciones —cuenta activa
| y `View:Recibo`— están escritas en el controlador, y por eso las prueba cada
| una: es el único lugar del sistema donde la autorización no la pone Filament.
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

    $this->recibo = app(RegistroDePagos::class)->cobrarCuotas(
        venta: $this->venta,
        lote: $this->renglon,
        cliente: $this->cliente,
        monto: new Monto('60000.00'),
        forma: FormaDePago::Efectivo,
    );

    $this->papel = fn () => $this->get(route('documentos.recibo', $this->recibo));
});

/*
| El nombre se compara contra lo que la BASE guardó, no contra lo que se
| tecleó: `clientes.nombre` pasa por un mutador que lo lleva a MAYÚSCULAS
| (decisión del 3-ago-2026, docs/mayusculas.md). Escribir 'Leticia Romero' acá
| a mano hace fallar el test por una regla que está bien.
*/
test('el recibo sale con su número, su cliente y su detalle', function (): void {
    ($this->papel)()
        ->assertOk()
        ->assertSee($this->recibo->folio())
        ->assertSee((string) $this->cliente->getAttribute('nombre'))
        ->assertSee((string) $this->venta->getAttribute('numero_contrato'))
        // 25,000 + 25,000 + 10,000 = 60,000: tres renglones, un solo papel.
        ->assertSee('Cuota 1')
        ->assertSee('Cuota 3')
        ->assertSee('L. 60,000.00');
});

/*
| A un número se le agrega un cero con un trazo; a la cantidad en letras, no.
| Por eso van las dos, y por eso esto se prueba.
*/
test('lleva la cantidad en letras', function (): void {
    ($this->papel)()->assertSee('SESENTA MIL LEMPIRAS CON 00/100');
});

/*
| La Clausula Segunda, modulo g-i, pide el recibo interno «con NO VALIDO
| PARA CREDITO FISCAL». Son palabras del contrato, no una parafrasis: hasta
| el 6-ago el papel decia «No es comprobante fiscal», que significa lo mismo
| pero no es lo que se firmo.
|
| R10 sigue detras: no se usa CAI, asi que este papel nunca va a ser un
| comprobante fiscal y por eso lo dice.
*/
test('lleva la leyenda fiscal con las palabras del contrato (g-i, R10)', function (): void {
    ($this->papel)()
        ->assertSee('NO VÁLIDO PARA CRÉDITO FISCAL', escape: false)
        ->assertSee('Documento de uso interno.');
});

describe('El original y las copias', function (): void {
    test('la primera vez sale limpio', function (): void {
        ($this->papel)()
            ->assertOk()
            ->assertDontSee('COPIA');

        expect(ImpresionDeRecibo::query()->count())->toBe(1);
    });

    /*
    | Dos papeles con el mismo número no pueden hacerse pasar por dos cobros,
    | que es exactamente lo que un correlativo viene a evitar.
    */
    test('de la segunda en adelante dice COPIA', function (): void {
        ($this->papel)();

        ($this->papel)()
            ->assertOk()
            ->assertSee('COPIA')
            ->assertSee('2.ª impresión');

        expect(ImpresionDeRecibo::query()->count())->toBe(2);
    });
});

/*
| R21: un recibo puede poner al día lo vencido Y bajar el capital con el
| sobrante. Los dos renglones tienen que verse, o el cliente no entiende por
| qué pagó L 100,000.00 y sus cuotas solo bajaron L 60,000.00.
|
| ⚠️ Desde el 24-ago-2026 ese papel sale por «Ambas» y no por el abono a secas:
| «que no pueda hacer abono a capital si tiene cuotas pendientes okey». Con el
| lote atrasado `abonarACapital()` lo rechaza, y lo que hace las dos cosas en un
| solo recibo es `cobrarYAbonar()`.
*/
test('el recibo de un abono imprime sus dos renglones', function (): void {
    Cuota::query()
        ->where('compromiso_id', $this->renglon->getKey())
        ->whereIn('numero', [3, 4])
        ->update(['fecha_vencimiento' => today()->subMonths(2)->toDateString()]);

    // Vencido: lo que faltaba de la 3 (15,000) y la 4 entera (25,000) = 40,000.
    // Los otros 60,000 bajan el capital.
    $recibos = app(RegistroDePagos::class)->cobrarYAbonar(
        venta: $this->venta,
        cliente: $this->cliente,
        cuotas: [['lote' => $this->renglon, 'monto' => new Monto('40000.00')]],
        loteDelAbono: $this->renglon,
        aCapital: new Monto('60000.00'),
        modalidad: ModalidadDeReprogramacion::AcortarPlazo,
        motivo: 'El cliente quiere terminar antes',
        forma: FormaDePago::Efectivo,
    );

    $this->get(route('documentos.recibo', $recibos[0]))
        ->assertOk()
        ->assertSee('Abono a capital')
        ->assertSee('L. 60,000.00')
        ->assertSee('L. 100,000.00');
});

describe('Quién puede sacar el papel', function (): void {
    test('quien no puede ver recibos recibe un 403', function (): void {
        $sinPermiso = rol('sin_recibos');
        $sinPermiso->syncPermissions(['ViewAny:Venta', 'View:Venta']);

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($sinPermiso);

        resolve(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($user);

        ($this->papel)()->assertForbidden();

        expect(ImpresionDeRecibo::query()->count())->toBe(0);
    });

    /*
    | Esta ruta no pasa por el `Authenticate` de Filament, así que la cuenta
    | dada de baja se verifica acá. Un usuario desactivado que conserve su
    | sesión no imprime documentos con datos de clientes.
    */
    test('una cuenta dada de baja no imprime, aunque tenga el permiso', function (): void {
        $receptor = rol('receptor_de_baja');
        $receptor->syncPermissions(['ViewAny:Recibo', 'View:Recibo']);

        $user = User::factory()->create(['is_active' => false]);
        $user->assignRole($receptor);

        resolve(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($user);

        ($this->papel)()->assertForbidden();

        expect(ImpresionDeRecibo::query()->count())->toBe(0);
    });

    /*
    | Sin sesión no hay documento — pero tampoco una pared. La sesión se vence
    | mientras alguien cobra, y ahí lo útil es mandarlo al panel a
    | identificarse, no a un 403.
    |
    | Este test falló la primera vez y fue el más valioso de los tres: con el
    | middleware `auth` puesto, el invitado terminaba en una pantalla de error
    | 500 que mostraba la consulta CON EL NOMBRE DEL CLIENTE adentro. O sea,
    | justo el dato que se estaba protegiendo.
    */
    test('un invitado va al panel a identificarse, sin ver el papel', function (): void {
        Auth::logout();

        $respuesta = ($this->papel)();

        $respuesta
            ->assertRedirect('/')
            ->assertDontSee((string) $this->cliente->getAttribute('nombre'));

        expect(ImpresionDeRecibo::query()->count())->toBe(0);
    });
});
