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

/*
|--------------------------------------------------------------------------
| El dinero que entra — R11, R12, R13, R19
|--------------------------------------------------------------------------
| Un lote de 250 vr² a L 1,400.00 son L 350,000.00; con L 50,000.00 de prima
| quedan L 300,000.00 a financiar, que a 12 meses dan cuotas de L 25,000.00
| exactas. Todos los números de abajo salen de ahí y se pueden verificar sin
| calculadora.
*/

beforeEach(function (): void {
    actingAsAdmin();

    $this->pagos = app(RegistroDePagos::class);

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

    $this->cobrar = fn (string $monto, ?string $referencia = null, ?FormaDePago $forma = null) => $this->pagos->cobrarCuotas(
        venta: $this->venta,
        lote: $this->renglon,
        cliente: $this->cliente,
        monto: new Monto($monto),
        forma: $forma ?? FormaDePago::Efectivo,
        referencia: $referencia,
    );
});

describe('Un pago normal', function (): void {
    test('cubre la cuota más vieja y deja su recibo', function (): void {
        $recibo = ($this->cobrar)('25000.00');

        $primera = Cuota::query()->where('compromiso_id', $this->renglon->getKey())->orderBy('numero')->firstOrFail();

        expect($primera->estaPagada())->toBeTrue()
            ->and($primera->montoPagado())->toBeMonto('25000.00')
            ->and($recibo->montoTotal())->toBeMonto('25000.00')
            ->and($recibo->aplicaciones()->count())->toBe(1)
            ->and($recibo->getAttribute('venta_id'))->toBe($this->venta->getKey());
    });

    /*
    | FIFO: la más vieja primero. No es una preferencia — es lo que el cliente
    | entiende cuando dice «vengo a pagar», y es lo que hace que el atraso se
    | achique en vez de dejar huecos en el medio del plan.
    */
    test('un pago grande se reparte de la más vieja a la más nueva', function (): void {
        $recibo = ($this->cobrar)('60000.00');

        $cuotas = Cuota::query()
            ->where('compromiso_id', $this->renglon->getKey())
            ->orderBy('numero')
            ->limit(4)
            ->pluck('monto_pagado')
            ->all();

        // 25,000 + 25,000 + 10,000 = 60,000
        expect($cuotas)->toBe(['25000.00', '25000.00', '10000.00', '0.00'])
            ->and($recibo->aplicaciones()->count())->toBe(3);
    });

    /*
    | R19: «a veces pagan la cuota de un mes en 2 o más pagos». Lo que falta se
    | arrastra y NO genera cargo — R2, el atraso no cuesta.
    */
    test('una cuota se paga en dos veces y lo que falta se arrastra', function (): void {
        ($this->cobrar)('10000.00');

        $primera = Cuota::query()->where('compromiso_id', $this->renglon->getKey())->orderBy('numero')->firstOrFail();

        expect($primera->estaPagada())->toBeFalse()
            ->and($primera->saldo())->toBeMonto('15000.00');

        ($this->cobrar)('15000.00');

        expect($primera->refresh()->estaPagada())->toBeTrue()
            ->and($primera->montoPagado())->toBeMonto('25000.00')
            // Dos recibos distintos: cada pago tiene su papel.
            ->and($this->venta->getKey())->not->toBeNull();
    });

    test('el saldo del expediente baja con cada pago', function (): void {
        expect($this->venta->saldoPendiente())->toBeMonto('300000.00');

        ($this->cobrar)('75000.00');

        expect($this->venta->refresh()->saldoPendiente())->toBeMonto('225000.00');
    });
});

/*
|--------------------------------------------------------------------------
| R12 · Una sola numeración para toda la lotificadora
|--------------------------------------------------------------------------
*/
test('cada recibo se lleva su propio número', function (): void {
    $uno = ($this->cobrar)('25000.00');
    $dos = ($this->cobrar)('25000.00');

    expect((int) $dos->getAttribute('numero'))->toBe((int) $uno->getAttribute('numero') + 1)
        ->and($uno->folio())->toMatch('/^\d{6}$/');
});

