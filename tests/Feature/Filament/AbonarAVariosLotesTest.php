<?php

declare(strict_types=1);

use App\Domain\Enums\ConceptoDeRecibo;
use App\Domain\Enums\FormaDePago;
use App\Domain\Enums\ModalidadDeReprogramacion;
use App\Domain\ValueObjects\Monto;
use App\Domain\Ventas\PrecioPactado;
use App\Domain\Ventas\RegistroDeVentas;
use App\Filament\Resources\Ventas\Pages\ViewVenta;
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
| Abonar a capital en varios lotes, con UN solo recibo
|--------------------------------------------------------------------------
| El caso que pidió Mauricio el 10-ago-2026, textual: «deberia de poderse a
| mas de un lote, en caso de que tenga mas el cliente: ponle quiere hacer un
| abono a capital de 20000 al lote 1 y 10000 al lote 2, todo en una sola
| transaccion».
|
| 🔴 R21 dice «el abono se aplica A UN LOTE», y lo que rechazaba era que el
| SISTEMA repartiera solo. Acá no reparte nadie: el monto de cada lote —y su
| modalidad— los teclea quien recibe. La enmienda de la letra está anotada en
| `docs/dominio.md`, pendiente de la firma de la contratante.
|
| Dos lotes de 250 vr² a L 1,400.00: L 350,000.00 cada uno. El primero a 12
| meses con L 50,000.00 de prima da cuotas de L 25,000.00; el segundo a 24
| meses con la misma prima da L 12,500.00. Saldo del contrato: L 600,000.00.
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
     * El caso de Mauricio: L 20,000.00 al primero y L 10,000.00 al segundo. Se
     * marcan los dos a mano porque el modal NO propone montos para el abono —a
     * diferencia de la cuota, acá no hay un número esperado.
     */
    $this->reparto = fn (array $extra = []): array => array_merge([
        'abonar_'.$this->primerLote->getKey()    => true,
        'abono_'.$this->primerLote->getKey()     => '20000.00',
        'modalidad_'.$this->primerLote->getKey() => ModalidadDeReprogramacion::AcortarPlazo->value,

        'abonar_'.$this->segundoLote->getKey()    => true,
        'abono_'.$this->segundoLote->getKey()     => '10000.00',
        'modalidad_'.$this->segundoLote->getKey() => ModalidadDeReprogramacion::BajarCuota->value,

        'forma_pago' => FormaDePago::Efectivo->value,
        'fecha'      => today()->toDateString(),
        'motivo'     => 'Abonó a los dos lotes',
    ], $extra);

    $this->recibosNuevos = fn (): int => Recibo::query()
        ->whereNotIn('concepto', [ConceptoDeRecibo::Prima, ConceptoDeRecibo::Senia])
        ->count();
});

/*
| El test del pedido. Un cliente, un billete de L 30,000.00, un papel — y DOS
| constancias, porque son dos planes los que se reescribieron.
*/
test('los dos lotes se abonan en un solo recibo', function (): void {
    ($this->expediente)()
        ->callAction('abonar_a_capital', ($this->reparto)())
        ->assertHasNoActionErrors();

    $recibo = Recibo::query()->where('concepto', ConceptoDeRecibo::AbonoCapital)->sole();

    expect(($this->recibosNuevos)())->toBe(1)
        ->and($recibo->montoTotal())->toBeMonto('30000.00')
        // 600,000 − 30,000. Los dos abonos bajaron capital.
        ->and($this->venta->refresh()->saldoPendiente())->toBeMonto('570000.00')
        ->and(Reprogramacion::query()->count())->toBe(2)
        /*
         * Un recibo de varios lotes no es de ninguno: la columna queda vacía y
         * el desglose es el que lo dice. Es la misma regla que ya seguía
         * `cobrarVariosLotes()` desde el 8-ago.
         */
        ->and($recibo->getAttribute('compromiso_id'))->toBeNull()
        ->and($recibo->reprogramaciones()->count())->toBe(2);
});

/*
| Lo que Mauricio eligió el 10-ago: la modalidad es POR LOTE. Un cliente puede
| querer terminar antes el lote que va a construir y bajar la cuota del otro, y
| R21 dice que esos dos caminos los elige él.
|
| Si algún día alguien la sube a una sola por recibo, este test se cae.
*/
test('cada lote se reprograma con la modalidad que le tocó', function (): void {
    ($this->expediente)()
        ->callAction('abonar_a_capital', ($this->reparto)())
        ->assertHasNoActionErrors();

    $delPrimero = Reprogramacion::query()->where('compromiso_id', $this->primerLote->getKey())->sole();
    $delSegundo = Reprogramacion::query()->where('compromiso_id', $this->segundoLote->getKey())->sole();

    expect($delPrimero->getAttribute('modalidad'))->toBe(ModalidadDeReprogramacion::AcortarPlazo)
        ->and($delPrimero->montoAbonado())->toBeMonto('20000.00')
        ->and($delSegundo->getAttribute('modalidad'))->toBe(ModalidadDeReprogramacion::BajarCuota)
        ->and($delSegundo->montoAbonado())->toBeMonto('10000.00')
        // El segundo mantiene sus 24 meses y baja la cuota; el primero no.
        ->and(Cuota::query()->where('compromiso_id', $this->segundoLote->getKey())->count())->toBe(24);
});

