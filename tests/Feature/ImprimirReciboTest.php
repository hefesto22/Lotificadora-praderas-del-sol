<?php

declare(strict_types=1);

use App\Domain\Enums\ConceptoDeRecibo;
use App\Domain\Enums\FormaDePago;
use App\Domain\Enums\ModalidadDeReprogramacion;
use App\Domain\Pagos\RegistroDePagos;
use App\Domain\ValueObjects\Monto;
use App\Domain\Ventas\RegistroDeCompromisos;
use App\Domain\Ventas\RegistroDeVentas;
use App\Models\Bloque;
use App\Models\Cliente;
use App\Models\Cuota;
use App\Models\ImpresionDeRecibo;
use App\Models\Lote;
use App\Models\Proyecto;
use App\Models\Recibo;
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
    // Con nombre: desde el 31-ago el papel dice quién recibió el dinero, y
    // quien teclea es quien recibe mientras nadie diga otra cosa.
    $this->usuario = actingAsAdmin();

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
|--------------------------------------------------------------------------
| 🔴 Cuánto le resta de pagar — 27-ago-2026
|--------------------------------------------------------------------------
| «Que diga cuánto le queda de x lote o lotes que él tiene, ya que les gusta
| saber cuánto les resta de pagar» — Mauricio.
|
| La línea del saldo YA existía, pero salía de `$recibo->compromiso`, que en un
| cobro de varios lotes queda vacío a propósito (R13). O sea: **el papel que
| más necesita el desglose era el único que no lo imprimía.**
*/

test('el papel dice cuánto le queda por pagar de su lote', function (): void {
    // 300,000 a financiar − 60,000 cobrados = 240,000.
    ($this->papel)()
        ->assertOk()
        ->assertSee('Le queda por pagar')
        ->assertSee((string) $this->renglon->lote?->getAttribute('codigo'))
        ->assertSee('L. 240,000.00')
        // Con un solo lote el total repetiría el mismo número con otro nombre.
        ->assertDontSee('total L.');
});

