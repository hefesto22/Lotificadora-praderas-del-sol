<?php

declare(strict_types=1);

use App\Domain\Enums\EstadoLote;
use App\Domain\Enums\EstadoVenta;
use App\Domain\Exceptions\VentaInvalidaException;
use App\Domain\ValueObjects\Monto;
use App\Domain\Ventas\PrecioPactado;
use App\Domain\Ventas\RegistroDeVentas;
use App\Models\Bloque;
use App\Models\Cliente;
use App\Models\Cuota;
use App\Models\Lote;
use App\Models\Proyecto;
use App\Models\Recibo;

/*
|--------------------------------------------------------------------------
| La venta AL CONTADO — 14-ago-2026
|--------------------------------------------------------------------------
| Encontrada en pantalla por Mauricio: marcar «Al Contado» y firmar tiraba
| «El plazo de 0 meses no es valido: tiene que ser de 1 a 600 meses», y una
| venta de contado no se podía hacer por ninguna pantalla.
|
| El dominio nunca estuvo roto —`PlanDeCuotas::nuevo()` devuelve un plan
| vacío cuando el saldo da cero— pero NADIE lo probaba de punta a punta, así
| que el agujero de la pantalla vivió sin que ningún test lo notara.
|
| Al contado la prima ES el valor: se paga entero el día que se firma, que es
| exactamente lo que dice R5 —la venta nace vigente cuando la prima se paga
| completa—. 250 vr² × L 1,400.00 = L 350,000.00.
*/

beforeEach(function (): void {
    $this->registro = app(RegistroDeVentas::class);

    $this->proyecto = Proyecto::factory()->create(['codigo' => 'RPS']);
    $this->bloque = Bloque::factory()->create(['proyecto_id' => $this->proyecto->getKey(), 'nombre' => 'A']);

    $this->lote = Lote::factory()->enBloque($this->bloque)->conMedidas('250.0000', '1400.00')->create(['numero' => '1']);
    $this->cliente = Cliente::factory()->create(['nombre' => 'Leticia Romero']);

    $this->deContado = fn (): mixed => $this->registro->activar(
        proyecto: $this->proyecto,
        lotes: [$this->lote],
        clientes: [$this->cliente],
        prima: new Monto('350000.00'),
        plazoMeses: 0,
        diaPago: 5,
        precios: [new PrecioPactado(
            loteId: (int) $this->lote->getKey(),
            precioVara: new Monto('1400.00'),
            plazoMeses: 0,
            prima: new Monto('350000.00'),
        )],
    );
});

test('se puede vender un lote al contado', function (): void {
    $venta = ($this->deContado)();

    expect($venta->esDeContado())->toBeTrue()
        ->and($this->lote->refresh()->getAttribute('estado'))->toBe(EstadoLote::Vendido);
});

/*
| 🔴 23-ago-2026, visto por Mauricio en la tabla de expedientes: «los que
| fueron de contado deberían de estar liquidados, ya fueron pagados en su
| totalidad así que no tiene lógica que sigan vigentes».
|
| Se quedaban vigentes porque el único que asignaba `Liquidada` era el cobro
| de una cuota, y al contado no hay ninguna cuota que cobrar. El expediente
| ofrecía el botón de cobrar sobre un contrato que no debe un centavo.
|
| El lote NO se suelta: `EstadoVenta::ocupaLosLotes()` cuenta la liquidada.
| El cliente terminó de pagar, el lote es suyo.
*/
test('nace liquidada, no vigente: se pagó entera el día que se firmó', function (): void {
    $venta = ($this->deContado)();

    expect($venta->getAttribute('estado'))->toBe(EstadoVenta::Liquidada)
        ->and($venta->getAttribute('cerrada_el'))->not->toBeNull()
        ->and($this->lote->refresh()->getAttribute('estado'))->toBe(EstadoLote::Vendido);

    $this->assertDatabaseHas('ventas', [
        'id'         => $venta->getKey(),
        'estado'     => EstadoVenta::Liquidada->value,
        'cerrada_el' => today()->toDateString(),
    ]);
});

