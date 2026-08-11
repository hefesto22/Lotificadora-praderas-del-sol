<?php

declare(strict_types=1);

use App\Domain\Enums\ConceptoDeRecibo;
use App\Domain\Enums\FormaDePago;
use App\Domain\Enums\ModalidadDeReprogramacion;
use App\Domain\ValueObjects\Monto;
use App\Domain\Ventas\PrecioPactado;
use App\Domain\Ventas\RegistroDeVentas;
use App\Filament\Resources\Ventas\Pages\ViewVenta;
use App\Filament\Support\ModoDeCobro;
use App\Models\Bloque;
use App\Models\Cliente;
use App\Models\Cuota;
use App\Models\Lote;
use App\Models\Proyecto;
use App\Models\Recibo;
use App\Models\Reprogramacion;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| «Ambas» con varios lotes — las cuotas de todos, el sobrante a uno
|--------------------------------------------------------------------------
| Lo pidió Mauricio el 10-ago-2026: «acá también debe de poderse, la cuota de
| los lotes que tenga y si también quiere hacer abono a capital en el mismo
| coso». Y al preguntarle cómo se reparte: «se selecciona como cuota o abono a
| capital; en caso de que traiga para dos cuotas y sobre, se le abona como
| capital a un lote seleccionable».
|
| O sea: se teclea UNA vez el total recibido, se marcan las cuotas que cubre, y
| el sobrante baja el capital de UN lote elegido.
|
| 🔴 Este camino respeta la letra de R21 —el abono sigue yendo contra un solo
| lote—, así que no necesita la enmienda R21-bis. Esa es solo para el modo
| «Abono a capital», que sí reparte.
|
| Dos lotes de 250 vr² a L 1,400.00: L 350,000.00 cada uno, L 50,000.00 de
| prima. El primero a 12 meses da cuotas de L 25,000.00; el segundo a 24 da
| L 12,500.00. Saldo del contrato: L 600,000.00.
*/

beforeEach(function (): void {
    actingAsAdmin();

    $proyecto = Proyecto::factory()->create(['codigo' => 'RPS']);
    $bloque = Bloque::factory()->create(['proyecto_id' => $proyecto->getKey(), 'nombre' => 'A']);

    $uno = Lote::factory()->enBloque($bloque)->conMedidas('250.0000', '1400.00')->create(['numero' => '1']);
    $dos = Lote::factory()->enBloque($bloque)->conMedidas('250.0000', '1400.00')->create(['numero' => '2']);

    $this->cliente = Cliente::factory()->create(['nombre' => 'Leticia Romero']);

    $condicion = static fn (Lote $lote, int $meses): PrecioPactado => new PrecioPactado(
        loteId: (int) $lote->getKey(),
        precioVara: new Monto('1400.00'),
        plazoMeses: $meses,
        prima: new Monto('50000.00'),
    );

    $this->venta = app(RegistroDeVentas::class)->activar(
        proyecto: $proyecto,
        lotes: [$uno, $dos],
        clientes: [$this->cliente],
        prima: new Monto('100000.00'),
        plazoMeses: 12,
        diaPago: 5,
        precios: [$condicion($uno, 12), $condicion($dos, 24)],
    );

    $this->primerLote = $this->venta->compromisos()->orderBy('lote_id')->firstOrFail();
    $this->segundoLote = $this->venta->compromisos()->orderByDesc('lote_id')->firstOrFail();

    $this->expediente = fn (): object => Livewire::test(
        ViewVenta::class,
        ['record' => $this->venta->getKey()],
    );

    /*
     * El cliente entrega L 112,500.00: la cuota del mes de los dos lotes
     * (25,000 + 12,500 = 37,500) y L 75,000.00 que bajan el capital del
     * primero. El sobrante NO se teclea — sale de restar.
     */
    $this->pago = fn (array $extra = []): array => array_merge([
        'modo'        => ModoDeCobro::Ambas->value,
        'monto_total' => '112500.00',

        'cobrar_'.$this->primerLote->getKey()  => true,
        'monto_'.$this->primerLote->getKey()   => '25000.00',
        'cobrar_'.$this->segundoLote->getKey() => true,
        'monto_'.$this->segundoLote->getKey()  => '12500.00',

        'compromiso_id' => $this->primerLote->getKey(),
        'modalidad'     => ModalidadDeReprogramacion::AcortarPlazo->value,

        'forma_pago' => FormaDePago::Efectivo->value,
        'fecha'      => today()->toDateString(),
        'motivo'     => 'Pagó los dos meses y abonó al primero',
    ], $extra);

    $this->recibosNuevos = fn (): int => Recibo::query()
        ->whereNotIn('concepto', [ConceptoDeRecibo::Prima, ConceptoDeRecibo::Senia])
        ->count();
});

