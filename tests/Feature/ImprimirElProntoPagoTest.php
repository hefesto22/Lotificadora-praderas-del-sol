<?php

declare(strict_types=1);

use App\Domain\Enums\FormaDePago;
use App\Domain\Pagos\RegistroDePagos;
use App\Domain\ValueObjects\Monto;
use App\Domain\Ventas\RegistroDeVentas;
use App\Models\Bloque;
use App\Models\Cliente;
use App\Models\Lote;
use App\Models\Proyecto;

/*
|--------------------------------------------------------------------------
| El papel del pronto pago — 21-sep-2026
|--------------------------------------------------------------------------
| «En el recibo, cuando sea pronto pago, no tiene que listar todas las cuotas:
| solo una línea diciendo cuánto pagó, cuánto de descuento y que quedó pagado
| en su totalidad» — Mauricio, mirando un pronto pago de L 200,000.00 que
| salió en TRES páginas de «Cuota 3, Cuota 4, Cuota 5…».
|
| DOS lotes de 250 vr² a L 1,400.00 son L 700,000.00; con L 100,000.00 de
| prima quedan L 600,000.00 a 12 meses: doce cuotas de L 25,000.00 exactas por
| lote. Es el mismo expediente de `ProntoPagoTest`, que prueba el DINERO; acá
| se prueba solo lo que dice el papel.
|
| 🔴 Lo que no puede pasar nunca es que el renglón resumido cambie el total:
| la columna Monto y el total siguen siendo lo que ENTRO, y el descuento se
| dice al lado, no se suma.
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

test('un pronto pago sale en UNA línea: cuánto debía, cuánto se le descontó y cuánto pagó', function (): void {
    // Pagó una cuota, así que el lote debe 275,000 en las cuotas 2 a 12.
    $this->pagos->cobrarCuotas(
        venta: $this->venta,
        lote: $this->dos,
        cliente: $this->cliente,
        monto: new Monto('25000.00'),
        forma: FormaDePago::Efectivo,
    );

    $recibo = $this->pagos->prontoPago(
        venta: $this->venta,
        cliente: $this->cliente,
        renglones: [['lote' => $this->dos, 'descuento' => new Monto('25000.00')]],
        motivo: 'El cliente cancela y pide rebaja',
        forma: FormaDePago::Efectivo,
    )[0];

    $codigo = (string) $this->dos->lote?->getAttribute('codigo');

    $this->get(route('documentos.recibo', $recibo))
        ->assertOk()
        ->assertSee('Pronto pago · cancela el lote '.$codigo)
        ->assertSee('Debía L. 275,000.00')
        ->assertSee('(cuotas 2 a 12)')
        ->assertSee('descuento por pronto pago L. 25,000.00')
        ->assertSee('pagó L. 250,000.00')
        ->assertSee('El lote queda pagado en su totalidad')
        // Las once cuotas que saldó ya no salen una por una.
        ->assertDontSee('Cuota 2')
        ->assertDontSee('Cuota 12');
});

test('el renglón resumido NO mete el descuento en el total', function (): void {
    $recibo = $this->pagos->prontoPago(
        venta: $this->venta,
        cliente: $this->cliente,
        renglones: [['lote' => $this->uno, 'descuento' => new Monto('50000.00')]],
        motivo: 'El cliente cancela y pide rebaja',
        forma: FormaDePago::Efectivo,
    )[0];

    // Debía 300,000; se le perdonaron 50,000; entraron 250,000. La caja se
    // cuadra contra lo que entró, y es el único total que el papel puede decir.
    expect($recibo->montoTotal())->toBeMonto('250000.00');

    $renglones = $recibo->prontoPagoPorLote();

    expect($renglones)->toHaveCount(1)
        ->and($renglones[0]['desde'])->toBe(1)
        ->and($renglones[0]['hasta'])->toBe(12)
        ->and($renglones[0]['debia'])->toBeMonto('300000.00')
        ->and($renglones[0]['descuento'])->toBeMonto('50000.00')
        // Lo que va en la columna Monto: lo que entró, no lo que debía.
        ->and($renglones[0]['pago'])->toBeMonto('250000.00');

    $this->get(route('documentos.recibo', $recibo))
        ->assertOk()
        ->assertSee('Debía L. 300,000.00')
        ->assertSee('no se cobró: el total de este recibo es lo que usted entregó');
});

test('un recibo sin descuento no tiene renglones de pronto pago', function (): void {
    $recibo = $this->pagos->cobrarCuotas(
        venta: $this->venta,
        lote: $this->uno,
        cliente: $this->cliente,
        monto: new Monto('25000.00'),
        forma: FormaDePago::Efectivo,
    );

    expect($recibo->prontoPagoPorLote())->toBe([]);
});

test('dos lotes en un mismo pronto pago salen en dos líneas, cada una con lo suyo', function (): void {
    $recibo = $this->pagos->prontoPago(
        venta: $this->venta,
        cliente: $this->cliente,
        renglones: [
            ['lote' => $this->uno, 'descuento' => new Monto('10000.00')],
            ['lote' => $this->dos, 'descuento' => new Monto('40000.00')],
        ],
        motivo: 'Cancela los dos lotes',
        forma: FormaDePago::Efectivo,
    )[0];

    $this->get(route('documentos.recibo', $recibo))
        ->assertOk()
        ->assertSee('Pronto pago · cancela el lote '.$this->uno->lote?->getAttribute('codigo'))
        ->assertSee('Pronto pago · cancela el lote '.$this->dos->lote?->getAttribute('codigo'))
        ->assertSee('descuento por pronto pago L. 10,000.00')
        ->assertSee('pagó L. 290,000.00')
        ->assertSee('descuento por pronto pago L. 40,000.00')
        ->assertSee('pagó L. 260,000.00')
        ->assertDontSee('Cuota 1');
});

test('si a uno de los dos lotes no se le rebajó nada, su línea no inventa un descuento', function (): void {
    $recibo = $this->pagos->prontoPago(
        venta: $this->venta,
        cliente: $this->cliente,
        renglones: [
            ['lote' => $this->uno, 'descuento' => Monto::cero()],
            ['lote' => $this->dos, 'descuento' => new Monto('40000.00')],
        ],
        motivo: 'Rebaja solo en el segundo lote',
        forma: FormaDePago::Efectivo,
    )[0];

    $this->get(route('documentos.recibo', $recibo))
        ->assertOk()
        ->assertSee('pagó L. 300,000.00')
        ->assertSee('descuento por pronto pago L. 40,000.00')
        ->assertDontSee('descuento por pronto pago L. 0.00');
});

test('cuando lo que faltaba era una sola cuota, el papel dice «cuota» y no un rango', function (): void {
    // Once cuotas pagadas: le queda solo la 12.
    $this->pagos->cobrarCuotas(
        venta: $this->venta,
        lote: $this->uno,
        cliente: $this->cliente,
        monto: new Monto('275000.00'),
        forma: FormaDePago::Efectivo,
    );

    $recibo = $this->pagos->prontoPago(
        venta: $this->venta,
        cliente: $this->cliente,
        renglones: [['lote' => $this->uno, 'descuento' => new Monto('5000.00')]],
        motivo: 'Rebaja en la última cuota',
        forma: FormaDePago::Efectivo,
    )[0];

    $this->get(route('documentos.recibo', $recibo))
        ->assertOk()
        ->assertSee('(cuota 12)')
        ->assertSee('pagó L. 20,000.00');
});

test('un cobro normal sigue listando sus cuotas una por una', function (): void {
    $recibo = $this->pagos->cobrarCuotas(
        venta: $this->venta,
        lote: $this->uno,
        cliente: $this->cliente,
        monto: new Monto('50000.00'),
        forma: FormaDePago::Efectivo,
    );

    $this->get(route('documentos.recibo', $recibo))
        ->assertOk()
        ->assertSee('Cuota 1')
        ->assertSee('Cuota 2')
        ->assertDontSee('Pronto pago');
});
