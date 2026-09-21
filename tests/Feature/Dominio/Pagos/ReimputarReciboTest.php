<?php

declare(strict_types=1);

use App\Domain\Enums\ConceptoDeRecibo;
use App\Domain\Enums\FormaDePago;
use App\Domain\Enums\ModalidadDeReprogramacion;
use App\Domain\Exceptions\ReimputacionInvalidaException;
use App\Domain\Pagos\RegistroDePagos;
use App\Domain\Pagos\ReimputacionDeRecibo;
use App\Domain\ValueObjects\Monto;
use App\Domain\Ventas\RegistroDeVentas;
use App\Models\Bloque;
use App\Models\Cliente;
use App\Models\Compromiso;
use App\Models\Cuota;
use App\Models\Lote;
use App\Models\Proyecto;
use App\Models\Recibo;
use App\Models\Reprogramacion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

/*
|--------------------------------------------------------------------------
| `olympo:reimputar-recibo` — el dinero bien cobrado y mal repartido
|--------------------------------------------------------------------------
| Salió del expediente 0031 de la primera instalación: dos lotes en un
| contrato y un abono a capital que el cuaderno anotó sin decir a qué lote.
| La carga lo repartió a medias —es lo razonable cuando no se sabe más— y
| después se supo: «en el lote 2 solo son los 10,000 de prima, en el otro va
| todo el resto» — Mauricio, 21-sep-2026.
|
| 🔴 ESTE TEST EXISTE PORQUE EL COMANDO ESCRIBE EN PRODUCCION. El camino que
| toca la cartera real no puede estrenarse ahí: se estrena acá.
|
| ═══ LOS NUMEROS ═══
|
| Dos lotes de 250 vr² a L 1,400.00 = L 350,000.00 cada uno, L 50,000.00 de
| prima cada uno, 24 meses: cuotas de L 12,500.00 exactas, L 300,000.00
| financiados por lote.
|
| «Hoy» es día 20 y el contrato se firmó hace tres meses con pago los días 5,
| así que cada lote tiene TRES cuotas vencidas (L 37,500.00). Eso importa: la
| cartera vieja pone primero al día lo vencido y manda el resto a capital.
|
| El recibo viejo es de L 105,000.00 y entró a medias, L 52,500.00 por lote:
|   · 37,500 a las cuotas 1, 2 y 3 + 15,000 a capital → saldo 247,500.00.
|
| Re-imputado entero al primer lote:
|   · lote 1: 37,500 a cuotas + 67,500 a capital → saldo 195,000.00, que son
|     15 cuotas de 12,500 y una última de 7,500.00.
|   · lote 2: vuelve a su plan de 24 cuotas sin un pago → saldo 300,000.00,
|     con tres vencidas.
|   · entre los dos: 495,000.00 antes y 495,000.00 después.
*/

beforeEach(function (): void {
    actingAsAdmin();

    $this->hoy = CarbonImmutable::parse(today()->toDateString())->startOfMonth()->addDays(19);
    $this->travelTo($this->hoy);

    $proyecto = Proyecto::factory()->create(['codigo' => 'RPS']);
    $bloque = Bloque::factory()->create(['proyecto_id' => $proyecto->getKey(), 'nombre' => 'A']);

    $lote = static fn (string $numero): Lote => Lote::factory()->enBloque($bloque)
        ->conMedidas('250.0000', '1400.00')
        ->create(['numero' => $numero]);

    $this->cliente = Cliente::factory()->create(['nombre' => 'MELVIN RODRIGUEZ']);

    $this->venta = app(RegistroDeVentas::class)->activar(
        proyecto: $proyecto,
        lotes: [$lote('1'), $lote('2')],
        clientes: [$this->cliente],
        prima: new Monto('100000.00'),
        plazoMeses: 24,
        diaPago: 5,
        fechaContrato: $this->hoy->subMonthsNoOverflow(3)->day(10),
        deLaCarteraVieja: true,
    );

    [$this->uno, $this->dos] = $this->venta->compromisos()->with('lote')->orderBy('id')->get()->all();

    $this->codigoUno = (string) $this->uno->lote?->getAttribute('codigo');
    $this->codigoDos = (string) $this->dos->lote?->getAttribute('codigo');

    // Quien recibió el dinero NO es quien teclea: tiene que sobrevivir.
    $this->elder = User::factory()->create(['name' => 'ELDER PINTO', 'is_active' => true]);

    $this->fechaDelPago = $this->hoy->subMonthsNoOverflow(1)->day(15);

    $this->recibo = app(RegistroDePagos::class)
        ->loRecibio((int) $this->elder->getKey())
        ->abonarAVariosLotes(
            venta: $this->venta,
            cliente: $this->cliente,
            renglones: [
                ['lote' => $this->uno, 'monto' => new Monto('52500.00'), 'modalidad' => ModalidadDeReprogramacion::AcortarPlazo],
                ['lote' => $this->dos, 'monto' => new Monto('52500.00'), 'modalidad' => ModalidadDeReprogramacion::AcortarPlazo],
            ],
            motivo: 'Abono a capital registrado en el cuaderno antes del sistema.',
            forma: FormaDePago::Deposito,
            referencia: '004300',
            fecha: $this->fechaDelPago,
            observaciones: 'Recibo 00000377 del talonario. Cuaderno: abono a capital.',
            deLaCarteraVieja: true,
        )[0];

    $this->todoAlPrimero = fn (): array => [$this->codigoUno => new Monto('105000.00')];

    $this->reimputar = fn (?array $pedido = null, string $motivo = 'Todo el abono era del lote 1') => app(ReimputacionDeRecibo::class)
        ->reimputar($this->venta, $this->recibo, $pedido ?? ($this->todoAlPrimero)(), $motivo);
});