/*
| 🔴 Todo o nada. Si el segundo renglón se pasa de lo que ese lote debe, el
| PRIMERO tampoco se abona y el correlativo no se movió: medio recibo con un
| plan ya reescrito no existe.
|
| Es la misma garantía que da `cobrarVariosLotes()`, y acá pesa más: un cobro
| mal hecho se anula, pero un abono NO se puede anular —reescribió un plan— así
| que la única defensa es que nunca llegue a escribirse.
*/
test('si un lote se pasa, no se abona ninguno', function (): void {
    ($this->expediente)()
        ->callAction('abonar_a_capital', ($this->reparto)([
            'abono_'.$this->segundoLote->getKey() => '999999.00',
        ]))
        ->assertHasNoActionErrors();

    expect(($this->recibosNuevos)())->toBe(0)
        ->and(Reprogramacion::query()->count())->toBe(0)
        ->and($this->venta->refresh()->saldoPendiente())->toBeMonto('600000.00')
        // El primero tampoco se tocó, que es lo que este test existe para fijar.
        ->and(Cuota::query()->where('compromiso_id', $this->primerLote->getKey())->count())->toBe(12);
});

/*
| Un lote al que no le alcanza ni para lo vencido NO tumba a los otros: ese se
| registra como pago normal —el dinero ya está sobre el mostrador— y los demás
| reprograman igual. Es el mismo comportamiento que con un solo lote, y la
| notificación lo explica.
|
| Al segundo lote se le vencen tres cuotas de L 12,500.00 = L 37,500.00, y se
| le abonan L 10,000.00: no alcanza.
*/
test('el lote que no alcanza se registra como pago normal, y el otro reprograma', function (): void {
    Cuota::query()
        ->where('compromiso_id', $this->segundoLote->getKey())
        ->whereIn('numero', [1, 2, 3])
        ->update(['fecha_vencimiento' => today()->subMonths(2)->toDateString()]);

    ($this->expediente)()
        ->callAction('abonar_a_capital', ($this->reparto)())
        ->assertHasNoActionErrors();

    $recibo = Recibo::query()->where('concepto', ConceptoDeRecibo::AbonoCapital)->sole();

    expect($recibo->montoTotal())->toBeMonto('30000.00')
        // Una sola constancia: la del primero. El segundo no reprogramó nada.
        ->and(Reprogramacion::query()->count())->toBe(1)
        ->and(Reprogramacion::query()->sole()->getAttribute('compromiso_id'))->toBe($this->primerLote->getKey())
        // Y el segundo conserva sus 24 cuotas, con la más vieja cobrada.
        ->and(Cuota::query()->where('compromiso_id', $this->segundoLote->getKey())->count())->toBe(24)
        ->and(Cuota::query()->where('compromiso_id', $this->segundoLote->getKey())->where('numero', 1)->value('monto_pagado'))
        ->toBe('10000.00');
});

/*
| Un lote sin marcar no se toca. Suena obvio y es la garantía que R21 pide con
| todas las letras: «le movería números que no pidió tocar».
*/
test('el lote que no se marca queda intacto', function (): void {
    ($this->expediente)()
        ->callAction('abonar_a_capital', ($this->reparto)([
            'abonar_'.$this->segundoLote->getKey() => false,
        ]))
        ->assertHasNoActionErrors();

    expect(Reprogramacion::query()->count())->toBe(1)
        ->and(Cuota::query()->where('compromiso_id', $this->segundoLote->getKey())->count())->toBe(24)
        ->and(Cuota::query()->where('compromiso_id', $this->segundoLote->getKey())->sum('monto_pagado'))->toBe('0.00')
        // 600,000 − 20,000: solo bajó el primero.
        ->and($this->venta->refresh()->saldoPendiente())->toBeMonto('580000.00')
        // Con un solo lote marcado, el recibo vuelve a apuntar a ese lote.
        ->and(Recibo::query()->where('concepto', ConceptoDeRecibo::AbonoCapital)->sole()->getAttribute('compromiso_id'))
        ->toBe($this->primerLote->getKey());
});
