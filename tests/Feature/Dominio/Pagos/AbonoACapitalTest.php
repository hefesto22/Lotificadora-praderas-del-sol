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

/*
|--------------------------------------------------------------------------
| El abono a capital — R21
|--------------------------------------------------------------------------
| Un lote de 250 vr² a L 1,400.00 son L 350,000.00; con L 50,000.00 de prima
| quedan L 300,000.00 a financiar, que a 12 meses dan cuotas de L 25,000.00
| exactas. Todos los números de abajo salen de ahí y se pueden verificar sin
| calculadora.
|
| La venta se firma HOY, así que ninguna cuota nace vencida: la primera vence
| el 5 del mes que viene. Cuando un test necesita atraso, atrasa las cuotas a
| mano — es determinista y no depende del reloj.
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

    $this->abonar = fn (
        string $monto,
        ModalidadDeReprogramacion $modalidad = ModalidadDeReprogramacion::AcortarPlazo,
        string $motivo = 'Abono a capital solicitado por el cliente',
    ) => $this->pagos->abonarACapital(
        venta: $this->venta,
        lote: $this->renglon,
        cliente: $this->cliente,
        monto: new Monto($monto),
        modalidad: $modalidad,
        motivo: $motivo,
        forma: FormaDePago::Efectivo,
    );

    $this->cobrar = fn (string $monto) => $this->pagos->cobrarCuotas(
        venta: $this->venta,
        lote: $this->renglon,
        cliente: $this->cliente,
        monto: new Monto($monto),
        forma: FormaDePago::Efectivo,
    );

    // Las cuotas del lote, en orden, tal como están en la base.
    $this->plan = fn (): array => Cuota::query()
        ->where('compromiso_id', $this->renglon->getKey())
        ->orderBy('numero')
        ->pluck('monto', 'numero')
        ->all();

    $this->atrasar = function (array $numeros): void {
        Cuota::query()
            ->where('compromiso_id', $this->renglon->getKey())
            ->whereIn('numero', $numeros)
            ->update(['fecha_vencimiento' => today()->subMonths(2)->toDateString()]);
    };
});

/*
|--------------------------------------------------------------------------
| Camino 1 · Misma cuota, menos meses (el default histórico, R3)
|--------------------------------------------------------------------------
*/

describe('Acortar el plazo', function (): void {
    test('la cuota no se mueve y el plan termina antes', function (): void {
        ($this->abonar)('75000.00');

        // 300,000 − 75,000 = 225,000, que en cuotas de 25,000 son 9 exactas.
        expect(($this->plan)())->toBe([
            1 => '25000.00', 2 => '25000.00', 3 => '25000.00',
            4 => '25000.00', 5 => '25000.00', 6 => '25000.00',
            7 => '25000.00', 8 => '25000.00', 9 => '25000.00',
        ])
            ->and($this->venta->refresh()->saldoPendiente())->toBeMonto('225000.00');
    });

    test('deja la constancia con el antes y el después', function (): void {
        ($this->abonar)('75000.00');

        $constancia = Reprogramacion::query()->firstOrFail();

        expect($constancia->getAttribute('modalidad'))->toBe(ModalidadDeReprogramacion::AcortarPlazo)
            ->and($constancia->montoAbonado())->toBeMonto('75000.00')
            ->and($constancia->montoSaldoAnterior())->toBeMonto('300000.00')
            ->and($constancia->montoSaldoNuevo())->toBeMonto('225000.00')
            ->and($constancia->montoCuotaAnterior())->toBeMonto('25000.00')
            ->and($constancia->montoCuotaNueva())->toBeMonto('25000.00')
            ->and((int) $constancia->getAttribute('cuotas_antes'))->toBe(12)
            ->and((int) $constancia->getAttribute('cuotas_despues'))->toBe(9)
            ->and((int) $constancia->getAttribute('desde_numero'))->toBe(1)
            ->and($constancia->mesesAhorrados())->toBe(3)
            ->and($constancia->getAttribute('motivo'))->toBe('Abono a capital solicitado por el cliente');
    });

    /*
    | El plan viejo entero, para poder reconstruir el estado de cuenta de
    | cualquier fecha. Sin esto, «¿por qué mi cuota cambió?» no tiene respuesta:
    | las filas viejas ya no existen.
    */
    test('guarda el plan anterior completo, cuota por cuota', function (): void {
        ($this->abonar)('75000.00');

        $viejo = Reprogramacion::query()->firstOrFail()->planAnterior();

        expect($viejo)->toHaveCount(12)
            ->and($viejo[0]['numero'])->toBe(1)
            ->and($viejo[0]['monto'])->toBe('25000.00')
            ->and($viejo[11]['numero'])->toBe(12)
            ->and($viejo[0]['vence'])->toMatch('/^\d{4}-\d{2}-\d{2}$/');
    });

    test('el recibo dice que fue un abono, no una cuota', function (): void {
        $recibo = ($this->abonar)('75000.00');

        expect($recibo->getAttribute('concepto'))->toBe(ConceptoDeRecibo::AbonoCapital)
            ->and($recibo->montoTotal())->toBeMonto('75000.00')
            // Sin cuotas vencidas no hay nada que poner al día: todo fue capital.
            ->and($recibo->aplicaciones()->count())->toBe(0)
            ->and($recibo->reprogramaciones()->count())->toBe(1);
    });
});

