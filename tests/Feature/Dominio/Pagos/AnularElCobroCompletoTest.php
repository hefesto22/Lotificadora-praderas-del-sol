<?php

declare(strict_types=1);

use App\Domain\Enums\FormaDePago;
use App\Domain\Enums\ModalidadDeReprogramacion;
use App\Domain\Exceptions\PagoInvalidoException;
use App\Domain\Pagos\RegistroDePagos;
use App\Domain\ValueObjects\Monto;
use App\Domain\Ventas\PrecioPactado;
use App\Domain\Ventas\RegistroDeVentas;
use App\Models\Bloque;
use App\Models\Cliente;
use App\Models\Compromiso;
use App\Models\Cuota;
use App\Models\Lote;
use App\Models\Proyecto;
use App\Models\Recibo;

/*
|--------------------------------------------------------------------------
| Anular TODO el cobro, cuando salió en varios papeles — 11-sep-2026
|--------------------------------------------------------------------------
| «Cuando tiene más de un titular de recibo y a cada uno se le hizo un abono o
| pago de cuota y generó varios recibos, ¿cómo se maneja eso?» — Mauricio.
|
| Un cobro se parte en un recibo POR TITULAR, así que el contrato con varios
| representados emite varios papeles de un solo pago. Se podían anular de a uno
| —cada papel toca sus propios lotes, así que no se estorban— y ese era el
| problema: cuatro veces el mismo trámite, y quien anula tres y se olvida del
| cuarto deja el expediente a medias.
|
| Tres lotes de 250 vr² a L 1,400.00 = L 350,000.00 cada uno, L 50,000.00 de
| prima, 12 meses: cuotas de L 25,000.00. Dos titulares de recibo distintos, así
| que un cobro de los tres sale en DOS papeles.
*/

beforeEach(function (): void {
    actingAsAdmin();

    $this->pagos = app(RegistroDePagos::class);

    $proyecto = Proyecto::factory()->create(['codigo' => 'RPS']);
    $bloque = Bloque::factory()->create(['proyecto_id' => $proyecto->getKey(), 'nombre' => 'A']);

    $lotes = [];

    foreach (['1', '2', '3'] as $numero) {
        $lotes[] = Lote::factory()->enBloque($bloque)->conMedidas('250.0000', '1400.00')->create(['numero' => $numero]);
    }

    $this->cliente = Cliente::factory()->create(['nombre' => 'MARIA EVELINA CABALLERO']);

    $condicion = static fn (Lote $l): PrecioPactado => new PrecioPactado(
        loteId: (int) $l->getKey(),
        precioVara: new Monto('1400.00'),
        plazoMeses: 12,
        prima: new Monto('50000.00'),
    );

    $this->venta = app(RegistroDeVentas::class)->activar(
        proyecto: $proyecto,
        lotes: $lotes,
        clientes: [$this->cliente],
        prima: new Monto('150000.00'),
        plazoMeses: 12,
        diaPago: 5,
        precios: array_map($condicion, $lotes),
    );

    $renglon = fn (Lote $lote): Compromiso => $this->venta
        ->compromisos()
        ->where('lote_id', '=', $lote->getKey())
        ->firstOrFail();

    $this->uno = $renglon($lotes[0]);
    $this->dos = $renglon($lotes[1]);
    $this->tres = $renglon($lotes[2]);

    // Dos titulares: el primero por su cuenta, los otros dos juntos.
    $this->uno->update(['titular_recibo' => 'JOSE ANTONIO MEJIA']);

    $this->cobroDeLosTres = fn (): array => $this->pagos->cobrarVariosLotes(
        venta: $this->venta,
        cliente: $this->cliente,
        renglones: [
            ['lote' => $this->uno, 'monto' => new Monto('25000.00')],
            ['lote' => $this->dos, 'monto' => new Monto('25000.00')],
            ['lote' => $this->tres, 'monto' => new Monto('25000.00')],
        ],
        forma: FormaDePago::Efectivo,
    );

    $this->pagadoDe = fn (Compromiso $lote): string => (string) Cuota::query()
        ->where('compromiso_id', $lote->getKey())
        ->orderBy('numero')
        ->value('monto_pagado');
});