/**
 * Lo que le falta pagar a un lote, sumando sus cuotas desde la base.
 */
function saldoTrasReimputar(Compromiso $lote): Monto
{
    $saldo = Monto::cero();

    foreach (Cuota::query()->where('compromiso_id', $lote->getKey())->get() as $cuota) {
        $saldo = $saldo->sumar($cuota->saldo());
    }

    return $saldo;
}

/**
 * El recibo vivo que reemplazó al re-imputado: el último de la venta.
 */
function reciboQueReemplaza(Recibo $viejo): Recibo
{
    return Recibo::query()
        ->where('venta_id', $viejo->getAttribute('venta_id'))
        ->whereNull('anulado_el')
        ->whereKeyNot($viejo->getKey())
        ->orderByDesc('id')
        ->firstOrFail();
}

test('el escenario arranca como lo dejó la carga: a medias', function (): void {
    // Si esto falla, ningún número de los tests de abajo significa nada.
    expect(saldoTrasReimputar($this->uno))->toBeMonto('247500.00')
        ->and(saldoTrasReimputar($this->dos))->toBeMonto('247500.00')
        ->and($this->recibo->getAttribute('concepto'))->toBe(ConceptoDeRecibo::AbonoCapital)
        ->and($this->recibo->esDeLaCarteraVieja())->toBeTrue()
        ->and(Reprogramacion::query()->where('recibo_id', $this->recibo->getKey())->count())->toBe(2);
});

/*
| EL CASO DE MAURICIO, tal cual lo contó.
*/
test('todo el recibo va al primer lote, y el segundo vuelve a su plan sin un pago', function (): void {
    $hecha = ($this->reimputar)();

    expect($hecha->escrita)->toBeTrue()
        ->and($hecha->folioViejo)->toBe($this->recibo->folio())
        ->and($hecha->foliosNuevos)->toHaveCount(1);

    // El primer lote: tres cuotas al día y L 67,500.00 menos de capital.
    $cuotasDelUno = Cuota::query()->where('compromiso_id', $this->uno->getKey())->orderBy('numero')->get();

    expect(saldoTrasReimputar($this->uno))->toBeMonto('195000.00')
        ->and($cuotasDelUno)->toHaveCount(19)
        ->and($cuotasDelUno->take(3)->every(static fn (Cuota $cuota): bool => $cuota->estaPagada()))->toBeTrue()
        ->and($cuotasDelUno->last()?->montoTotal())->toBeMonto('7500.00');

    // El segundo: las 24 cuotas de siempre, ninguna pagada, ninguna constancia.
    $cuotasDelDos = Cuota::query()->where('compromiso_id', $this->dos->getKey())->get();

    expect(saldoTrasReimputar($this->dos))->toBeMonto('300000.00')
        ->and($cuotasDelDos)->toHaveCount(24)
        ->and($cuotasDelDos->every(static fn (Cuota $cuota): bool => $cuota->montoPagado()->esCero()))->toBeTrue()
        ->and(Reprogramacion::query()->where('compromiso_id', $this->dos->getKey())->count())->toBe(0);
});

