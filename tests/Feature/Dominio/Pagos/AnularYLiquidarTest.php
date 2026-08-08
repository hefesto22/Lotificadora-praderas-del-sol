<?php

declare(strict_types=1);

use App\Domain\Enums\ConceptoDeRecibo;
use App\Domain\Enums\EstadoVenta;
use App\Domain\Enums\FormaDePago;
use App\Domain\Enums\ModalidadDeReprogramacion;
use App\Domain\Exceptions\PagoInvalidoException;
use App\Domain\Pagos\RegistroDePagos;
use App\Domain\ValueObjects\Monto;
use App\Domain\Ventas\RegistroDeVentas;
use App\Models\Bloque;
use App\Models\Cliente;
use App\Models\Cuota;
use App\Models\Lote;
use App\Models\Proyecto;
use App\Models\Recibo;
use Carbon\CarbonImmutable;

/*
|--------------------------------------------------------------------------
| Anular un recibo · liquidar un expediente · la fecha del pago
|--------------------------------------------------------------------------
| Los tres trámites del 8-ago-2026, y los tres nacen del mismo hallazgo: el
| sistema sabía emitir dinero pero no sabía deshacerlo ni cerrar.
|
| Un lote de 250 vr² a L 1,400.00 son L 350,000.00; con L 50,000.00 de prima
| quedan L 300,000.00 a financiar, que a 12 meses dan cuotas de L 25,000.00
| exactas. Todos los números salen de ahí.
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

    $this->cobrar = fn (string $monto, ?CarbonImmutable $fecha = null) => $this->pagos->cobrarCuotas(
        venta: $this->venta,
        lote: $this->renglon,
        cliente: $this->cliente,
        monto: new Monto($monto),
        forma: FormaDePago::Efectivo,
        fecha: $fecha,
    );

    $this->pagadas = fn (int $cuantas): array => Cuota::query()
        ->where('compromiso_id', $this->renglon->getKey())
        ->orderBy('numero')
        ->limit($cuantas)
        ->pluck('monto_pagado')
        ->all();
});

describe('Anular un recibo', function (): void {
    /*
    | Lo que se revierte es `cuotas.monto_pagado`, que es de donde sale el
    | saldo. El recibo y sus aplicaciones se quedan: sin ellas, «¿por qué la
    | cuota 3 volvió a deber?» no tendría respuesta.
    */
    test('devuelve el saldo a las cuotas y deja la traza', function (): void {
        $recibo = ($this->cobrar)('60000.00');

        expect(($this->pagadas)(3))->toBe(['25000.00', '25000.00', '10000.00']);

        $anulado = $this->pagos->anular($recibo, 'Se tecleó de más: eran L 6,000.00');

        expect(($this->pagadas)(3))->toBe(['0.00', '0.00', '0.00'])
            ->and($anulado->estaAnulado())->toBeTrue()
            ->and($anulado->getAttribute('motivo_anulacion'))->toContain('L 6,000.00')
            ->and($anulado->getAttribute('anulado_por'))->toBe(auth()->id())
            // La fila y su detalle NO se borran: la serie no puede tener huecos.
            ->and(Recibo::query()->whereKey($recibo->getKey())->exists())->toBeTrue()
            ->and($anulado->aplicaciones()->count())->toBe(3)
            ->and($this->venta->refresh()->saldoPendiente())->toBeMonto('300000.00');
    });

    test('sin motivo no se anula', function (): void {
        $recibo = ($this->cobrar)('25000.00');

        expect(fn () => $this->pagos->anular($recibo, '   '))
            ->toThrow(PagoInvalidoException::class, 'por qué');

        expect($recibo->refresh()->estaAnulado())->toBeFalse()
            ->and(($this->pagadas)(1))->toBe(['25000.00']);
    });

    /*
    | Anular dos veces devolvería el saldo dos veces y la cuota quedaría
    | debiendo más de lo que vale.
    */
    test('no se anula dos veces', function (): void {
        $recibo = ($this->cobrar)('25000.00');
        $this->pagos->anular($recibo, 'Error de digitación');

        expect(fn () => $this->pagos->anular($recibo->refresh(), 'Otra vez'))
            ->toThrow(PagoInvalidoException::class, 'ya estaba anulado');

        expect(($this->pagadas)(1))->toBe(['0.00']);
    });

    /*
    | Un abono a capital BORRÓ cuotas y escribió otras. Deshacerlo es
    | devolverle al lote su plan viejo, no restar un número.
    */
    test('un abono a capital no se anula desde acá', function (): void {
        $recibo = $this->pagos->abonarACapital(
            venta: $this->venta,
            lote: $this->renglon,
            cliente: $this->cliente,
            monto: new Monto('100000.00'),
            modalidad: ModalidadDeReprogramacion::AcortarPlazo,
            motivo: 'Abono solicitado por la clienta',
            forma: FormaDePago::Efectivo,
        );

        expect(fn () => $this->pagos->anular($recibo, 'Me equivoqué'))
            ->toThrow(PagoInvalidoException::class, 'reescribió el plan');
    });

    /*
    | La prima consumió el correlativo del contrato. Revertirla es deshacer la
    | venta, que es otro trámite y tiene otro permiso.
    */
    test('el recibo de la prima no se anula desde acá', function (): void {
        $prima = Recibo::query()->where('concepto', ConceptoDeRecibo::Prima)->sole();

        expect(fn () => $this->pagos->anular($prima, 'Me equivoqué'))
            ->toThrow(PagoInvalidoException::class, 'otro trámite');
    });
});

