<?php

declare(strict_types=1);

use App\Domain\Enums\ConceptoDeRecibo;
use App\Domain\Enums\FormaDePago;
use App\Domain\Enums\ModalidadDeReprogramacion;
use App\Domain\Pagos\RegistroDePagos;
use App\Domain\ValueObjects\Monto;
use App\Domain\Ventas\RegistroDeVentas;
use App\Filament\Resources\Ventas\Pages\ViewVenta;
use App\Filament\Support\CobrarUnPago;
use App\Filament\Support\ModoDeCobro;
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
| El modo «Ambas» — cobrar la cuota y abonar el sobrante, con UN recibo
|--------------------------------------------------------------------------
| El caso que hasta el 10-ago-2026 NO tenía solución: el lote tiene una cuota
| pagada a medias y el cliente llega con dinero para terminarla y bajar el
| capital con el resto.
|
| 🔴 La raya fina, que se midió acá el 10-ago: el abono NO se rechaza siempre.
| Su tope es `vencido + lo reprogramable`, y lo que le falta a la cuota a medias
| queda FUERA de esa suma. O sea:
|
|   - por debajo del tope, `abonarACapital()` entra igual — pero deja al cliente
|     «con capital abonado» y una cuota a medias al mismo tiempo, que son las
|     dos verdades sobre un mismo contrato que R21 no quiere;
|   - por encima del tope se rechaza, y ahí «Ambas» es el ÚNICO camino en un
|     solo trámite.
|
| Los dos casos están abajo, con el mismo escenario y el mismo monto.
|
| Un lote de 250 vr² a L 1,400.00 son L 350,000.00; con L 50,000.00 de prima
| quedan L 300,000.00 a 12 meses, o sea cuotas de L 25,000.00 exactas.
|
| El escenario de todos los tests: la cuota 1 pagada a medias —L 12,500.00 de
| L 25,000.00— y NADA vencido.
|
| ⚠️ Desde el 10-ago (tarde) «Ambas» NO adivina la raya: se teclea el total
| recibido, se marcan las cuotas que cubre, y el sobrante —total menos cuotas—
| baja el capital del lote elegido. Acá se marca la cuota 1 por L 12,500.00,
| que es lo que le falta, así que la raya cae en el mismo lugar que cuando la
| calculaba el sistema y los números de abajo no cambiaron.
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

    /*
     * La cuota a medias, hecha por la puerta de siempre. Se cobra por el
     * Service y no con un `update()` a mano a propósito: así la cuota queda
     * con su aplicación de pago colgando, que es justo lo que hace que R21 no
     * la pueda tocar.
     */
    app(RegistroDePagos::class)->cobrarCuotas(
        venta: $this->venta,
        lote: $this->renglon,
        cliente: $this->cliente,
        monto: new Monto('12500.00'),
        forma: FormaDePago::Efectivo,
    );

    $this->expediente = fn (): object => Livewire::test(
        ViewVenta::class,
        ['record' => $this->venta->getKey()],
    );

    /*
     * Los dos modos que reprograman llenan campos DISTINTOS, y este archivo
     * los usa a los dos:
     *
     *   - «Ambas» → `monto_total`, las cuotas marcadas, y `compromiso_id`
     *              + `modalidad` para el lote que recibe el sobrante
     *   - «Abono» va por renglones   → `abonar_N`, `abono_N`, `modalidad_N`
     *
     * Se mandan los dos juegos siempre: Filament no deshidrata los campos que
     * el modo elegido oculta, así que el que no aplica se descarta solo y cada
     * test elige el modo sin tener que armar su propio arreglo.
     */
    $this->datos = function (array $extra = []): array {
        $id = $this->renglon->getKey();

        return array_merge([
            'modo'          => ModoDeCobro::Ambas->value,
            'monto_total'   => '87500.00',
            "cobrar_{$id}"  => true,
            "monto_{$id}"   => '12500.00',
            'compromiso_id' => $id,
            'modalidad'     => ModalidadDeReprogramacion::AcortarPlazo->value,

            "abonar_{$id}"    => true,
            "abono_{$id}"     => '87500.00',
            "modalidad_{$id}" => ModalidadDeReprogramacion::AcortarPlazo->value,

            'forma_pago' => FormaDePago::Efectivo->value,
            'fecha'      => today()->toDateString(),
            'motivo'     => 'Terminó la cuota y abonó el resto',
        ], $extra);
    };

    // Los recibos del escenario, sin contar la prima ni el cobro de arriba.
    $this->recibosNuevos = fn (): int => Recibo::query()
        ->whereNotIn('concepto', [ConceptoDeRecibo::Prima, ConceptoDeRecibo::Senia])
        ->count() - 1;
});