test('el retrato dice cuánto fue a cuotas, cuánto a capital y quién amanece atrasado', function (): void {
    $hecha = ($this->reimputar)();

    [$uno, $dos] = $hecha->lotes;

    expect($uno->codigo)->toBe($this->codigoUno)
        ->and($uno->imputadoAntes)->toBeMonto('52500.00')
        ->and($uno->imputadoDespues)->toBeMonto('105000.00')
        ->and($uno->aCuotas)->toBeMonto('37500.00')
        ->and($uno->aCapital)->toBeMonto('67500.00')
        ->and($uno->saldoDespues)->toBeMonto('195000.00')
        ->and($uno->vencidasDespues)->toBe(0);

    // 🔴 Lo que hay que ver ANTES de escribir: el lote que pierde el dinero
    // amanece con sus tres cuotas vencidas.
    expect($dos->codigo)->toBe($this->codigoDos)
        ->and($dos->imputadoAntes)->toBeMonto('52500.00')
        ->and($dos->imputadoDespues)->toBeMonto('0.00')
        ->and($dos->saldoDespues)->toBeMonto('300000.00')
        ->and($dos->vencidasAntes)->toBe(0)
        ->and($dos->vencidasDespues)->toBe(3)
        ->and($dos->debeVencidoDespues)->toBeMonto('37500.00');
});

/*
| 🔴 EL TEST QUE JUSTIFICA EL ARCHIVO: re-imputar reparte, no crea ni perdona.
*/
test('ni se crea ni se pierde un lempira', function (): void {
    $debia = saldoTrasReimputar($this->uno)->sumar(saldoTrasReimputar($this->dos));
    $enCaja = Recibo::query()->whereNull('anulado_el')->sum('monto');

    ($this->reimputar)();

    expect(saldoTrasReimputar($this->uno)->sumar(saldoTrasReimputar($this->dos)))->toBeMonto($debia->redondeado())
        ->and($debia)->toBeMonto('495000.00')
        // La caja del día del pago tampoco se mueve: sale un papel y entra otro igual.
        ->and(Recibo::query()->whereNull('anulado_el')->sum('monto'))->toEqual($enCaja)
        ->and($this->venta->fresh()?->saldoPendiente())->toBeMonto('495000.00');

    $this->artisan('olympo:cuadrar-recibos')
        ->expectsOutputToContain('Todos los recibos cuadran')
        ->assertSuccessful();
});

/*
| 🔴 «No hay anulados, directamente se borran esas transacciones» — Mauricio.
| En el cuaderno hay UN pago y nadie anuló nada: el recibo viejo se va entero,
| y el rastro queda en la bitácora del expediente (el test de más abajo).
*/
test('el recibo viejo se borra —no queda anulado— y el nuevo conserva todo lo demás', function (): void {
    ($this->reimputar)(motivo: 'Lo confirmó el cliente en ventanilla');

    $nuevo = reciboQueReemplaza($this->recibo);

    expect(Recibo::query()->whereKey($this->recibo->getKey())->exists())->toBeFalse()
        ->and(Recibo::query()->where('venta_id', $this->venta->getKey())->whereNotNull('anulado_el')->count())->toBe(0);

    expect($nuevo->esDeLaCarteraVieja())->toBeTrue()
        ->and($nuevo->montoTotal())->toBeMonto('105000.00')
        ->and($nuevo->getAttribute('concepto'))->toBe(ConceptoDeRecibo::AbonoCapital)
        ->and($nuevo->getAttribute('forma_pago'))->toBe(FormaDePago::Deposito)
        ->and($nuevo->getAttribute('referencia'))->toBe('004300')
        ->and($nuevo->getAttribute('fecha')?->format('Y-m-d'))->toBe($this->fechaDelPago->format('Y-m-d'))
        ->and($nuevo->getAttribute('recibido_por'))->toBe($this->elder->getKey())
        ->and($nuevo->getAttribute('compromiso_id'))->toBe($this->uno->getKey())
        // El número del talonario de papel viaja en la nota, y llega tal cual:
        // no nombra al recibo viejo, que ya no existe.
        ->and($nuevo->getAttribute('observaciones'))->toBe('Recibo 00000377 del talonario. Cuaderno: abono a capital.');

    // La constancia nueva dice lo que decía la vieja.
    $constancia = Reprogramacion::query()->where('recibo_id', $nuevo->getKey())->firstOrFail();

    expect($constancia->montoAbonado())->toBeMonto('67500.00')
        ->and($constancia->modalidadElegida())->toBe(ModalidadDeReprogramacion::AcortarPlazo)
        ->and($constancia->getAttribute('motivo'))->toBe('Abono a capital registrado en el cuaderno antes del sistema.');
});