describe('Liquidar el expediente', function (): void {
    /*
    | `EstadoVenta::Liquidada` existía desde la primera migración y nadie lo
    | asignaba nunca: una venta pagada al último centavo se quedaba «Vigente»
    | para siempre, ofreciendo el botón de cobrar sobre lo que no debe nada.
    */
    test('pagar todo el saldo cierra la venta', function (): void {
        ($this->cobrar)('300000.00');

        $venta = $this->venta->refresh();

        expect($venta->getAttribute('estado'))->toBe(EstadoVenta::Liquidada)
            ->and($venta->getAttribute('cerrada_el'))->not->toBeNull()
            ->and($venta->saldoPendiente())->toBeMonto('0.00');
    });

    test('pagar de a poco no la cierra antes de tiempo', function (): void {
        ($this->cobrar)('299999.00');

        expect($this->venta->refresh()->getAttribute('estado'))->toBe(EstadoVenta::Vigente);
    });

    /*
    | Sin esto, anular el último cobro dejaría un expediente «Liquidado» que
    | vuelve a deber dinero y sin botón para cobrarlo.
    */
    test('anular el cobro que la cerró la vuelve a abrir', function (): void {
        $recibo = ($this->cobrar)('300000.00');

        expect($this->venta->refresh()->getAttribute('estado'))->toBe(EstadoVenta::Liquidada);

        $this->pagos->anular($recibo, 'El cheque rebotó');

        $venta = $this->venta->refresh();

        expect($venta->getAttribute('estado'))->toBe(EstadoVenta::Vigente)
            ->and($venta->getAttribute('cerrada_el'))->toBeNull()
            ->and($venta->saldoPendiente())->toBeMonto('300000.00');
    });
});

describe('La fecha del pago', function (): void {
    /*
    | Un recibo fechado el mes que viene deja una cuota que figura pagada
    | antes de haberse cobrado.
    */
    test('no se cobra con fecha futura', function (): void {
        expect(fn () => ($this->cobrar)('25000.00', CarbonImmutable::parse(today()->addDay()->toDateString())))
            ->toThrow(PagoInvalidoException::class, 'posterior a hoy');

        expect(($this->pagadas)(1))->toBe(['0.00']);
    });

    /*
    | El clásico error de tipear el año.
    */
    test('no se cobra antes de que el contrato existiera', function (): void {
        expect(fn () => ($this->cobrar)('25000.00', CarbonImmutable::parse(today()->subYears(7)->toDateString())))
            ->toThrow(PagoInvalidoException::class, 'anterior a la firma');

        expect(($this->pagadas)(1))->toBe(['0.00']);
    });

    test('hoy sí se puede', function (): void {
        ($this->cobrar)('25000.00', CarbonImmutable::parse(today()->toDateString()));

        expect(($this->pagadas)(1))->toBe(['25000.00']);
    });
});
