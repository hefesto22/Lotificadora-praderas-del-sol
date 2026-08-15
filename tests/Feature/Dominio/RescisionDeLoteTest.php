<?php

declare(strict_types=1);

use App\Domain\Enums\EstadoCompromiso;
use App\Domain\Enums\EstadoLote;
use App\Domain\Enums\EstadoVenta;
use App\Domain\Enums\FormaDePago;
use App\Domain\Enums\TipoCompromiso;
use App\Domain\Enums\TipoDeDevolucion;
use App\Domain\Exceptions\PagoInvalidoException;
use App\Domain\Exceptions\RescisionInvalidaException;
use App\Domain\Pagos\RegistroDePagos;
use App\Domain\ValueObjects\Monto;
use App\Domain\Ventas\PrecioPactado;
use App\Domain\Ventas\RegistroDeCompromisos;
use App\Domain\Ventas\RegistroDeRescisiones;
use App\Domain\Ventas\RegistroDeVentas;
use App\Models\Bloque;
use App\Models\Cliente;
use App\Models\Cuota;
use App\Models\Lote;
use App\Models\Proyecto;

/*
|--------------------------------------------------------------------------
| R22 · La rescisión es POR LOTE — 14-ago-2026
|--------------------------------------------------------------------------
| «Dio la prima, pagó dos meses y ya no quiere el lote» (la contratante,
| 6-ago). «Si la inmobiliaria no le quiere devolver el dinero puede hacerlo,
| así que eso quedaría como saldo a favor de la inmobiliaria; o si se le
| devuelve una parte, que se registre cuánto fue» (Mauricio, 14-ago).
|
| Los números son los de siempre para que se lean sin calculadora: tres lotes
| de 250 vr² a L 1,400.00 son L 350,000.00 cada uno; con L 50,000.00 de prima
| cada uno quedan L 300,000.00 a financiar, que a 12 meses dan cuotas de
| L 25,000.00 exactas.
*/

beforeEach(function (): void {
    $this->ventas = app(RegistroDeVentas::class);
    $this->rescisiones = app(RegistroDeRescisiones::class);

    $this->proyecto = Proyecto::factory()->create(['codigo' => 'RPS']);
    $this->bloque = Bloque::factory()->create([
        'proyecto_id' => $this->proyecto->getKey(),
        'nombre'      => 'A',
    ]);

    $this->cliente = Cliente::factory()->create(['nombre' => 'Leticia Romero']);

    $this->lote = fn (string $numero): Lote => Lote::factory()
        ->enBloque($this->bloque)
        ->conMedidas('250.0000', '1400.00')
        ->create(['numero' => $numero]);

    $this->condiciones = static fn (Lote $lote): PrecioPactado => new PrecioPactado(
        loteId: (int) $lote->getKey(),
        precioVara: new Monto('1400.00'),
        plazoMeses: 12,
        prima: new Monto('50000.00'),
    );
});

