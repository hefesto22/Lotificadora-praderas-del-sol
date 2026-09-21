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
use App\Models\Reprogramacion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

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

});

/*
|--------------------------------------------------------------------------
| 🔴 Anular un ABONO A CAPITAL — 11-sep-2026
|--------------------------------------------------------------------------
| «Ocurrió lo que temíamos: se equivocó y era de otra manera el hacer los pagos
| de cuota o abono a capital (…) muy seguramente volverá a pasar» — Mauricio.
|
| Hasta hoy esto se rechazaba: el abono BORRA las cuotas pendientes del lote y
| escribe otras, y devolverle el plan viejo «todavía no estaba construido».
|
| Se puede porque el plan viejo está guardado: cada reprogramación conserva en
| `plan_anterior` las cuotas que reemplazó y en `desde_numero` desde dónde
| reescribió. Deshacer es borrar lo que el abono creó y volver a escribir eso.
|
| Sobre el fixture: 300,000 a 12 meses son cuotas de 25,000. Un abono de
| 100,000 con «misma cuota, menos meses» deja 200,000, o sea 8 cuotas.
*/
describe('Anular un abono a capital', function (): void {
    beforeEach(function (): void {
        $this->abonar = fn (string $monto): Recibo => $this->pagos->abonarACapital(
            venta: $this->venta,
            lote: $this->renglon,
            cliente: $this->cliente,
            monto: new Monto($monto),
            modalidad: ModalidadDeReprogramacion::AcortarPlazo,
            motivo: 'Abono solicitado por la clienta',
            forma: FormaDePago::Efectivo,
        );

        $this->plan = fn (): array => Cuota::query()
            ->where('compromiso_id', $this->renglon->getKey())
            ->orderBy('numero')
            ->pluck('monto', 'numero')
            ->all();
    });

    /*
    | El caso del pedido, de punta a punta: el lote queda exactamente como
    | estaba antes del abono — las mismas doce cuotas, los mismos montos y el
    | mismo saldo—. No «parecido»: el mismo plan.
    */
    test('el lote recupera el plan que tenía antes', function (): void {
        $antes = ($this->plan)();

        expect($antes)->toHaveCount(12);

        $recibo = ($this->abonar)('100000.00');

        // El abono acortó el plazo: 200,000 a cuota fija de 25,000.
        expect(($this->plan)())->toHaveCount(8);

        $this->pagos->anular($recibo->refresh(), 'Era cuota, no abono a capital');

        expect(($this->plan)())->toBe($antes)
            ->and($this->venta->refresh()->saldoPendiente())->toBeMonto('300000.00');
    });

    /*
    | La constancia se BORRA, y es lo único de este repo que se borra en vez de
    | marcarse. `Reprogramacion` dice «es historia, no se edita ni se borra», y
    | vale para una reprogramación que OCURRIO. Esta no ocurrió: el plan volvió
    | a ser el de antes, y una constancia que siga diciendo «tu cuota cambió
    | por este abono» le mentiría al estado de cuenta, que se reconstruye
    | leyendo justamente estas filas.
    |
    | Lo que pasó no se pierde: queda el recibo anulado, con su motivo.
    */
    test('no queda constancia de una reprogramación que se deshizo', function (): void {
        $recibo = ($this->abonar)('100000.00');

        expect(Reprogramacion::query()->count())->toBe(1);

        $this->pagos->anular($recibo->refresh(), 'Era cuota, no abono a capital');

        expect(Reprogramacion::query()->count())->toBe(0)
            ->and($recibo->refresh()->estaAnulado())->toBeTrue()
            ->and($recibo->getAttribute('motivo_anulacion'))->toBe('Era cuota, no abono a capital');
    });

    /*
    | 🔴🔴 LA INVARIANTE CARA: solo si es el ULTIMO movimiento del lote.
    |
    | Si después del abono se cobró una cuota del plan NUEVO, devolver el plan
    | viejo dejaría ese pago apuntando a una cuota que deja de existir: el lote
    | perdería plata pagada y las cuentas no cuadrarían con ningún recibo.
    |
    | Se deshace de atrás para adelante, y el mensaje dice por dónde empezar —
    | con el folio, no con «hay movimientos posteriores», que obliga a quien lo
    | lee a salir a buscarlos.
    */
    test('no se anula si después se cobró una cuota del plan nuevo', function (): void {
        $abono = ($this->abonar)('100000.00');
        $cobro = ($this->cobrar)('25000.00');

        expect(fn () => $this->pagos->anular($abono->refresh(), 'Me equivoqué'))
            ->toThrow(PagoInvalidoException::class, $cobro->folio());

        // Y el lote no quedó a medias: sigue con el plan del abono.
        expect(($this->plan)())->toHaveCount(8);
    });

    /*
    | 🔴 EL QUE REVENTO EN PRUEBAS — 21-sep-2026. Un cobro sobre el plan nuevo
    | que después se ANULO no estorba —ya no mueve un centavo—, pero su
    | aplicación se conserva como traza y sigue apuntando a una cuota del plan
    | nuevo. Deshacer el abono BORRABA esas cuotas, y Postgres se negaba con un
    | error crudo de llave foránea. Ahora se pisan en el lugar y conservan su id.
    */
    test('se anula aunque un cobro posterior ya anulado haya dejado su traza en el plan nuevo', function (): void {
        $antes = ($this->plan)();

        $abono = ($this->abonar)('100000.00');
        $cobro = ($this->cobrar)('25000.00');

        $this->pagos->anular($cobro->refresh(), 'Se cobró por error');
        $this->pagos->anular($abono->refresh(), 'Era cuota, no abono a capital');

        expect(($this->plan)())->toBe($antes)
            ->and($this->venta->refresh()->saldoPendiente())->toBeMonto('300000.00')
            // La traza del cobro anulado sigue ahí, y apunta a una cuota que existe.
            ->and(DB::table('aplicaciones_de_pago')->where('recibo_id', $cobro->getKey())->count())->toBe(1);
    });

    /*
    | Lo mismo con otro abono encima: su `plan_anterior` es el plan que escribió
    | este, así que devolverle a este el suyo lo dejaría apuntando a cuotas que
    | dejan de existir.
    */
    test('no se anula si después hubo otro abono sobre el mismo lote', function (): void {
        $primero = ($this->abonar)('100000.00');
        $segundo = ($this->abonar)('50000.00');

        expect(fn () => $this->pagos->anular($primero->refresh(), 'Me equivoqué'))
            ->toThrow(PagoInvalidoException::class, $segundo->folio());
    });

    /*
    | Y deshaciendo en el orden correcto sí se puede: el de arriba no es un
    | callejón sin salida, es una cola.
    */
    test('deshaciendo del más nuevo al más viejo se puede con los dos', function (): void {
        $antes = ($this->plan)();

        $primero = ($this->abonar)('100000.00');
        $segundo = ($this->abonar)('50000.00');

        $this->pagos->anular($segundo->refresh(), 'El segundo estaba mal');
        $this->pagos->anular($primero->refresh(), 'El primero también');

        expect(($this->plan)())->toBe($antes)
            ->and($this->venta->refresh()->saldoPendiente())->toBeMonto('300000.00');
    });

    /*
    | La prima consumió el correlativo del contrato. Revertirla es deshacer la
    | venta, que es otro trámite y tiene otro permiso. Eso NO cambió.
    */
    test('el recibo de la prima sigue sin anularse desde acá', function (): void {
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