/*
|--------------------------------------------------------------------------
| Camino 2 · Mismos meses, cuota más baja (agregado el 6-ago-2026)
|--------------------------------------------------------------------------
*/

describe('Bajar la cuota', function (): void {
    test('quedan los mismos doce meses, más baratos', function (): void {
        ($this->abonar)('60000.00', ModalidadDeReprogramacion::BajarCuota);

        // 300,000 − 60,000 = 240,000 entre 12 = 20,000 exactos.
        expect(($this->plan)())->toHaveCount(12)
            ->and(($this->plan)()[1])->toBe('20000.00')
            ->and(($this->plan)()[12])->toBe('20000.00')
            ->and($this->venta->refresh()->saldoPendiente())->toBeMonto('240000.00');
    });

    test('no ahorra meses, y la constancia lo dice', function (): void {
        ($this->abonar)('60000.00', ModalidadDeReprogramacion::BajarCuota);

        $constancia = Reprogramacion::query()->firstOrFail();

        expect($constancia->mesesAhorrados())->toBe(0)
            ->and($constancia->montoCuotaAnterior())->toBeMonto('25000.00')
            ->and($constancia->montoCuotaNueva())->toBeMonto('20000.00');
    });
});

/*
|--------------------------------------------------------------------------
| Los dos detalles que decidió Mauricio: el 24-ago y el 6-ago-2026
|--------------------------------------------------------------------------
*/

