<?php

declare(strict_types=1);

use App\Domain\Enums\ConceptoDeRecibo;
use App\Domain\Enums\FormaDePago;
use App\Domain\Exceptions\ReimputacionInvalidaException;
use App\Domain\Pagos\RecibosDeCarteraVieja;
use App\Domain\Pagos\RegistroDePagos;
use App\Domain\ValueObjects\Monto;
use App\Domain\Ventas\RegistroDeVentas;
use App\Models\Bloque;
use App\Models\Cliente;
use App\Models\Cuota;
use App\Models\Lote;
use App\Models\Proyecto;
use App\Models\Recibo;
use Spatie\Activitylog\Models\Activity;

/*
|--------------------------------------------------------------------------
| `olympo:acomodar-recibos-viejos` — los PAPELES de la cartera vieja
|--------------------------------------------------------------------------
| «No hay anulados, directamente se borran esas transacciones» y «los recibos
| deben salir a estos nombres» — Mauricio, 21-sep-2026, mirando un expediente
| de dos lotes con UN recibo de prima sin nombre y un anulado que en el
| cuaderno nunca existió.
|
| 🔴 Lo que estos tests cuidan: que ninguna de las dos cosas mueva un saldo, y
| que NINGUNA alcance a un recibo que imprimió el sistema.
|
| Dos lotes de 250 vr² a L 1,400.00 = L 350,000.00 cada uno, L 50,000.00 de
| prima cada uno: un recibo de prima de L 100,000.00, 12 cuotas de L 25,000.00.
*/

beforeEach(function (): void {
    actingAsAdmin();

    $this->papeles = app(RecibosDeCarteraVieja::class);

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
        plazoMeses: 12,
        diaPago: 5,
        formaPrima: FormaDePago::Deposito,
        referenciaPrima: '004300',
        deLaCarteraVieja: true,
    );

    [$this->uno, $this->dos] = $this->venta->compromisos()->with('lote')->orderBy('id')->get()->all();

    // Los nombres se pusieron DESPUES de la carga, que es como pasó.
    $this->uno->update(['titular_recibo' => 'MELVIN RODRIGUEZ LOTE UNO']);
    $this->dos->update(['titular_recibo' => 'MELVIN RODRIGUEZ LOTE DOS']);

    $this->primas = fn () => Recibo::query()
        ->where('venta_id', $this->venta->getKey())
        ->where('concepto', ConceptoDeRecibo::Prima->value)
        ->orderBy('id')
        ->get();

    $this->saldo = fn (): string => $this->venta->fresh()?->saldoPendiente()->redondeado() ?? '';
});

test('la prima queda en un recibo por titular, cada uno con su lote, su nombre y su parte', function (): void {
    $vieja = ($this->primas)()->firstOrFail();
    $saldo = ($this->saldo)();

    $nuevos = $this->papeles->partirLaPrima($this->venta, 'Los recibos salen a nombre de cada lote', escribir: true);

    $primas = ($this->primas)();

    expect($nuevos)->toHaveCount(2)
        ->and($primas)->toHaveCount(2)
        // El recibo único se fue: no queda ni anulado.
        ->and(Recibo::query()->whereKey($vieja->getKey())->exists())->toBeFalse();

    expect($primas[0]->montoTotal())->toBeMonto('50000.00')
        ->and($primas[0]->getAttribute('compromiso_id'))->toBe($this->uno->getKey())
        ->and($primas[0]->getAttribute('a_nombre_de'))->toBe('MELVIN RODRIGUEZ LOTE UNO')
        ->and($primas[1]->montoTotal())->toBeMonto('50000.00')
        ->and($primas[1]->getAttribute('compromiso_id'))->toBe($this->dos->getKey())
        ->and($primas[1]->getAttribute('a_nombre_de'))->toBe('MELVIN RODRIGUEZ LOTE DOS');

    // Todo lo demás del papel viaja igual, y sigue siendo de la serie vieja.
    expect($primas[0]->esDeLaCarteraVieja())->toBeTrue()
        ->and($primas[0]->getAttribute('forma_pago'))->toBe(FormaDePago::Deposito)
        ->and($primas[0]->getAttribute('referencia'))->toBe('004300')
        ->and($primas[0]->getAttribute('fecha')?->format('Y-m-d'))->toBe($vieja->getAttribute('fecha')?->format('Y-m-d'));

    // 🔴 Ni un centavo de saldo se movió.
    expect(($this->saldo)())->toBe($saldo)
        ->and(Activity::query()->where('event', 'prima_partida')->count())->toBe(1);
});

test('el papel de cada prima sale a nombre de su titular', function (): void {
    $this->papeles->partirLaPrima($this->venta, 'Los recibos salen a nombre de cada lote', escribir: true);

    $this->get(route('documentos.recibo', ($this->primas)()[1]))
        ->assertOk()
        ->assertSee('MELVIN RODRIGUEZ LOTE DOS')
        ->assertSee('L. 50,000.00');
});

test('el ensayo de la prima muestra los papeles y no deja nada', function (): void {
    $vieja = ($this->primas)()->firstOrFail();

    $nuevos = $this->papeles->partirLaPrima($this->venta, '', escribir: false);

    expect($nuevos)->toHaveCount(2)
        ->and($nuevos[0]['monto'])->toBeMonto('50000.00')
        ->and(($this->primas)())->toHaveCount(1)
        ->and(Recibo::query()->whereKey($vieja->getKey())->exists())->toBeTrue()
        ->and(Activity::query()->where('event', 'prima_partida')->count())->toBe(0);
});