test('queda asentado en la bitácora del expediente, con su motivo', function (): void {
    ($this->reimputar)(motivo: 'Lo confirmó el cliente en ventanilla');

    $asiento = Activity::query()
        ->where('event', 'reimputacion')
        ->where('subject_type', $this->venta->getMorphClass())
        ->where('subject_id', $this->venta->getKey())
        ->firstOrFail();

    expect($asiento->properties->get('motivo'))->toBe('Lo confirmó el cliente en ventanilla');
});

/*
| 🔴 EL ENSAYO NO ES UNA ESTIMACION: corre todo de verdad y lo deshace.
*/
test('el ensayo muestra los mismos números y no deja nada escrito', function (): void {
    $recibos = Recibo::query()->count();
    $serie = DB::table('correlativos')->where('tipo', 'recibo_historico')->value('ultimo_numero');

    $hecha = app(ReimputacionDeRecibo::class)->ensayar($this->venta, $this->recibo, ($this->todoAlPrimero)());

    expect($hecha->escrita)->toBeFalse()
        ->and($hecha->lotes[0]->saldoDespues)->toBeMonto('195000.00')
        ->and($hecha->lotes[1]->saldoDespues)->toBeMonto('300000.00')
        ->and($hecha->lotes[1]->vencidasDespues)->toBe(3);

    expect($this->recibo->fresh()?->estaAnulado())->toBeFalse()
        ->and(Recibo::query()->count())->toBe($recibos)
        ->and(DB::table('correlativos')->where('tipo', 'recibo_historico')->value('ultimo_numero'))->toBe($serie)
        ->and(saldoTrasReimputar($this->uno))->toBeMonto('247500.00')
        ->and(saldoTrasReimputar($this->dos))->toBeMonto('247500.00')
        ->and(Reprogramacion::query()->where('recibo_id', $this->recibo->getKey())->count())->toBe(2)
        ->and(Activity::query()->where('event', 'reimputacion')->count())->toBe(0);
});