/*
| ⚠️ La condición es «no debe nada», NO «fue de contado». Un contrato puede
| llevar un lote pagado al contado y otro financiado: ese sigue vigente,
| porque las doce cuotas del segundo todavía se cobran. Un `plazo_meses = 0`
| como criterio lo habría cerrado con la cartera por delante.
|
| 350,000 el lote 1 (contado) + 350,000 el lote 2 (50,000 de prima y 300,000
| a 12 meses) = 700,000 de valor y 400,000 de prima.
*/
test('un contrato con un lote al contado y otro financiado sigue vigente', function (): void {
    $financiado = Lote::factory()
        ->enBloque($this->bloque)
        ->conMedidas('250.0000', '1400.00')
        ->create(['numero' => '2']);

    $venta = $this->registro->activar(
        proyecto: $this->proyecto,
        lotes: [$this->lote, $financiado],
        clientes: [$this->cliente],
        prima: new Monto('400000.00'),
        plazoMeses: 12,
        diaPago: 5,
        precios: [
            new PrecioPactado(
                loteId: (int) $this->lote->getKey(),
                precioVara: new Monto('1400.00'),
                plazoMeses: 0,
                prima: new Monto('350000.00'),
            ),
            new PrecioPactado(
                loteId: (int) $financiado->getKey(),
                precioVara: new Monto('1400.00'),
                plazoMeses: 12,
                prima: new Monto('50000.00'),
            ),
        ],
    );

    expect($venta->getAttribute('estado'))->toBe(EstadoVenta::Vigente)
        ->and($venta->getAttribute('cerrada_el'))->toBeNull()
        ->and($venta->saldoPendiente()->redondeado())->toBe('300000.00')
        ->and(Cuota::query()->where('venta_id', $venta->getKey())->count())->toBe(12);
});

/*
| El CHECK `ventas_cuota_segun_plazo_chk` lo dice desde el 4-ago: «una venta
| de contado va con plazo 0 y cuota nula». Esto es ese comentario, probado.
*/
test('no deja cuotas, ni plazo, ni cuota mensual', function (): void {
    $venta = ($this->deContado)();

    expect(Cuota::query()->where('venta_id', $venta->getKey())->count())->toBe(0)
        ->and($venta->getAttribute('plazo_meses'))->toBe(0)
        ->and($venta->getAttribute('cuota_mensual'))->toBeNull();
});

test('el saldo nace en cero: no queda nada por cobrar', function (): void {
    $venta = ($this->deContado)();

    expect($venta->getAttribute('saldo_financiar'))->toBe('0.00')
        ->and($venta->getAttribute('prima'))->toBe('350000.00')
        ->and($venta->getAttribute('valor_total'))->toBe('350000.00')
        ->and($venta->saldoPendiente()->esCero())->toBeTrue();
});

// El papel sale por el valor entero, no por una cuota.
test('emite el recibo de la prima por el valor completo', function (): void {
    $venta = ($this->deContado)();

    $recibo = Recibo::query()
        ->where('venta_id', $venta->getKey())
        ->latest('id')
        ->firstOrFail();

    expect($recibo->montoTotal()->redondeado())->toBe('350000.00');
});

/*
| 🔴 El caso que rompía en pantalla: plazo 0 con prima que NO cubre el valor.
| El motor culpaba al plazo —«tiene que ser de 1 a 600 meses»— cuando el
| problema era la prima. Ahora la pantalla impone la prima al contado, pero el
| dominio tiene que seguir plantándose si alguien llega por otro lado.
*/
test('al contado con prima incompleta se planta', function (): void {
    expect(fn (): mixed => $this->registro->activar(
        proyecto: $this->proyecto,
        lotes: [$this->lote],
        clientes: [$this->cliente],
        prima: Monto::cero(),
        plazoMeses: 0,
        diaPago: 5,
        precios: [new PrecioPactado(
            loteId: (int) $this->lote->getKey(),
            precioVara: new Monto('1400.00'),
            plazoMeses: 0,
            prima: Monto::cero(),
        )],
    ))->toThrow(VentaInvalidaException::class);

    expect($this->lote->refresh()->getAttribute('estado'))->toBe(EstadoLote::Disponible);
});