test('partir dos veces no duplica la prima', function (): void {
    $this->papeles->partirLaPrima($this->venta, 'Los recibos salen a nombre de cada lote', escribir: true);

    expect(fn () => $this->papeles->partirLaPrima($this->venta, 'Otra vez', escribir: true))
        ->toThrow(ReimputacionInvalidaException::class, 'ya tiene 2 recibos de prima');

    expect(($this->primas)())->toHaveCount(2);
});

test('con un solo nombre no hay nada que partir', function (): void {
    $this->dos->update(['titular_recibo' => 'MELVIN RODRIGUEZ LOTE UNO']);

    expect(fn () => $this->papeles->partirLaPrima($this->venta, 'No debería', escribir: true))
        ->toThrow(ReimputacionInvalidaException::class, 'un mismo nombre');

    expect(($this->primas)())->toHaveCount(1);
});

test('un recibo anulado de la cartera vieja se borra, y el saldo no se entera', function (): void {
    $cobro = app(RegistroDePagos::class)->cobrarVariosLotes(
        venta: $this->venta,
        cliente: $this->cliente,
        renglones: [['lote' => $this->uno, 'monto' => new Monto('25000.00')]],
        forma: FormaDePago::Efectivo,
        deLaCarteraVieja: true,
    )[0];

    app(RegistroDePagos::class)->anular($cobro, 'Se cargó al lote equivocado');

    $saldo = ($this->saldo)();

    $this->papeles->borrarElAnulado($this->venta, $cobro->refresh(), 'En el cuaderno nadie anuló nada');

    expect(Recibo::query()->whereKey($cobro->getKey())->exists())->toBeFalse()
        ->and(($this->saldo)())->toBe($saldo)
        ->and(Cuota::query()->where('compromiso_id', $this->uno->getKey())->where('monto_pagado', '>', 0)->count())->toBe(0)
        ->and(Activity::query()->where('event', 'borrado')->where('subject_id', $this->venta->getKey())->count())->toBe(1);
});

test('un recibo VIGENTE no se borra', function (): void {
    $cobro = app(RegistroDePagos::class)->cobrarVariosLotes(
        venta: $this->venta,
        cliente: $this->cliente,
        renglones: [['lote' => $this->uno, 'monto' => new Monto('25000.00')]],
        forma: FormaDePago::Efectivo,
        deLaCarteraVieja: true,
    )[0];

    expect(fn () => $this->papeles->borrarElAnulado($this->venta, $cobro, 'No debería'))
        ->toThrow(ReimputacionInvalidaException::class, 'está vigente');

    expect(Recibo::query()->whereKey($cobro->getKey())->exists())->toBeTrue();
});

/*
| 🔴 EL LIMITE QUE NO SE NEGOCIA: lo que imprimió el sistema no se borra nunca,
| ni anulado. Ese número está en un papel que el cliente se llevó (R12).
*/
test('un recibo que imprimió el sistema no se borra ni estando anulado', function (): void {
    $delSistema = app(RegistroDePagos::class)->cobrarCuotas(
        venta: $this->venta,
        lote: $this->uno,
        cliente: $this->cliente,
        monto: new Monto('25000.00'),
        forma: FormaDePago::Efectivo,
    );

    app(RegistroDePagos::class)->anular($delSistema, 'Error de digitación');

    expect(fn () => $this->papeles->borrarElAnulado($this->venta, $delSistema->refresh(), 'No debería'))
        ->toThrow(ReimputacionInvalidaException::class, 'lo imprimió el sistema');

    expect(Recibo::query()->whereKey($delSistema->getKey())->exists())->toBeTrue();
});

test('el comando hace las dos cosas de una, y pegarlo dos veces no hace daño', function (): void {
    $cobro = app(RegistroDePagos::class)->cobrarVariosLotes(
        venta: $this->venta,
        cliente: $this->cliente,
        renglones: [['lote' => $this->uno, 'monto' => new Monto('25000.00')]],
        forma: FormaDePago::Efectivo,
        deLaCarteraVieja: true,
    )[0];

    app(RegistroDePagos::class)->anular($cobro, 'Se cargó al lote equivocado');

    $pedido = [
        'venta'            => (string) $this->venta->getAttribute('numero_contrato'),
        '--borrar-anulado' => [$cobro->folio()],
        '--partir-prima'   => true,
        '--motivo'         => 'Los recibos salen a nombre de cada lote',
    ];

    $this->artisan('olympo:acomodar-recibos-viejos', [...$pedido, '--ensayo' => true])
        ->expectsOutputToContain('Ensayo: no se escribió nada')
        ->assertSuccessful();

    expect(($this->primas)())->toHaveCount(1)
        ->and(Recibo::query()->whereKey($cobro->getKey())->exists())->toBeTrue();

    $this->artisan('olympo:acomodar-recibos-viejos', $pedido)->assertSuccessful();

    expect(($this->primas)())->toHaveCount(2)
        ->and(Recibo::query()->whereKey($cobro->getKey())->exists())->toBeFalse();

    // La segunda vez: el anulado ya no está (avisa y sigue) y la prima ya está partida.
    $this->artisan('olympo:acomodar-recibos-viejos', $pedido)
        ->expectsOutputToContain('ya tiene 2 recibos de prima')
        ->assertFailed();

    expect(($this->primas)())->toHaveCount(2);
});