describe('El lote tiene que estar al día', function (): void {
    /*
    | 🔴 ESTO REEMPLAZA LO QUE SE DECIDIO EL 6-AGO
    |
    | «Que no pueda hacer abono a capital si tiene cuotas pendientes okey»
    | —Mauricio, 24-ago-2026—.
    |
    | Antes el abono ponía al día primero y el sobrante bajaba capital. Eso
    | dejaba UN papel contando dos historias y, cuando no alcanzaba ni para lo
    | vencido, un recibo que decía «abono» sin haber abonado nada. Ahora lo
    | vencido entra por su camino: «Cuota», o «Ambas» —que hace las dos cosas en
    | un solo recibo y ya rechazaba este mismo caso—.
    */
    test('con una cuota vencida el abono se rechaza', function (): void {
        ($this->atrasar)([1]);

        expect(fn () => ($this->abonar)('100000.00'))
            ->toThrow(PagoInvalidoException::class, 'no puede recibir un abono a capital');
    });

    /*
    | Y no queda nada a medias: ni recibo, ni constancia, ni una cuota con un
    | céntimo aplicado. La verificación corre en la fase 1, antes de emitir, así
    | que el correlativo tampoco se movió.
    */
    test('no se registra nada de lo que se iba a hacer', function (): void {
        ($this->atrasar)([1, 2]);

        expect(fn () => ($this->abonar)('100000.00'))->toThrow(PagoInvalidoException::class);

        expect(Recibo::query()->where('concepto', '!=', ConceptoDeRecibo::Prima)->count())->toBe(0)
            ->and(Reprogramacion::query()->count())->toBe(0)
            ->and(($this->plan)())->toHaveCount(12)
            ->and(Cuota::query()->where('compromiso_id', $this->renglon->getKey())->sum('monto_pagado'))->toBe('0.00')
            ->and($this->venta->refresh()->saldoPendiente())->toBeMonto('300000.00');
    });

    /*
    | El mensaje tiene que decir CUANTO está vencido y por dónde entra esa
    | plata: quien lo lee tiene al cliente enfrente y necesita el próximo paso.
    */
    test('el mensaje dice cuánto está vencido y por dónde cobrarlo', function (): void {
        ($this->atrasar)([1, 2]);

        expect(fn () => ($this->abonar)('100000.00'))
            ->toThrow(PagoInvalidoException::class, '2 cuotas vencidas por L. 50,000.00');
    });

    /*
    | La que vence HOY todavía no atrasa. Es la misma línea que usa la mora
    | —`Cuota::estaVencida()`— y correrla un día haría que el mismo abono se
    | acepte a las once y se rechace a la una.
    */
    test('la cuota que vence hoy no bloquea el abono', function (): void {
        Cuota::query()
            ->where('compromiso_id', $this->renglon->getKey())
            ->where('numero', 1)
            ->update(['fecha_vencimiento' => today()->toDateString()]);

        ($this->abonar)('75000.00');

        expect(Reprogramacion::query()->count())->toBe(1)
            ->and($this->venta->refresh()->saldoPendiente())->toBeMonto('225000.00');
    });
});

describe('La cuota a medias se respeta', function (): void {
    /*
    | Lo pagado no se toca nunca, y así el recibo viejo sigue apuntando a una
    | cuota que existe. La alternativa —absorber el parcial y recalcular todo—
    | deja aplicaciones de pago colgando de cuotas borradas.
    */
    test('el plan nuevo empieza en la siguiente', function (): void {
        ($this->cobrar)('12500.00');

        ($this->abonar)('50000.00');

        $primera = Cuota::query()
            ->where('compromiso_id', $this->renglon->getKey())
            ->where('numero', 1)
            ->firstOrFail();

        expect($primera->montoTotal())->toBeMonto('25000.00')
            ->and($primera->montoPagado())->toBeMonto('12500.00')
            // 11 cuotas reprogramables (275,000) − 50,000 = 225,000 → 9 cuotas.
            ->and(($this->plan)())->toHaveCount(10)
            ->and((int) Reprogramacion::query()->firstOrFail()->getAttribute('desde_numero'))->toBe(2);
    });

    test('el recibo viejo sigue apuntando a una cuota que existe', function (): void {
        $viejo = ($this->cobrar)('12500.00');

        ($this->abonar)('50000.00');

        $aplicacion = $viejo->aplicaciones()->firstOrFail();

        expect($aplicacion->cuota()->exists())->toBeTrue()
            ->and($aplicacion->montoAplicado())->toBeMonto('12500.00');
    });

    /*
    | Lo que le falta a esa cuota queda FUERA del alcance del abono: respetarla
    | significa no tocarla, ni siquiera para cobrarla de paso.
    */
    test('lo que le falta a esa cuota no se puede abonar', function (): void {
        ($this->cobrar)('12500.00');

        // Debe 287,500, pero solo 275,000 son reprogramables.
        expect(fn () => ($this->abonar)('280000.00'))
            ->toThrow(PagoInvalidoException::class, 'se puede abonar hasta');

        expect(Recibo::query()->where('concepto', '!=', ConceptoDeRecibo::Prima)->count())->toBe(1)
            ->and(($this->plan)())->toHaveCount(12);
    });
});