/*
| El golden test del reparto. L 87,500.00 se parten solos en L 12,500.00 que
| terminan la cuota 1 y L 75,000.00 que bajan el capital:
|
|   - quedan pendientes las cuotas 2..12 → 11 × 25,000 = 275,000
|   - 275,000 − 75,000 = 200,000, que en cuotas de 25,000 son 8 exactas
|   - la cuota 1 no se toca: sigue existiendo, pagada, y el recibo viejo sigue
|     apuntando a ella
*/
test('«Ambas» termina la cuota a medias y abona el sobrante', function (): void {
    ($this->expediente)()
        ->callAction('cobrar', ($this->datos)())
        ->assertHasNoActionErrors();

    $cuotas = Cuota::query()
        ->where('compromiso_id', $this->renglon->getKey())
        ->orderBy('numero')
        ->get();

    expect($cuotas)->toHaveCount(9)
        // La 1 sobrevivió entera y quedó saldada por la mitad de cobro.
        ->and($cuotas->firstOrFail()->getAttribute('numero'))->toBe(1)
        ->and($cuotas->firstOrFail()->saldo())->toBeMonto('0.00')
        // El plan nuevo empieza en la 2 y son ocho cuotas de 25,000.
        ->and($this->venta->refresh()->saldoPendiente())->toBeMonto('200000.00')
        ->and(($this->recibosNuevos)())->toBe(1)
        ->and(Reprogramacion::query()->count())->toBe(1);
});

/*
| UN cliente, UN billete, UN papel. Y el papel dice «abono a capital» porque
| reescribió un plan: `anular()` rechaza los recibos que reprogramaron, y si
| este dijera «cuota» se podría anular dejando un plan nuevo pagado con dinero
| que ya no entró.
*/
test('sale un solo recibo, y dice que reprogramó', function (): void {
    ($this->expediente)()
        ->callAction('cobrar', ($this->datos)())
        ->assertHasNoActionErrors();

    $recibo = Recibo::query()->where('concepto', ConceptoDeRecibo::AbonoCapital)->sole();
    $constancia = Reprogramacion::query()->sole();

    expect($recibo->montoTotal())->toBeMonto('87500.00')
        // La mitad de cuota deja su aplicación; la que bajó capital no aplica
        // a ninguna cuota porque las borró.
        ->and($recibo->aplicaciones()->count())->toBe(1)
        ->and($constancia->montoAbonado())->toBeMonto('75000.00')
        ->and($constancia->getAttribute('recibo_id'))->toBe($recibo->getKey());
});

/*
| Por debajo del tope el abono SÍ entra, y ese es justamente el problema que
| «Ambas» resuelve: los dos mueven los mismos L 87,500.00 y dejan el mismo saldo
| de L 200,000.00, pero el abono deja la cuota 1 debiendo L 12,500.00 para
| siempre. El cliente queda con capital abonado y una cuota a medias — las dos
| verdades sobre un mismo contrato que R21 no quiere.
|
| Este test fija esa diferencia. Si algún día el abono empieza a saldar la
| cuota a medias por su cuenta, se cae acá y hay que revisar R21.
*/
test('por debajo del tope, el abono entra pero deja la cuota a medias', function (): void {
    ($this->expediente)()
        ->callAction('cobrar', ($this->datos)(['modo' => ModoDeCobro::Abono->value]))
        ->assertHasNoActionErrors();

    $primera = Cuota::query()
        ->where('compromiso_id', $this->renglon->getKey())
        ->orderBy('numero')
        ->firstOrFail();

    expect($this->venta->refresh()->saldoPendiente())->toBeMonto('200000.00')
        // El mismo saldo que «Ambas», pero la cuota 1 sigue debiendo.
        ->and($primera->saldo())->toBeMonto('12500.00');
});

