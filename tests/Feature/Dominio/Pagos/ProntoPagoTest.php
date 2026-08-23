<?php

declare(strict_types=1);

use App\Domain\Enums\ConceptoDeRecibo;
use App\Domain\Enums\EstadoVenta;
use App\Domain\Enums\FormaDePago;
use App\Domain\Exceptions\PagoInvalidoException;
use App\Domain\Pagos\RegistroDePagos;
use App\Domain\ValueObjects\Monto;
use App\Domain\Ventas\RegistroDeVentas;
use App\Models\Bloque;
use App\Models\Cliente;
use App\Models\Compromiso;
use App\Models\Cuota;
use App\Models\Lote;
use App\Models\Proyecto;
use App\Models\Recibo;
use App\Models\Venta;
use Spatie\Activitylog\Models\Activity;

/*
|--------------------------------------------------------------------------
| El pronto pago — 23-ago-2026
|--------------------------------------------------------------------------
| Mauricio: «quiere pagar todo el lote 2 pero pide un descuento; se le coloca
| cuánto se le dio de descuento y que pague el resto, y ya quedaría pagado».
|
| DOS lotes de 250 vr² a L 1,400.00 son L 700,000.00; con L 100,000.00 de
| prima quedan L 600,000.00 a 12 meses. Cada lote queda con doce cuotas de
| L 25,000.00 exactas, así que todos los números de abajo se verifican sin
| calculadora.
|
| 🔴 LO QUE ESTOS TESTS CUIDAN DE VERDAD es una sola cosa, y no se ve en
| pantalla cuando falla: que **la caja reciba solo lo que el cliente
| entregó**. Un descuento que se cuele en `recibos.monto` cuadra el
| expediente y descuadra el corte de caja — y eso no se descubre hasta que
| alguien cuenta el efectivo del día y le sobra en el papel lo que le falta
| en la mano.
*/

beforeEach(function (): void {
    actingAsAdmin();

    $this->pagos = app(RegistroDePagos::class);

    $proyecto = Proyecto::factory()->create(['codigo' => 'RPS']);
    $bloque = Bloque::factory()->create(['proyecto_id' => $proyecto->getKey(), 'nombre' => 'A']);

    $lote = static fn (string $numero): Lote => Lote::factory()->enBloque($bloque)
        ->conMedidas('250.0000', '1400.00')
        ->create(['numero' => $numero]);

    $this->cliente = Cliente::factory()->create(['nombre' => 'Leticia Romero']);

    $this->venta = app(RegistroDeVentas::class)->activar(
        proyecto: $proyecto,
        lotes: [$lote('1'), $lote('2')],
        clientes: [$this->cliente],
        prima: new Monto('100000.00'),
        plazoMeses: 12,
        diaPago: 5,
    );

    [$this->uno, $this->dos] = $this->venta->compromisos()->orderBy('id')->get()->all();
});

/**
 * El atajo de este archivo: un pronto pago con lo que ya está armado.
 *
 * ⚠️ Es una FUNCION y no un closure en `$this`, y no es una preferencia de
 * estilo: `prontoPago()` recibe `list<array{lote: …, descuento: …}>`, y un
 * `fn (array $renglones) => …` borra esa forma —queda en `array` pelado— así
 * que PHPStan rechaza el reenvío. Un closure no puede llevar `@param`; una
 * función sí. Es el mismo molde que `numeric-string` perdiéndose al cruzar
 * un parámetro `string`.
 *
 * @param list<array{lote: Compromiso, descuento: Monto}> $renglones
 *
 * @return list<Recibo>
 */
function prontoPagoDe(
    Venta $venta,
    Cliente $cliente,
    array $renglones,
    string $motivo = 'El cliente cancela y pide rebaja',
): array {
    return app(RegistroDePagos::class)->prontoPago(
        venta: $venta,
        cliente: $cliente,
        renglones: $renglones,
        motivo: $motivo,
        forma: FormaDePago::Efectivo,
    );
}

/**
 * Lo que le falta pagar a un lote, sumando sus cuotas.
 */
function loQueDebeElLote(Compromiso $lote): Monto
{
    $saldo = Monto::cero();

    foreach (Cuota::query()->where('compromiso_id', $lote->getKey())->get() as $cuota) {
        $saldo = $saldo->sumar($cuota->saldo());
    }

    return $saldo;
}

/**
 * Lo que se perdonó de un lote, sumando sus cuotas.
 */
function loQueSePerdonoDelLote(Compromiso $lote): Monto
{
    $total = Monto::cero();

    foreach (Cuota::query()->where('compromiso_id', $lote->getKey())->get() as $cuota) {
        $total = $total->sumar($cuota->capitalCondonado());
    }

    return $total;
}