/*
| Lo primero: que los papeles sepan que salieron juntos. Sin eso, «anular todo
| el cobro» sería adivinar — un cobro de la mañana y otro de la tarde sobre el
| mismo contrato comparten contrato, fecha y quién los emitió.
*/
test('los papeles de un mismo cobro comparten emisión', function (): void {
    $recibos = ($this->cobroDeLosTres)();

    expect($recibos)->toHaveCount(2);

    $emisiones = array_map(
        static fn (Recibo $papel): mixed => $papel->getAttribute('emision_id'),
        $recibos,
    );

    expect($emisiones[0])->not->toBeNull()
        ->and($emisiones[0])->toBe($emisiones[1])
        ->and($recibos[0]->hermanosDeEmision()->pluck('id')->all())->toBe([$recibos[1]->getKey()]);
});

/*
| La señal de la lista: «este papel no vino solo».
|
| Se descartó mostrar el cobro como UNA fila —rompía el libro de correlativos,
| la búsqueda por número y la línea de cada titular; está escrito en
| `RecibosTable::conCuantosSalio()`— y en su lugar cada fila dice con cuántos
| salió.
|
| ⚠️ La lista lo cuenta con un SUBQUERY a mano y no con `withCount()` de una
| relación. Se intentó con `hasMany(self::class, 'emision_id', 'emision_id')` y
| rompió siete tests de pantalla: esa relación apunta a su propia tabla,
| Eloquent la renombra a un alias y le hace `setTable()` al modelo, y Filament
| clona esa consulta varias veces. El porqué completo está en
| `RecibosTable::configure()`; acá se prueba lo que ese subquery cuenta.
*/
test('los papeles del cobro se cuentan entre sí', function (): void {
    $recibos = ($this->cobroDeLosTres)();

    // Uno más el hermano: es lo que la lista lee como «2 papeles del mismo cobro».
    expect($recibos[0]->hermanosDeEmision())->toHaveCount(1)
        ->and($recibos[1]->hermanosDeEmision())->toHaveCount(1)
        ->and($recibos[0]->salioConOtros())->toBeTrue();
});

/*
| 🔴 Y un recibo SIN emisión no encuentra a nadie, ni a sí mismo.
|
| Es lo que hace que los recibos anteriores al 11-sep —y los cobros de un solo
| papel— no digan nada en la lista, en vez de decir «1 papel del mismo cobro»,
| que sería ruido en el 99 % de las filas.
*/
test('un recibo sin emisión no cuenta ni consigo mismo', function (): void {
    $recibo = $this->pagos->cobrarCuotas(
        venta: $this->venta,
        lote: $this->dos,
        cliente: $this->cliente,
        monto: new Monto('25000.00'),
        forma: FormaDePago::Efectivo,
    );

    expect($recibo->getAttribute('emision_id'))->toBeNull()
        ->and($recibo->hermanosDeEmision())->toHaveCount(0)
        ->and($recibo->salioConOtros())->toBeFalse();
});

/*
| ⚠️ Un recibo solo NO lleva emisión. Darle una propia haría que la pantalla
| ofrezca «anular todo el cobro» para anular exactamente uno.
*/
test('un cobro de un solo papel no se marca', function (): void {
    $recibo = $this->pagos->cobrarCuotas(
        venta: $this->venta,
        lote: $this->dos,
        cliente: $this->cliente,
        monto: new Monto('25000.00'),
        forma: FormaDePago::Efectivo,
    );

    expect($recibo->getAttribute('emision_id'))->toBeNull()
        ->and($recibo->salioConOtros())->toBeFalse();
});

test('anular el cobro anula los dos papeles y devuelve lo aplicado', function (): void {
    $recibos = ($this->cobroDeLosTres)();

    $anulados = $this->pagos->anularElCobro($recibos[0]->refresh(), 'El pago era de otro contrato');

    expect($anulados)->toHaveCount(2);

    foreach ($anulados as $papel) {
        expect($papel->estaAnulado())->toBeTrue();
    }

    // Los tres lotes volvieron a deber su cuota.
    expect(($this->pagadoDe)($this->uno))->toBe('0.00')
        ->and(($this->pagadoDe)($this->dos))->toBe('0.00')
        ->and(($this->pagadoDe)($this->tres))->toBe('0.00');
});