describe('Un lote se cae y el contrato sigue', function (): void {
    beforeEach(function (): void {
        $this->uno = ($this->lote)('1');
        $this->dos = ($this->lote)('2');

        $this->venta = $this->ventas->activar(
            proyecto: $this->proyecto,
            lotes: [$this->uno, $this->dos],
            clientes: [$this->cliente],
            prima: new Monto('100000.00'),
            plazoMeses: 12,
            diaPago: 5,
            precios: [
                ($this->condiciones)($this->uno),
                ($this->condiciones)($this->dos),
            ],
        );

        $this->primero = $this->venta->compromisos()
            ->where('lote_id', $this->uno->getKey())
            ->firstOrFail();
    });

    test('el lote queda rescindido y vuelve al plano', function (): void {
        $this->rescisiones->rescindir(
            lote: $this->primero,
            devuelto: Monto::cero(),
            forma: FormaDePago::Efectivo,
            motivo: 'El cliente ya no quiere el lote.',
        );

        expect($this->primero->refresh()->getAttribute('estado'))->toBe(EstadoCompromiso::Rescindido)
            ->and($this->primero->getAttribute('cerrado_el'))->not->toBeNull()
            ->and($this->uno->refresh()->getAttribute('estado'))->toBe(EstadoLote::Disponible);
    });

    test('sus cuotas pendientes se cancelan y las del otro lote no se tocan', function (): void {
        expect(Cuota::query()->count())->toBe(24);

        $this->rescisiones->rescindir(
            lote: $this->primero,
            devuelto: Monto::cero(),
            forma: FormaDePago::Efectivo,
            motivo: 'Se cayó.',
        );

        expect(Cuota::query()->where('compromiso_id', $this->primero->getKey())->count())->toBe(0)
            ->and(Cuota::query()->count())->toBe(12);
    });

    /*
    | El expediente NO se anula: el cliente se queda con el otro lote, con su
    | mismo número de contrato y su misma historia. Lo que cambia son los
    | totales, que ahora hablan de un solo lote.
    */
    test('el expediente sigue vigente y recalcula sus totales', function (): void {
        $this->rescisiones->rescindir(
            lote: $this->primero,
            devuelto: Monto::cero(),
            forma: FormaDePago::Efectivo,
            motivo: 'Se cayó.',
        );

        $venta = $this->venta->refresh();

        expect($venta->getAttribute('estado'))->toBe(EstadoVenta::Vigente)
            ->and($venta->getAttribute('cerrada_el'))->toBeNull()
            ->and($venta->getAttribute('valor_total'))->toBe('350000.00')
            ->and($venta->getAttribute('prima'))->toBe('50000.00')
            ->and($venta->getAttribute('saldo_financiar'))->toBe('300000.00')
            ->and($venta->getAttribute('cuota_mensual'))->toBe('25000.00')
            ->and($venta->getAttribute('plazo_meses'))->toBe(12);
    });

    /*
    | La pregunta de Mauricio, contestada por el acta: entró tanto, salió
    | tanto, quedó tanto a favor de la lotificadora.
    */
    test('el acta dice cuánto entró, cuánto salió y cuánto quedó', function (): void {
        $acta = $this->rescisiones->rescindir(
            lote: $this->primero,
            devuelto: new Monto('20000.00'),
            forma: FormaDePago::Efectivo,
            motivo: 'Devolución parcial acordada con la administración.',
        );

        expect($acta->tipoDeDevolucion())->toBe(TipoDeDevolucion::Rescision)
            ->and($acta->esRescision())->toBeTrue()
            ->and($acta->montoRecibido()->redondeado())->toBe('50000.00')
            ->and($acta->montoDevuelto()->redondeado())->toBe('20000.00')
            ->and($acta->montoRetenido()->redondeado())->toBe('30000.00')
            ->and($acta->getAttribute('venta_id'))->toBe($this->venta->getKey())
            ->and($acta->getAttribute('compromiso_id'))->toBe($this->primero->getKey());
    });

    /*
    | «Si la inmobiliaria no le quiere devolver el dinero puede hacerlo.»
    */
    test('se puede no devolver nada y todo queda retenido', function (): void {
        $acta = $this->rescisiones->rescindir(
            lote: $this->primero,
            devuelto: Monto::cero(),
            forma: FormaDePago::Efectivo,
            motivo: 'Incumplimiento: no pagó durante seis meses.',
        );

        expect($acta->montoDevuelto()->esCero())->toBeTrue()
            ->and($acta->montoRetenido()->redondeado())->toBe('50000.00')
            ->and($acta->fueTotal())->toBeFalse();
    });

    test('lo que se pagó de cuotas cuenta como recibido', function (): void {
        app(RegistroDePagos::class)->cobrarCuotas(
            venta: $this->venta,
            lote: $this->primero,
            cliente: $this->cliente,
            monto: new Monto('25000.00'),
            forma: FormaDePago::Efectivo,
        );

        $acta = $this->rescisiones->rescindir(
            lote: $this->primero->refresh(),
            devuelto: Monto::cero(),
            forma: FormaDePago::Efectivo,
            motivo: 'Pagó una cuota y se cayó.',
        );

        // La prima del lote más la cuota que sí pagó.
        expect($acta->montoRecibido()->redondeado())->toBe('75000.00');
    });

    /*
    | R21, aplicada acá: una cuota con plata encima tiene aplicaciones de pago
    | colgando y un recibo que el cliente guardó. Se queda.
    */
    test('la cuota ya pagada no se borra', function (): void {
        app(RegistroDePagos::class)->cobrarCuotas(
            venta: $this->venta,
            lote: $this->primero,
            cliente: $this->cliente,
            monto: new Monto('25000.00'),
            forma: FormaDePago::Efectivo,
        );

        $this->rescisiones->rescindir(
            lote: $this->primero->refresh(),
            devuelto: Monto::cero(),
            forma: FormaDePago::Efectivo,
            motivo: 'Se cayó.',
        );

        expect(Cuota::query()->where('compromiso_id', $this->primero->getKey())->count())->toBe(1);
    });

    /*
        | 🔴 LA TRAMPA QUE CASI SE COLA — 14-ago-2026
        |
        | `anular()` devuelve `monto_pagado` a cero y DEJA viva la aplicación de
        | pago, porque es historia. La FK `aplicaciones_de_pago.cuota_id` es
        | `restrictOnDelete`, así que borrar esa cuota —que a los ojos de las
        | columnas parece intacta— reventaría con un 23503 de Postgres en la cara
        | de la administradora, a mitad de la transacción.
        */
    test('una cuota con un pago anulado no tumba la rescisión', function (): void {
        $pagos = app(RegistroDePagos::class);

        $recibo = $pagos->cobrarCuotas(
            venta: $this->venta,
            lote: $this->primero,
            cliente: $this->cliente,
            monto: new Monto('25000.00'),
            forma: FormaDePago::Efectivo,
        );

        $pagos->anular($recibo, 'Cheque rechazado.');

        $acta = $this->rescisiones->rescindir(
            lote: $this->primero->refresh(),
            devuelto: Monto::cero(),
            forma: FormaDePago::Efectivo,
            motivo: 'Se cayó después de que se le anulara el pago.',
        );

        // El pago anulado no cuenta como recibido: solo queda la prima.
        expect($acta->montoRecibido()->redondeado())->toBe('50000.00')
            // Y la cuota sobrevive, porque su aplicación sigue viva.
            ->and(Cuota::query()->where('compromiso_id', $this->primero->getKey())->count())->toBe(1);
    });

    /*
    | La cuota pagada a medias sobrevive a la rescisión —tiene un recibo
    | encima— y por eso conserva saldo. Ese saldo NO puede seguir contando
    | como deuda del expediente: sería cobrarle al cliente por un terreno que
    | ya devolvió.
    */
    test('el saldo del expediente deja de contar el lote rescindido', function (): void {
        app(RegistroDePagos::class)->cobrarCuotas(
            venta: $this->venta,
            lote: $this->primero,
            cliente: $this->cliente,
            monto: new Monto('12500.00'),
            forma: FormaDePago::Efectivo,
        );

        $this->rescisiones->rescindir(
            lote: $this->primero->refresh(),
            devuelto: Monto::cero(),
            forma: FormaDePago::Efectivo,
            motivo: 'Pagó media cuota y se cayó.',
        );

        // Solo el lote que queda: 12 × L 25,000.00. Sin el filtro serían
        // L 312,500.00, con los L 12,500.00 del lote que ya no es suyo.
        expect($this->venta->refresh()->saldoPendiente()->redondeado())->toBe('300000.00');
    });

    /*
    | 🔴 La guarda va en el SERVICE, no en la pantalla. El modal de cobro ya
    | no ofrece el lote rescindido, pero el plano y cualquier pantalla futura
    | entran por el mismo Service, y la cuota que sobrevivió sigue teniendo
    | saldo: sin esto, la ventanilla podría emitirle un recibo numerado a
    | alguien por un terreno que ya devolvió.
    */
    test('a un lote rescindido no se le puede cobrar', function (): void {
        $pagos = app(RegistroDePagos::class);

        $pagos->cobrarCuotas(
            venta: $this->venta,
            lote: $this->primero,
            cliente: $this->cliente,
            monto: new Monto('12500.00'),
            forma: FormaDePago::Efectivo,
        );

        $this->rescisiones->rescindir(
            lote: $this->primero->refresh(),
            devuelto: Monto::cero(),
            forma: FormaDePago::Efectivo,
            motivo: 'Se cayó.',
        );

        expect(fn () => $pagos->cobrarCuotas(
            venta: $this->venta->refresh(),
            lote: $this->primero->refresh(),
            cliente: $this->cliente,
            monto: new Monto('12500.00'),
            forma: FormaDePago::Efectivo,
        ))->toThrow(PagoInvalidoException::class);

        $vivos = $this->venta->compromisos()
            ->where('estado', EstadoCompromiso::Vigente->value)
            ->pluck('lote_id')
            ->all();

        expect($vivos)->toBe([$this->dos->getKey()]);
    });

    test('no se puede devolver más de lo que entró', function (): void {
        expect(fn () => $this->rescisiones->rescindir(
            lote: $this->primero,
            devuelto: new Monto('60000.00'),
            forma: FormaDePago::Efectivo,
            motivo: 'Se cayó.',
        ))->toThrow(RescisionInvalidaException::class);

        expect($this->primero->refresh()->getAttribute('estado'))->toBe(EstadoCompromiso::Vigente);
    });

    test('sin motivo escrito no se rescinde', function (): void {
        expect(fn () => $this->rescisiones->rescindir(
            lote: $this->primero,
            devuelto: Monto::cero(),
            forma: FormaDePago::Efectivo,
            motivo: '   ',
        ))->toThrow(RescisionInvalidaException::class);
    });

    // R11, del lado de la salida.
    test('una devolución por transferencia necesita referencia', function (): void {
        expect(fn () => $this->rescisiones->rescindir(
            lote: $this->primero,
            devuelto: new Monto('10000.00'),
            forma: FormaDePago::Transferencia,
            motivo: 'Se cayó.',
        ))->toThrow(RescisionInvalidaException::class);
    });

    test('un lote ya rescindido no se rescinde dos veces', function (): void {
        $this->rescisiones->rescindir(
            lote: $this->primero,
            devuelto: Monto::cero(),
            forma: FormaDePago::Efectivo,
            motivo: 'Se cayó.',
        );

        expect(fn () => $this->rescisiones->rescindir(
            lote: $this->primero->refresh(),
            devuelto: Monto::cero(),
            forma: FormaDePago::Efectivo,
            motivo: 'Otra vez.',
        ))->toThrow(RescisionInvalidaException::class);
    });
});