/*
|--------------------------------------------------------------------------
| Los bordes de R3, que valen para los dos caminos
|--------------------------------------------------------------------------
*/

describe('Cuando el abono alcanza para todo', function (): void {
    test('cancela el plan sin dejar cuotas de L 0.00 colgando', function (): void {
        ($this->abonar)('300000.00');

        expect(($this->plan)())->toBe([])
            ->and($this->venta->refresh()->saldoPendiente())->toBeMonto('0.00')
            ->and(Reprogramacion::query()->firstOrFail()->cancelaElLote())->toBeTrue()
            ->and(Reprogramacion::query()->firstOrFail()->montoCuotaNueva())->toBeNull();
    });

    test('un abono mayor al saldo se rechaza diciendo cuánto se debe', function (): void {
        expect(fn () => ($this->abonar)('300000.01'))
            ->toThrow(PagoInvalidoException::class, 'L. 300,000.00');
    });
});

/*
|--------------------------------------------------------------------------
| Todo o nada, y el motivo
|--------------------------------------------------------------------------
*/

describe('Lo que rechaza', function (): void {
    test('un abono sin motivo, aunque sean espacios', function (): void {
        expect(fn () => ($this->abonar)('75000.00', ModalidadDeReprogramacion::AcortarPlazo, '   '))
            ->toThrow(PagoInvalidoException::class, 'por qué');

        expect(Recibo::query()->where('concepto', '!=', ConceptoDeRecibo::Prima)->count())->toBe(0)
            ->and(($this->plan)())->toHaveCount(12);
    });

    /*
    | Un abono rechazado no quema un número de recibo. El correlativo es lo
    | único que no se puede reponer.
    */
    test('un abono rechazado no deja recibo, ni constancia, ni toca el plan', function (): void {
        try {
            ($this->abonar)('999999.00');
        } catch (PagoInvalidoException) {
            // Es lo que se espera.
        }

        expect(Recibo::query()->where('concepto', '!=', ConceptoDeRecibo::Prima)->count())->toBe(0)
            ->and(Reprogramacion::query()->count())->toBe(0)
            ->and(($this->plan)())->toHaveCount(12)
            ->and($this->venta->refresh()->saldoPendiente())->toBeMonto('300000.00');
    });

    /*
    | Desde el 8-ago-2026 pagar todo el saldo LIQUIDA el expediente, así que
    | con un solo lote este caso cambió de motivo: el abono se rechaza antes,
    | por venta cerrada. Es más estricto y es correcto — un contrato liquidado
    | no recibe nada.
    */
    test('un expediente liquidado no recibe abonos', function (): void {
        ($this->cobrar)('300000.00');

        expect(fn () => ($this->abonar)('10000.00'))
            ->toThrow(PagoInvalidoException::class, 'liquidada');
    });

    /*
    | Y «el lote no debe nada» sigue vivo donde de verdad ocurre: en un
    | contrato de varios lotes, cuando se salda UNO y el otro sigue debiendo,
    | así que la venta no se liquida. Se prueba acá para no perder la guarda
    | de `pendientesBloqueadas()`.
    */
    test('un lote saldado, con el contrato todavía vigente', function (): void {
        $proyecto = Proyecto::query()->firstOrFail();
        $bloque = Bloque::query()->firstOrFail();

        $venta = app(RegistroDeVentas::class)->activar(
            proyecto: $proyecto,
            lotes: [
                Lote::factory()->enBloque($bloque)->conMedidas('250.0000', '1400.00')->create(['numero' => '10']),
                Lote::factory()->enBloque($bloque)->conMedidas('250.0000', '1400.00')->create(['numero' => '11']),
            ],
            clientes: [$this->cliente],
            prima: new Monto('100000.00'),
            plazoMeses: 12,
            diaPago: 5,
        );

        $saldado = $venta->compromisos()->orderBy('lote_id')->firstOrFail();

        // El saldo se SUMA en vez de escribirlo: así el test no depende de
        // cómo `activar()` reparta la prima entre los dos lotes.
        $suSaldo = Monto::cero();

        foreach ($saldado->cuotas()->get() as $cuota) {
            $suSaldo = $suSaldo->sumar($cuota->saldo());
        }

        $this->pagos->cobrarCuotas(
            venta: $venta,
            lote: $saldado,
            cliente: $this->cliente,
            monto: $suSaldo,
            forma: FormaDePago::Efectivo,
        );

        // La venta NO se liquidó: el otro lote sigue debiendo.
        expect($venta->refresh()->getAttribute('estado'))->toBe(EstadoVenta::Vigente);

        expect(fn () => $this->pagos->abonarACapital(
            venta: $venta,
            lote: $saldado,
            cliente: $this->cliente,
            monto: new Monto('10000.00'),
            modalidad: ModalidadDeReprogramacion::AcortarPlazo,
            motivo: 'Abono a capital solicitado por el cliente',
            forma: FormaDePago::Efectivo,
        ))->toThrow(PagoInvalidoException::class, 'no debe nada');
    });
});