/*
| ⚠️ ANULAR UNA CUOTA **NO** EXIGE SER EL ULTIMO MOVIMIENTO, y está bien.
|
| La regla del «último movimiento» es del abono, porque devolver el plan viejo
| choca con lo que vino después. Una cuota no devuelve ningún plan: devuelve lo
| pagado a cuotas que siguen existiendo —una cuota con pago NUNCA se reemplaza,
| por el tope de `EfectoDelAbono`— así que un abono posterior no la estorba.
|
| Este test nació de una corrida fallida: el primer intento de probar «todos o
| ninguno» puso un abono después de un cobro de cuotas esperando que lo
| bloqueara, y no lo bloqueó — porque no tiene por qué.
*/
test('una cuota se anula aunque después haya habido un abono', function (): void {
    $recibos = ($this->cobroDeLosTres)();

    $this->pagos->abonarACapital(
        venta: $this->venta,
        lote: $this->dos,
        cliente: $this->cliente,
        monto: new Monto('50000.00'),
        modalidad: ModalidadDeReprogramacion::AcortarPlazo,
        motivo: 'Abono posterior',
        forma: FormaDePago::Efectivo,
    );

    $this->pagos->anularElCobro($recibos[0]->refresh(), 'El pago era de otro contrato');

    foreach ($recibos as $papel) {
        expect($papel->refresh()->estaAnulado())->toBeTrue();
    }
});

/*
| 🔴🔴 TODOS O NINGUNO.
|
| Un cobro de ABONOS que salió en dos papeles, y después un cobro de cuota sobre
| el lote del segundo. Ese segundo papel ya no se puede deshacer —devolver su
| plan viejo dejaría el pago posterior apuntando a una cuota que deja de
| existir— así que se cae la operación entera.
|
| Media anulación deja el contrato en un estado que no es ni el de antes ni el
| de después, y que nadie pidió.
|
| ⚠️ Se prueba con el ESTADO después del error, no solo con la excepción: una
| transacción mal puesta lanzaría igual habiendo dejado el primero anulado, y
| el test pasaría mintiendo.
*/
test('si uno no se puede anular, no se anula ninguno', function (): void {
    $abonos = $this->pagos->abonarAVariosLotes(
        venta: $this->venta,
        cliente: $this->cliente,
        renglones: [
            ['lote' => $this->uno, 'monto' => new Monto('50000.00'), 'modalidad' => ModalidadDeReprogramacion::AcortarPlazo],
            ['lote' => $this->dos, 'monto' => new Monto('50000.00'), 'modalidad' => ModalidadDeReprogramacion::AcortarPlazo],
        ],
        motivo: 'Abono de los dos representados',
        forma: FormaDePago::Efectivo,
    );

    // Dos titulares, dos papeles: es el caso del pedido.
    expect($abonos)->toHaveCount(2);

    // Y ahora una cuota del plan NUEVO del segundo lote: el abono que lo
    // escribió deja de ser el último movimiento de ese lote.
    $this->pagos->cobrarCuotas(
        venta: $this->venta,
        lote: $this->dos,
        cliente: $this->cliente,
        monto: new Monto('25000.00'),
        forma: FormaDePago::Efectivo,
    );

    expect(fn (): array => $this->pagos->anularElCobro($abonos[0]->refresh(), 'Todo mal'))
        ->toThrow(PagoInvalidoException::class);

    // 🔴 Lo que de verdad importa: NINGUNO quedó anulado.
    foreach ($abonos as $papel) {
        expect($papel->refresh()->estaAnulado())->toBeFalse();
    }
});

/*
| Y se puede seguir anulando de a uno: el cobro completo es una comodidad, no
| un reemplazo. El papel del otro titular no tiene por qué caer con él.
*/
test('anular uno solo deja al hermano en pie', function (): void {
    $recibos = ($this->cobroDeLosTres)();

    $this->pagos->anular($recibos[0]->refresh(), 'Solo este estaba mal');

    expect($recibos[0]->refresh()->estaAnulado())->toBeTrue()
        ->and($recibos[1]->refresh()->estaAnulado())->toBeFalse();
});