test('también se puede repartir distinto sin mandarlo todo a un lote', function (): void {
    // 80,000 al primero: 37,500 a cuotas y 42,500 a capital. 25,000 al segundo:
    // no le alcanza para lo vencido, así que son sus cuotas 1 y 2 y nada más.
    ($this->reimputar)([
        $this->codigoUno => new Monto('80000.00'),
        $this->codigoDos => new Monto('25000.00'),
    ]);

    expect(saldoTrasReimputar($this->uno))->toBeMonto('220000.00')
        ->and(saldoTrasReimputar($this->dos))->toBeMonto('275000.00')
        ->and(Reprogramacion::query()->where('compromiso_id', $this->dos->getKey())->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Lo que se niega a hacer
|--------------------------------------------------------------------------
*/

test('sin motivo no se escribe nada', function (): void {
    expect(fn () => ($this->reimputar)(motivo: '   '))
        ->toThrow(ReimputacionInvalidaException::class, 'Falta el motivo');

    expect($this->recibo->fresh()?->estaAnulado())->toBeFalse();
});

test('lo pedido tiene que sumar el recibo exacto', function (): void {
    expect(fn () => ($this->reimputar)([$this->codigoUno => new Monto('100000.00')]))
        ->toThrow(ReimputacionInvalidaException::class, 'no puede sobrar ni faltar un centavo');

    expect($this->recibo->fresh()?->estaAnulado())->toBeFalse();
});

test('un lote que no es del expediente se rechaza', function (): void {
    expect(fn () => ($this->reimputar)(['RPS-Z-099' => new Monto('105000.00')]))
        ->toThrow(ReimputacionInvalidaException::class, 'no es de este expediente');
});

test('pedir el reparto que ya tiene no hace nada', function (): void {
    expect(fn () => ($this->reimputar)([
        $this->codigoUno => new Monto('52500.00'),
        $this->codigoDos => new Monto('52500.00'),
    ]))->toThrow(ReimputacionInvalidaException::class, 'ya está imputado exactamente así');

    expect($this->recibo->fresh()?->estaAnulado())->toBeFalse();
});

test('la segunda corrida ya no encuentra el recibo viejo y no vuelve a mover nada', function (): void {
    ($this->reimputar)();

    $recibos = Recibo::query()->count();

    expect(fn () => ($this->reimputar)())
        ->toThrow(ReimputacionInvalidaException::class, 'ya no existe');

    expect(Recibo::query()->count())->toBe($recibos)
        ->and(saldoTrasReimputar($this->uno))->toBeMonto('195000.00');
});

test('la prima no se re-imputa', function (): void {
    $prima = Recibo::query()
        ->where('venta_id', $this->venta->getKey())
        ->where('concepto', ConceptoDeRecibo::Prima->value)
        ->firstOrFail();

    expect(fn () => app(ReimputacionDeRecibo::class)->reimputar(
        $this->venta,
        $prima,
        [$this->codigoUno => new Monto('100000.00')],
        'No debería poderse',
    ))->toThrow(ReimputacionInvalidaException::class, 'Solo se re-imputan los cobros de cuotas y los abonos a capital');
});

test('un recibo que imprimió el sistema no se re-imputa por acá', function (): void {
    $delSistema = app(RegistroDePagos::class)->cobrarCuotas(
        venta: $this->venta,
        lote: $this->uno,
        cliente: $this->cliente,
        monto: new Monto('12500.00'),
        forma: FormaDePago::Efectivo,
    );

    expect(fn () => app(ReimputacionDeRecibo::class)->reimputar(
        $this->venta,
        $delSistema,
        [$this->codigoDos => new Monto('12500.00')],
        'No debería poderse',
    ))->toThrow(ReimputacionInvalidaException::class, 'lo imprimió el sistema');
});

/*
|--------------------------------------------------------------------------
| El comando
|--------------------------------------------------------------------------
*/

test('el comando ensaya por número de contrato y folio, y no escribe', function (): void {
    $this->artisan('olympo:reimputar-recibo', [
        'venta'    => (string) $this->venta->getAttribute('numero_contrato'),
        'recibo'   => $this->recibo->folio(),
        '--lote'   => [$this->codigoUno.':105000.00'],
        '--ensayo' => true,
    ])
        ->expectsOutputToContain('Ensayo: no se escribió nada')
        ->assertSuccessful();

    expect($this->recibo->fresh()?->estaAnulado())->toBeFalse()
        ->and(saldoTrasReimputar($this->dos))->toBeMonto('247500.00');
});

test('el comando escribe, y pegarlo dos veces no hace daño', function (): void {
    $pedido = [
        'venta'    => (int) $this->venta->getKey(),
        'recibo'   => $this->recibo->folio(),
        '--lote'   => [$this->codigoUno.':105000.00'],
        '--motivo' => 'Todo el abono era del lote 1',
    ];

    $this->artisan('olympo:reimputar-recibo', $pedido)
        ->expectsOutputToContain('Re-imputado')
        ->assertSuccessful();

    expect(saldoTrasReimputar($this->uno))->toBeMonto('195000.00')
        ->and(saldoTrasReimputar($this->dos))->toBeMonto('300000.00');

    $recibos = Recibo::query()->count();

    $this->artisan('olympo:reimputar-recibo', $pedido)
        ->expectsOutputToContain('No encontré ese recibo')
        ->assertFailed();

    expect(Recibo::query()->count())->toBe($recibos)
        ->and(saldoTrasReimputar($this->uno))->toBeMonto('195000.00');
});

test('el comando sin motivo no escribe', function (): void {
    $this->artisan('olympo:reimputar-recibo', [
        'venta'  => (int) $this->venta->getKey(),
        'recibo' => $this->recibo->folio(),
        '--lote' => [$this->codigoUno.':105000.00'],
    ])
        ->expectsOutputToContain('Falta el motivo')
        ->assertFailed();

    expect($this->recibo->fresh()?->estaAnulado())->toBeFalse();
});

test('un folio que no es de ese expediente no encuentra nada', function (): void {
    $this->artisan('olympo:reimputar-recibo', [
        'venta'    => (int) $this->venta->getKey(),
        'recibo'   => '999999',
        '--lote'   => [$this->codigoUno.':105000.00'],
        '--ensayo' => true,
    ])
        ->expectsOutputToContain('No encontré ese recibo')
        ->assertFailed();
});

test('un --lote mal escrito se rechaza antes de tocar nada', function (): void {
    $this->artisan('olympo:reimputar-recibo', [
        'venta'    => (int) $this->venta->getKey(),
        'recibo'   => $this->recibo->folio(),
        '--lote'   => [$this->codigoUno.'=105,000'],
        '--ensayo' => true,
    ])
        ->expectsOutputToContain('--lote mal escrito')
        ->assertFailed();
});