/*
| 🔴 Por ENCIMA del tope, el abono se rechaza — y ahí «Ambas» es el único
| camino en un solo trámite.
|
| El tope es lo vencido (cero acá) más lo reprogramable: las cuotas 2..12, o sea
| L 275,000.00. Con L 280,000.00 el abono se pasa, y lo que sobra es exactamente
| lo que le falta a la cuota a medias — la que R21 no deja tocar.
*/
test('por encima del tope, el abono se rechaza', function (): void {
    ($this->expediente)()
        ->callAction('cobrar', ($this->datos)([
            'modo'                            => ModoDeCobro::Abono->value,
            'abono_'.$this->renglon->getKey() => '280000.00',
        ]))
        ->assertHasNoActionErrors();

    expect(($this->recibosNuevos)())->toBe(0)
        ->and(Reprogramacion::query()->count())->toBe(0)
        ->and($this->venta->refresh()->saldoPendiente())->toBeMonto('287500.00');
});

/*
| Y el MISMO monto por «Ambas» entra: L 12,500.00 terminan la cuota 1 y
| L 267,500.00 bajan el capital. De los L 275,000.00 reprogramables quedan
| L 7,500.00, que en cuotas de L 25,000.00 es una sola cuota más chica.
*/
test('ese mismo monto por «Ambas» sí entra, y en UN recibo', function (): void {
    ($this->expediente)()
        ->callAction('cobrar', ($this->datos)(['monto_total' => '280000.00']))
        ->assertHasNoActionErrors();

    $cuotas = Cuota::query()
        ->where('compromiso_id', $this->renglon->getKey())
        ->orderBy('numero')
        ->get();

    expect($cuotas)->toHaveCount(2)
        ->and($cuotas->firstOrFail()->saldo())->toBeMonto('0.00')
        ->and($this->venta->refresh()->saldoPendiente())->toBeMonto('7500.00')
        ->and(($this->recibosNuevos)())->toBe(1)
        ->and(Reprogramacion::query()->count())->toBe(1);
});

/*
| Si el dinero no pasa la raya no hay capital que bajar, y registrarlo igual
| dejaría una constancia de reprogramación que no reprogramó nada —con su
| motivo y todo—. Se rechaza con el número que falta, y el error del dominio
| sale como notificación: lo que NO puede pasar es un 500 con el cliente
| enfrente.
*/
test('sin sobrante no hay abono, y no se registra nada', function (): void {
    ($this->expediente)()
        ->callAction('cobrar', ($this->datos)(['monto_total' => '10000.00']))
        ->assertHasNoActionErrors();

    expect(($this->recibosNuevos)())->toBe(0)
        ->and(Reprogramacion::query()->count())->toBe(0)
        ->and($this->venta->refresh()->saldoPendiente())->toBeMonto('287500.00');
});

/*
| §9.E3 y la frontera de R21, ahora que los tres caminos comparten un modal.
|
| Al receptor el toggle ni se le muestra —una sola opción es ruido— así que
| Filament no deshidrata el campo y el modo cae en «Cuota». Pero eso es la
| comodidad, no el borde: este test manda `modo=ambas` a mano, que es lo que
| haría alguien con las herramientas del navegador abiertas. Lo que se prueba
| es que NO queda una reprogramación, venga el modo de donde venga.
*/
test('el receptor no reprograma aunque mande el modo a mano', function (): void {
    $receptor = rol('receptor_de_prueba');
    $receptor->syncPermissions(['ViewAny:Venta', 'View:Venta', 'Create:Recibo']);

    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole($receptor);

    resolve(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->actingAs($user);

    ($this->expediente)()
        ->callAction('cobrar', ($this->datos)())
        ->assertHasNoActionErrors();

    expect(Reprogramacion::query()->count())->toBe(0)
        ->and(Recibo::query()->where('concepto', ConceptoDeRecibo::AbonoCapital)->count())->toBe(0);
});

/*
| El permiso caro sí abre los tres caminos. Sin este test, el de arriba pasaría
| igual con un toggle roto que nunca ofrece «Ambas» a nadie.
*/
test('quien puede reprogramar sí ve el camino completo', function (): void {
    ($this->expediente)()->assertActionVisible('cobrar');

    // El camino completo ya no es un segundo boton: son las tres opciones de
    // adentro del modal, y quien las habilita es este permiso.
    expect(CobrarUnPago::seLePermiteAbonar())->toBeTrue();
});