test('con dos lotes en un mismo papel sale el saldo de cada uno y el total', function (): void {
    $proyecto = Proyecto::factory()->create(['codigo' => 'RSB']);
    $bloque = Bloque::factory()->create(['proyecto_id' => $proyecto->getKey(), 'nombre' => 'B']);

    /*
     * ⚠️ Un array LITERAL, no `collect()->map()->all()`: ese devuelve
     * `array<int, Lote>` y `activar()` pide una `list<Lote>`. PHPStan no puede
     * probar que las claves sean 0..n, y tiene razón. Es el molde de
     * `una-forma-declarada-se-pierde`, y el tipo se repone en el ORIGEN.
     */
    $lotes = [
        Lote::factory()->enBloque($bloque)->conMedidas('250.0000', '1400.00')->create(['numero' => '1']),
        Lote::factory()->enBloque($bloque)->conMedidas('250.0000', '1400.00')->create(['numero' => '2']),
    ];

    // Dos lotes de 350,000 con 50,000 de prima cada uno: 300,000 a financiar
    // por lote, cuotas de 25,000.
    $venta = app(RegistroDeVentas::class)->activar(
        proyecto: $proyecto,
        lotes: $lotes,
        clientes: [$this->cliente],
        prima: new Monto('100000.00'),
        plazoMeses: 12,
        diaPago: 5,
    );

    // Por la misma razón que los lotes: `$renglones[] =` sobre un array vacío
    // sí produce una `list`; `->map()->all()` no.
    $renglones = [];

    foreach ($venta->compromisos as $lote) {
        $renglones[] = ['lote' => $lote, 'monto' => new Monto('25000.00')];
    }

    // UN recibo para los dos lotes: ahí `compromiso_id` queda vacío (R13).
    $recibos = app(RegistroDePagos::class)->cobrarVariosLotes(
        venta: $venta,
        cliente: $this->cliente,
        renglones: $renglones,
        forma: FormaDePago::Efectivo,
    );

    expect($recibos)->toHaveCount(1)
        ->and($recibos[0]->getAttribute('compromiso_id'))->toBeNull();

    // 300,000 − 25,000 = 275,000 cada uno, y 550,000 entre los dos.
    $papel = $this->get(route('documentos.recibo', $recibos[0]))->assertOk();

    foreach ($venta->compromisos as $lote) {
        $papel->assertSee((string) $lote->lote?->getAttribute('codigo'));
    }

    $papel->assertSee('Le queda por pagar')
        ->assertSee('L. 275,000.00')
        ->assertSee('total L. 550,000.00');
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

/*
|--------------------------------------------------------------------------
| 🔴 Quién recibió el dinero — 31-ago-2026
|--------------------------------------------------------------------------
| «También debe de decir el nombre de la persona que recibió el dinero»
| — Mauricio, mirando el RPS-00000008.
|
| El sistema lo sabía desde el 27-ago (R24) y el corte de caja del día cuenta
| por él. Lo que faltaba era que lo dijera el papel que se lleva el cliente.
*/
test('el papel dice quién recibió el dinero, arriba y en la firma', function (): void {
    $nombre = (string) $this->usuario->getAttribute('name');

    ($this->papel)()
        ->assertOk()
        ->assertSee('Recibido por')
        // Las dos rayas del final dejan de ser anónimas: en un recibo el
        // dinero ENTRA, así que «recibí» es la lotificadora y «entregué», quien pagó.
        ->assertSee('Recibí conforme — '.$nombre)
        ->assertSee('Entregué conforme — '.$this->cliente->getAttribute('nombre'));
});

/*
|--------------------------------------------------------------------------
| 🔴 El recibo de la PRIMA — 31-ago-2026
|--------------------------------------------------------------------------
| «Acá en lote aparece solo una línea en el recibo» y «que salga cuánto le
| queda por pagar, que cuando es recibo por prima no sale» — Mauricio.
|
| Las dos cosas tenían la misma causa: la prima se pacta por el CONTRATO
| aunque el expediente lleve tres lotes (R5), así que su recibo va sin
| `compromiso_id` y sin aplicaciones. Preguntar «qué lotes tocó» devolvía la
| lista vacía, y de ahí salían el rótulo Y el saldo.
|
| La pregunta del papel es otra: de qué lotes HABLA.
*/
describe('El recibo de la prima', function (): void {
    beforeEach(function (): void {
        // `activar()` ya emitió este recibo: se busca por su venta y su
        // concepto, nunca con un `firstOrFail()` pelado.
        $this->prima = Recibo::query()
            ->where('venta_id', '=', $this->venta->getKey())
            ->where('concepto', '=', ConceptoDeRecibo::Prima->value)
            ->firstOrFail();

        $this->papelDeLaPrima = fn () => $this->get(route('documentos.recibo', $this->prima));
    });

    test('dice de qué lote es, aunque la prima no toque ninguno', function (): void {
        ($this->papelDeLaPrima)()
            ->assertOk()
            ->assertSee((string) $this->renglon->lote?->getAttribute('codigo'));
    });

    /*
    | `montoACapital()` es una RESTA —lo cobrado menos lo aplicado a cuotas—,
    | así que en un recibo de prima da el papel entero. El renglón existía para
    | el abono del R21 y salía con ese nombre acá: un papel diciéndole al
    | cliente que su saldo bajó por fuera del plan.
    */
    test('no le llama «abono a capital» a la prima', function (): void {
        ($this->papelDeLaPrima)()
            ->assertOk()
            ->assertSee('Prima')
            ->assertDontSee('Abono a capital')
            ->assertSee('L. 50,000.00');
    });

    /*
    | Es el saldo de HOY, no el del día que se firmó: los 300,000 financiados
    | menos los 60,000 que el `beforeEach` ya cobró. Por eso el papel lleva al
    | lado la fecha de impresión.
    */
    test('dice cuánto le queda por pagar', function (): void {
        ($this->papelDeLaPrima)()
            ->assertOk()
            ->assertSee('Le queda por pagar')
            ->assertSee('L. 240,000.00');
    });

    /*
    | 🔴 El corte de caja del día agrupa por `recibido_por`. Hasta hoy la prima
    | no lo escribía —solo lo hacía el modal de cobro— y el arqueo la sumaba
    | bajo «Sin usuario». El default se mudó al modelo justamente para que
    | ningún camino nuevo se olvide.
    */
    test('queda anotada a nombre de quien la recibió', function (): void {
        expect((int) $this->prima->getAttribute('recibido_por'))
            ->toBe((int) $this->usuario->getKey());
    });

    test('con dos lotes los nombra a los dos, en plural', function (): void {
        $proyecto = Proyecto::factory()->create(['codigo' => 'RDL']);
        $bloque = Bloque::factory()->create(['proyecto_id' => $proyecto->getKey(), 'nombre' => 'D']);

        $lotes = [
            Lote::factory()->enBloque($bloque)->conMedidas('250.0000', '1400.00')->create(['numero' => '1']),
            Lote::factory()->enBloque($bloque)->conMedidas('250.0000', '1400.00')->create(['numero' => '2']),
        ];

        $venta = app(RegistroDeVentas::class)->activar(
            proyecto: $proyecto,
            lotes: $lotes,
            clientes: [$this->cliente],
            prima: new Monto('100000.00'),
            plazoMeses: 12,
            diaPago: 5,
        );

        $prima = Recibo::query()
            ->where('venta_id', '=', $venta->getKey())
            ->where('concepto', '=', ConceptoDeRecibo::Prima->value)
            ->firstOrFail();

        $papel = $this->get(route('documentos.recibo', $prima))->assertOk()->assertSee('Lotes');

        foreach ($venta->compromisos as $renglon) {
            $papel->assertSee((string) $renglon->lote?->getAttribute('codigo'));
        }
    });
});

/*
| La otra mitad de la misma regla: un lote SIN plan de cuotas no promete un
| saldo. El apartado todavía no tiene plan, y sumar cero ahí daría «le queda
| por pagar L 0.00» a alguien que debe el lote entero.
|
| Y su renglón se llama como su concepto, igual que el de la prima.
*/
test('el recibo de la seña no le llama abono a capital ni promete un saldo que no existe', function (): void {
    $proyecto = Proyecto::factory()->create(['codigo' => 'RSN']);
    $bloque = Bloque::factory()->create(['proyecto_id' => $proyecto->getKey(), 'nombre' => 'S']);
    $lote = Lote::factory()->enBloque($bloque)->conMedidas('250.0000', '1400.00')->create(['numero' => '7']);

    $apartado = app(RegistroDeCompromisos::class)->apartar(
        lote: $lote,
        cliente: $this->cliente,
        montoSenia: '5000.00',
        forma: FormaDePago::Efectivo,
    );

    $senia = Recibo::query()
        ->where('compromiso_id', '=', $apartado->getKey())
        ->firstOrFail();

    $this->get(route('documentos.recibo', $senia))
        ->assertOk()
        ->assertSee('Seña del apartado')
        ->assertDontSee('Abono a capital')
        ->assertDontSee('Le queda por pagar');
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