/*
|--------------------------------------------------------------------------
| El invariante que vale para cualquier abono
|--------------------------------------------------------------------------
| Después de un abono, el lote debe exactamente lo que debía menos lo que
| entró. En los dos caminos, con y sin atraso, al céntimo. Si esto falla, el
| estado de cuenta no cierra en cero el último mes.
|
| ⚠️ Con atraso el invariante viaja por «Ambas» desde el 24-ago-2026: el abono a
| secas pide el lote al día. De los mismos L X entran L 50,000.00 a las dos
| cuotas vencidas y el resto baja capital — **el total que el cliente entrega es
| el mismo**, y por eso el esperado no cambia ni una línea. Ahí está el valor de
| este test: la regla nueva cambió el CAMINO, no la aritmética.
*/

test('el saldo baja exactamente lo que entró, en cualquier combinación', function (
    string $monto,
    string $modalidad,
    bool $conAtraso,
): void {
    if ($conAtraso) {
        ($this->atrasar)([1, 2]);

        $this->pagos->cobrarYAbonar(
            venta: $this->venta,
            cliente: $this->cliente,
            cuotas: [['lote' => $this->renglon, 'monto' => new Monto('50000.00')]],
            loteDelAbono: $this->renglon,
            aCapital: new Monto($monto)->restar(new Monto('50000.00')),
            modalidad: ModalidadDeReprogramacion::from($modalidad),
            motivo: 'Abono a capital solicitado por el cliente',
            forma: FormaDePago::Efectivo,
        );
    } else {
        ($this->abonar)($monto, ModalidadDeReprogramacion::from($modalidad));
    }

    $esperado = new Monto('300000.00')->restar(new Monto($monto));

    expect($this->venta->refresh()->saldoPendiente())->toBeMonto($esperado->redondeado());
})->with([
    ['75000.00', 'acortar_plazo', false],
    ['75000.00', 'bajar_cuota', false],
    ['60000.00', 'acortar_plazo', true],
    ['60000.00', 'bajar_cuota', true],
    // Los que no dividen exacto: el residuo va a la última cuota.
    ['33333.33', 'acortar_plazo', false],
    ['33333.33', 'bajar_cuota', false],
    ['128745.19', 'bajar_cuota', true],
    ['0.01', 'acortar_plazo', false],
]);