/*
| El test del pedido. Un billete de L 112,500.00 y un papel:
|
|   - lote 1: se cobra la cuota 1 (25,000) y quedan las 2..12 = 275,000;
|     el sobrante de 75,000 las deja en 200,000, que en cuotas de 25,000 son
|     8 exactas → 1 pagada + 8 nuevas = 9 cuotas;
|   - lote 2: solo se cobra su cuota 1. Su plan no se toca.
*/
test('cobra la cuota de los dos lotes y abona el sobrante al primero', function (): void {
    ($this->expediente)()
        ->callAction('cobrar', ($this->pago)())
        ->assertHasNoActionErrors();

    $recibo = Recibo::query()->where('concepto', ConceptoDeRecibo::AbonoCapital)->sole();

    expect(($this->recibosNuevos)())->toBe(1)
        ->and($recibo->montoTotal())->toBeMonto('112500.00')
        // 600,000 − 112,500
        ->and($this->venta->refresh()->saldoPendiente())->toBeMonto('487500.00')
        // El abono va contra UN lote (R21): una sola constancia.
        ->and(Reprogramacion::query()->count())->toBe(1)
        ->and(Reprogramacion::query()->sole()->getAttribute('compromiso_id'))->toBe($this->primerLote->getKey())
        ->and(Reprogramacion::query()->sole()->montoAbonado())->toBeMonto('75000.00');
});

/*
| El desglose del papel. Dos aplicaciones —una cuota de cada lote— y la columna
| `compromiso_id` vacía, porque este recibo tocó dos lotes y ponerle uno sería
| peor que dejarla en blanco (R13).
*/
test('el recibo lleva las dos cuotas y no es de ningún lote', function (): void {
    ($this->expediente)()
        ->callAction('cobrar', ($this->pago)())
        ->assertHasNoActionErrors();

    $recibo = Recibo::query()->where('concepto', ConceptoDeRecibo::AbonoCapital)->sole();

    expect($recibo->aplicaciones()->count())->toBe(2)
        ->and($recibo->getAttribute('compromiso_id'))->toBeNull()
        // El plan del primero se reescribió; el del segundo quedó igual.
        ->and(Cuota::query()->where('compromiso_id', $this->primerLote->getKey())->count())->toBe(9)
        ->and(Cuota::query()->where('compromiso_id', $this->segundoLote->getKey())->count())->toBe(24);
});

/*
| Si el total no pasa de las cuotas marcadas no hay sobrante, y «Ambas» no puede
| cumplir lo que promete. Se rechaza entero —tampoco se cobran las cuotas— y el
| error del dominio sale como notificación: lo que NO puede pasar es un 500 con
| el cliente enfrente.
*/
test('sin sobrante no se registra nada', function (): void {
    ($this->expediente)()
        ->callAction('cobrar', ($this->pago)(['monto_total' => '37500.00']))
        ->assertHasNoActionErrors();

    expect(($this->recibosNuevos)())->toBe(0)
        ->and(Reprogramacion::query()->count())->toBe(0)
        ->and($this->venta->refresh()->saldoPendiente())->toBeMonto('600000.00');
});

/*
| 🔴 El orden importa, y este test lo fija: PRIMERO se cobra, DESPUES se abona.
|
| Al primer lote se le atrasan tres cuotas (75,000 vencidos). Se cobran las tres
| en el mismo trámite y recién el sobrante baja capital. Si el Service abonara
| antes de cobrar, `EfectoDelAbono` vería lo vencido, se lo comería para poner
| al día y no reprogramaría nada.
*/
test('primero cobra lo vencido y después abona, en el mismo recibo', function (): void {
    Cuota::query()
        ->where('compromiso_id', $this->primerLote->getKey())
        ->whereIn('numero', [1, 2, 3])
        ->update(['fecha_vencimiento' => today()->subMonths(2)->toDateString()]);

    ($this->expediente)()
        ->callAction('cobrar', ($this->pago)([
            // 75,000 de las tres vencidas + 12,500 del segundo + 75,000 a capital
            'monto_total'                        => '162500.00',
            'monto_'.$this->primerLote->getKey() => '75000.00',
        ]))
        ->assertHasNoActionErrors();

    // 300,000 − 75,000 cobrados = 225,000, menos 75,000 de capital = 150,000,
    // que en cuotas de 25,000 son 6 exactas → 3 pagadas + 6 nuevas.
    expect(Reprogramacion::query()->count())->toBe(1)
        ->and(Reprogramacion::query()->sole()->montoAbonado())->toBeMonto('75000.00')
        ->and(Cuota::query()->where('compromiso_id', $this->primerLote->getKey())->count())->toBe(9)
        ->and($this->venta->refresh()->saldoPendiente())->toBeMonto('437500.00');
});

/*
| Un lote que no se marca no se cobra. Su cuota queda para el mes que viene y su
| plan no se toca, aunque el sobrante vaya al otro lote.
*/
test('el lote que no se marca no se cobra', function (): void {
    ($this->expediente)()
        ->callAction('cobrar', ($this->pago)([
            'cobrar_'.$this->segundoLote->getKey() => false,
            // 25,000 de la cuota del primero + 75,000 a capital
            'monto_total' => '100000.00',
        ]))
        ->assertHasNoActionErrors();

    expect(Recibo::query()->where('concepto', ConceptoDeRecibo::AbonoCapital)->sole()->aplicaciones()->count())->toBe(1)
        ->and(Cuota::query()->where('compromiso_id', $this->segundoLote->getKey())->sum('monto_pagado'))->toBe('0.00')
        // 600,000 − 100,000
        ->and($this->venta->refresh()->saldoPendiente())->toBeMonto('500000.00');
});