describe('Cuando se cae el último lote', function (): void {
    beforeEach(function (): void {
        $this->unico = ($this->lote)('7');

        $this->venta = $this->ventas->activar(
            proyecto: $this->proyecto,
            lotes: [$this->unico],
            clientes: [$this->cliente],
            prima: new Monto('50000.00'),
            plazoMeses: 12,
            diaPago: 5,
            precios: [($this->condiciones)($this->unico)],
        );

        $this->compromiso = $this->venta->compromisos()->firstOrFail();
    });

    /*
    | Con un solo lote, rescindirlo equivale a anular el contrato entero — y
    | por eso es el mismo trámite, no otro.
    */
    test('el expediente queda rescindido y con su fecha de cierre', function (): void {
        $this->rescisiones->rescindir(
            lote: $this->compromiso,
            devuelto: Monto::cero(),
            forma: FormaDePago::Efectivo,
            motivo: 'Se arrepintió.',
        );

        $venta = $this->venta->refresh();

        expect($venta->getAttribute('estado'))->toBe(EstadoVenta::Rescindida)
            ->and($venta->getAttribute('cerrada_el'))->not->toBeNull();
    });

    /*
    | 🔴 Los totales NO se ponen en cero. Un contrato cerrado es historia:
    | dejarlo en cero borraría por cuánto se había firmado, que es justo lo
    | que alguien va a querer saber dentro de un año.
    */
    test('los totales del contrato cerrado quedan como estaban', function (): void {
        $this->rescisiones->rescindir(
            lote: $this->compromiso,
            devuelto: Monto::cero(),
            forma: FormaDePago::Efectivo,
            motivo: 'Se arrepintió.',
        );

        $venta = $this->venta->refresh();

        expect($venta->getAttribute('valor_total'))->toBe('350000.00')
            ->and($venta->getAttribute('prima'))->toBe('50000.00')
            ->and($venta->getAttribute('saldo_financiar'))->toBe('300000.00');
    });
});

describe('Lo que no es una rescisión', function (): void {
    /*
    | Un apartado no se rescinde: se libera y se le devuelve la seña. Mandarlo
    | por acá le quemaría un número de la serie y saldría rotulado como
    | rescisión en el papel del cliente.
    */
    test('un apartado no se rescinde', function (): void {
        $lote = ($this->lote)('9');

        $apartado = app(RegistroDeCompromisos::class)->apartar(
            lote: $lote,
            cliente: $this->cliente,
            montoSenia: '5000.00',
            forma: FormaDePago::Efectivo,
        );

        expect($apartado->getAttribute('tipo'))->toBe(TipoCompromiso::Apartado);

        expect(fn () => $this->rescisiones->rescindir(
            lote: $apartado,
            devuelto: Monto::cero(),
            forma: FormaDePago::Efectivo,
            motivo: 'No quiere.',
        ))->toThrow(RescisionInvalidaException::class);
    });
});