test('el plan arranca donde dice el encabezado', function (): void {
    // Si esto falla, ningún número de los tests de abajo significa nada.
    expect(loQueDebeElLote($this->uno))->toBeMonto('300000.00')
        ->and(loQueDebeElLote($this->dos))->toBeMonto('300000.00');
});

/*
| EL CASO DE MAURICIO, tal cual lo contó.
*/
test('pagó una cuota, pide rebaja y el lote queda saldado', function (): void {
    $this->pagos->cobrarCuotas(
        venta: $this->venta,
        lote: $this->dos,
        cliente: $this->cliente,
        monto: new Monto('25000.00'),
        forma: FormaDePago::Efectivo,
    );

    expect(loQueDebeElLote($this->dos))->toBeMonto('275000.00');

    $recibo = prontoPagoDe($this->venta, $this->cliente, [['lote' => $this->dos, 'descuento' => new Monto('25000.00')]])[0];

    expect(loQueDebeElLote($this->dos))->toBeMonto('0.00')
        ->and(loQueSePerdonoDelLote($this->dos))->toBeMonto('25000.00');

    // El papel dice que se perdonaron 25,000 y que entraron 250,000.
    expect($recibo->capitalCondonado())->toBeMonto('25000.00')
        ->and($recibo->tuvoDescuento())->toBeTrue();
});

/*
| 🔴 EL TEST QUE JUSTIFICA EL ARCHIVO.
|
| El lote debía 275,000 y el cliente entregó 250,000. Si el descuento se
| cuela en `recibos.monto`, el corte de caja del día va a decir que entraron
| 25,000 que nadie contó.
*/
test('la caja recibe SOLO lo que el cliente entregó', function (): void {
    $this->pagos->cobrarCuotas(
        venta: $this->venta,
        lote: $this->dos,
        cliente: $this->cliente,
        monto: new Monto('25000.00'),
        forma: FormaDePago::Efectivo,
    );

    $recibo = prontoPagoDe($this->venta, $this->cliente, [['lote' => $this->dos, 'descuento' => new Monto('25000.00')]])[0];

    expect($recibo->montoTotal())->toBeMonto('250000.00');
});

test('sin descuento también sirve: es saldar el lote de una vez', function (): void {
    $recibo = prontoPagoDe($this->venta, $this->cliente, [['lote' => $this->uno, 'descuento' => Monto::cero()]])[0];

    expect($recibo->montoTotal())->toBeMonto('300000.00')
        ->and(loQueDebeElLote($this->uno))->toBeMonto('0.00')
        ->and($recibo->tuvoDescuento())->toBeFalse();
});

test('el lote de al lado no se toca', function (): void {
    prontoPagoDe($this->venta, $this->cliente, [['lote' => $this->dos, 'descuento' => new Monto('50000.00')]]);

    expect(loQueDebeElLote($this->uno))->toBeMonto('300000.00')
        ->and(loQueSePerdonoDelLote($this->uno))->toBeMonto('0.00');
});

test('los dos lotes de una sola vez, cada uno con su descuento', function (): void {
    $recibo = prontoPagoDe($this->venta, $this->cliente, [
        ['lote' => $this->uno, 'descuento' => new Monto('10000.00')],
        ['lote' => $this->dos, 'descuento' => new Monto('40000.00')],
    ])[0];

    // 600,000 de saldo menos 50,000 de descuento.
    expect($recibo->montoTotal())->toBeMonto('550000.00')
        ->and($recibo->capitalCondonado())->toBeMonto('50000.00')
        ->and(loQueSePerdonoDelLote($this->uno))->toBeMonto('10000.00')
        ->and(loQueSePerdonoDelLote($this->dos))->toBeMonto('40000.00');
});

test('con los dos lotes saldados, el expediente se cierra solo', function (): void {
    prontoPagoDe($this->venta, $this->cliente, [
        ['lote' => $this->uno, 'descuento' => new Monto('10000.00')],
        ['lote' => $this->dos, 'descuento' => new Monto('40000.00')],
    ]);

    $this->venta->refresh();

    expect($this->venta->getAttribute('estado'))->toBe(EstadoVenta::Liquidada)
        ->and($this->venta->getAttribute('cerrada_el'))->not->toBeNull();
});