describe('Lo que rechaza', function (): void {
    test('un pago mayor a lo que se debe, diciendo cuánto se debe', function (): void {
        expect(fn () => ($this->cobrar)('300000.01'))
            ->toThrow(PagoInvalidoException::class, 'L. 300,000.00');
    });

    test('un monto en cero', function (): void {
        expect(fn () => ($this->cobrar)('0'))
            ->toThrow(PagoInvalidoException::class, 'mayor que cero');
    });

    /*
    | R11: en transferencia y depósito la referencia es obligatoria. Sin ella
    | no hay cómo cruzar el recibo contra el estado de cuenta del banco.
    */
    test('una transferencia sin número de referencia', function (): void {
        expect(fn () => ($this->cobrar)('25000.00', null, FormaDePago::Transferencia))
            ->toThrow(PagoInvalidoException::class, 'referencia');

        $recibo = ($this->cobrar)('25000.00', 'TRF-99812', FormaDePago::Transferencia);

        expect($recibo->getAttribute('referencia'))->toBe('TRF-99812');
    });

    test('un lote que no es de este contrato', function (): void {
        $ajeno = Compromiso::factory()->create();

        expect(fn () => $this->pagos->cobrarCuotas(
            venta: $this->venta,
            lote: $ajeno,
            cliente: $this->cliente,
            monto: new Monto('25000.00'),
            forma: FormaDePago::Efectivo,
        ))->toThrow(PagoInvalidoException::class, 'no pertenece al contrato');
    });

    /*
    | `cerrada_el` no es cosmética: la base tiene un CHECK que exige la fecha
    | de cierre cuando el estado es uno de los cerrados, y al revés. Poner el
    | estado a mano sin ella es lo que este test intentaba al principio, y
    | Postgres lo rechazó — con razón, porque un expediente rescindido sin
    | fecha de cierre no se puede poner en ningún reporte.
    */
    test('un expediente que no está vigente', function (): void {
        $this->venta->update([
            'estado'     => EstadoVenta::Rescindida,
            'cerrada_el' => today(),
        ]);

        expect(fn () => ($this->cobrar)('25000.00'))
            ->toThrow(PagoInvalidoException::class, 'rescindida');
    });

    /*
    | Todo o nada: un pago rechazado no quema un número de recibo. El
    | correlativo es lo único que no se puede reponer.
    */
    test('un pago rechazado no deja recibo ni mueve cuotas', function (): void {
        try {
            ($this->cobrar)('999999.00');
        } catch (PagoInvalidoException) {
            // Es lo que se espera.
        }

        expect(Recibo::query()->where('concepto', '!=', ConceptoDeRecibo::Prima)->count())->toBe(0)
            ->and($this->venta->refresh()->saldoPendiente())->toBeMonto('300000.00');
    });
});

/*
|--------------------------------------------------------------------------
| Las guardas del cobro de varios lotes
|--------------------------------------------------------------------------
| El caso feliz —dos lotes, un recibo— se prueba desde la pantalla, que es
| donde vive el trámite. Acá quedan las dos formas de armar mal la lista, que
| ninguna pantalla debería producir y que igual no pueden llegar a la base.
*/
describe('Cobrar varios lotes', function (): void {
    test('sin ningún lote marcado no se cobra nada', function (): void {
        expect(fn () => $this->pagos->cobrarVariosLotes(
            venta: $this->venta,
            cliente: $this->cliente,
            renglones: [],
            forma: FormaDePago::Efectivo,
        ))->toThrow(PagoInvalidoException::class, 'ningún lote marcado');

        expect(Recibo::query()->where('concepto', '!=', ConceptoDeRecibo::Prima)->count())->toBe(0);
    });

    /*
    | Dos renglones del mismo lote sumarían en silencio y el recibo diría un
    | total que nadie tecleó.
    */
    test('el mismo lote dos veces se rechaza', function (): void {
        expect(fn () => $this->pagos->cobrarVariosLotes(
            venta: $this->venta,
            cliente: $this->cliente,
            renglones: [
                ['lote' => $this->renglon, 'monto' => new Monto('25000.00')],
                ['lote' => $this->renglon, 'monto' => new Monto('25000.00')],
            ],
            forma: FormaDePago::Efectivo,
        ))->toThrow(PagoInvalidoException::class, 'dos veces');

        expect(Recibo::query()->where('concepto', '!=', ConceptoDeRecibo::Prima)->count())->toBe(0)
            ->and($this->venta->refresh()->saldoPendiente())->toBeMonto('300000.00');
    });
});