/*
| El dinero salda desde la cuota más vieja y el perdón cubre la cola. Con
| 300,000 de saldo y 50,000 de descuento, las diez primeras cuotas las paga
| el cliente y las dos últimas quedan perdonadas enteras.
*/
test('el dinero va a las cuotas más viejas; el perdón, a la cola', function (): void {
    prontoPagoDe($this->venta, $this->cliente, [['lote' => $this->uno, 'descuento' => new Monto('50000.00')]]);

    $cuotas = Cuota::query()
        ->where('compromiso_id', $this->uno->getKey())
        ->orderBy('numero')
        ->get();

    expect($cuotas->firstOrFail()->capitalCondonado())->toBeMonto('0.00')
        ->and($cuotas->firstOrFail()->pagadoEnDinero())->toBeMonto('25000.00')
        ->and($cuotas->last()?->capitalCondonado())->toBeMonto('25000.00')
        ->and($cuotas->last()?->pagadoEnDinero())->toBeMonto('0.00');
});

describe('Lo que se rechaza', function (): void {
    test('sin motivo no hay descuento', function (): void {
        expect(fn (): array => prontoPagoDe(
            $this->venta,
            $this->cliente,
            [['lote' => $this->uno, 'descuento' => new Monto('10000.00')]],
            motivo: '   ',
        ))->toThrow(PagoInvalidoException::class);

        expect(loQueDebeElLote($this->uno))->toBeMonto('300000.00');
    });

    test('no se puede perdonar más de lo que se debe', function (): void {
        expect(fn (): array => prontoPagoDe($this->venta, $this->cliente, [
            ['lote' => $this->uno, 'descuento' => new Monto('300000.01')],
        ]))->toThrow(PagoInvalidoException::class);
    });

    /*
    | Perdonar el saldo entero no es un pronto pago: es una donación, y esa es
    | otra operación con otro permiso. Además el recibo de L 0.00 no existe.
    */
    test('perdonarlo todo no es un pronto pago', function (): void {
        expect(fn (): array => prontoPagoDe($this->venta, $this->cliente, [
            ['lote' => $this->uno, 'descuento' => new Monto('300000.00')],
        ]))->toThrow(PagoInvalidoException::class);

        expect(loQueDebeElLote($this->uno))->toBeMonto('300000.00');
    });

    test('el mismo lote dos veces en la misma operación', function (): void {
        expect(fn (): array => prontoPagoDe($this->venta, $this->cliente, [
            ['lote' => $this->uno, 'descuento' => new Monto('10000.00')],
            ['lote' => $this->uno, 'descuento' => new Monto('20000.00')],
        ]))->toThrow(PagoInvalidoException::class);
    });

    /*
    | Sale con concepto `AbonoCapital` porque dio por terminado un plan, y
    | `anular()` rechaza esos por lo mismo que rechaza un abono. Revertir un
    | pronto pago es otro trámite, y todavía no existe.
    */
    test('un pronto pago no se anula', function (): void {
        $recibo = prontoPagoDe($this->venta, $this->cliente, [['lote' => $this->uno, 'descuento' => new Monto('10000.00')]])[0];

        expect($recibo->getAttribute('concepto'))->toBe(ConceptoDeRecibo::AbonoCapital);

        expect(fn () => $this->pagos->anular($recibo, 'Me equivoqué de lote'))
            ->toThrow(PagoInvalidoException::class);
    });
});

/*
| Dentro de dos años, la única forma de contestar por qué a este cliente se
| le descontaron esos lempiras. Sale en la pestaña «Actualizaciones» del
| expediente, que solo ve el super_admin.
*/
test('el descuento queda asentado con su motivo y quién lo dio', function (): void {
    prontoPagoDe(
        $this->venta,
        $this->cliente,
        [['lote' => $this->uno, 'descuento' => new Monto('10000.00')]],
        motivo: 'Cliente de años, cancela los dos lotes juntos',
    );

    $asiento = Activity::query()->where('event', 'pronto_pago')->latest('id')->firstOrFail();

    expect($asiento->getAttribute('subject_id'))->toBe($this->venta->getKey())
        ->and($asiento->properties->get('motivo'))->toBe('Cliente de años, cancela los dos lotes juntos')
        // 🔴 En `attribute_changes` y no en `properties`: es lo que pinta la
        // pestaña. En el otro lado quedaría guardado donde nadie lo lee.
        ->and($asiento->attribute_changes?->get('attributes'))->toBeArray()
        ->and($asiento->getAttribute('causer_id'))->not->toBeNull();
});

test('sin descuento no se ensucia la bitácora', function (): void {
    prontoPagoDe($this->venta, $this->cliente, [['lote' => $this->uno, 'descuento' => Monto::cero()]]);

    expect(Activity::query()->where('event', 'pronto_pago')->count())->toBe(0);
});
