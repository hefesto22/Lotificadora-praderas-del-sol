<?php

declare(strict_types=1);

use App\Domain\Enums\FormaDePago;
use App\Domain\Enums\ModalidadDeReprogramacion;
use App\Domain\Exceptions\CorreccionDeValorInvalidaException;
use App\Domain\Exceptions\PagoInvalidoException;
use App\Domain\Exceptions\ReestructuracionInvalidaException;
use App\Domain\Pagos\RegistroDePagos;
use App\Domain\ValueObjects\Monto;
use App\Domain\Ventas\CondicionesDeReestructuracion;
use App\Domain\Ventas\CorreccionDeValor;
use App\Domain\Ventas\PrecioPactado;
use App\Domain\Ventas\RegistroDeReestructuraciones;
use App\Domain\Ventas\RegistroDeVentas;
use App\Domain\Ventas\TasaDeInteres;
use App\Models\AplicacionDePago;
use App\Models\Bloque;
use App\Models\Cliente;
use App\Models\Compromiso;
use App\Models\Cuota;
use App\Models\Lote;
use App\Models\Proyecto;
use App\Models\Recibo;
use App\Models\Reestructuracion;
use App\Models\Reprogramacion;
use App\Models\Venta;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Reestructurar el contrato — el expediente 0033 (25-oct-2026)
|--------------------------------------------------------------------------
| «Hay que hacer reestructuración de contrato, ya sea de un lote o de dos …
| así como lo manejamos en Constructora Maya» —Mauricio—, y para el 0033:
| «se reestructurarían los dos lotes de manera individual a 24 meses cada
| uno».
|
| El escenario, con los números del caso y sin interés (R1):
|
|   Dos lotes de L 300,000.00 con L 60,000.00 de prima cada uno: deben
|   L 240,000.00 cada uno, a 48 cuotas de L 5,000.00 desde el 15/07/2026.
|   Cuatro recibos de L 20,000.00 —L 10,000.00 a cada lote—: L 40,000.00
|   pagados por lote, las cuotas 1 a 8 de cada uno.
|
| Lo que tiene que quedar, al céntimo:
|
|   Desde la cuota 1: 24 cuotas de L 10,000.00 por lote desde el 15/07/2026.
|   Lo pagado cubre las cuatro primeras de cada uno: al 25/10/2026 queda al
|   día, y lo próximo es el 15/11/2026 por L 20,000.00.
|   Desde la próxima: las cuotas 1 a 8 quedan como están, y las 9 a 32 son
|   23 de L 8,333.33 y una de L 8,333.41 por lote, desde el 15/03/2027.
|
|   En los dos casos el contrato debe exactamente lo mismo que antes, y no
|   se emite ni se anula un solo recibo.
|
| El motor puro está en `tests/Unit/Domain/Ventas/PlanUnificadoTest.php` y
| `CoberturaDelPlanTest.php`. Acá, lo que solo existe con base de datos.
| Las fechas viajan en el tiempo (§9.C2, §9.C11).
*/

beforeEach(function (): void {
    actingAsAdmin();

    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));

    $this->proyecto = Proyecto::factory()->create(['codigo' => 'RPS']);
    $this->bloque = Bloque::factory()->create(['proyecto_id' => $this->proyecto->getKey(), 'nombre' => 'A']);
    $this->cliente = Cliente::factory()->create(['nombre' => 'CLIENTE DEL EXPEDIENTE 0033']);

    $this->vender = function (?string $titularDelSegundo = null): Venta {
        $lotes = [
            Lote::factory()->enBloque($this->bloque)->conMedidas('250.0000', '1200.000000')->create(['numero' => '1']),
            Lote::factory()->enBloque($this->bloque)->conMedidas('250.0000', '1200.000000')->create(['numero' => '2']),
        ];

        return app(RegistroDeVentas::class)->activar(
            proyecto: $this->proyecto,
            lotes: $lotes,
            clientes: [$this->cliente],
            prima: new Monto('120000.00'),
            plazoMeses: 48,
            diaPago: 15,
            fechaContrato: CarbonImmutable::parse('2026-06-15'),
            precios: [
                new PrecioPactado(
                    loteId: (int) $lotes[0]->getKey(),
                    precioVara: new Monto('1200.000000'),
                    prima: new Monto('60000.00'),
                ),
                new PrecioPactado(
                    loteId: (int) $lotes[1]->getKey(),
                    precioVara: new Monto('1200.000000'),
                    prima: new Monto('60000.00'),
                    titularRecibo: $titularDelSegundo,
                    dniTitularRecibo: $titularDelSegundo === null ? null : '0801199012345',
                ),
            ],
        );
    };

    $this->lotes = static fn (Venta $venta): array => Compromiso::query()
        ->where('venta_id', $venta->getKey())
        ->reorder()
        ->orderBy('id')
        ->get()
        ->all();

    /*
    | Los cuatro recibos del caso: L 10,000.00 a cada lote, el día 10 de cada
    | mes de julio a octubre. Cada uno paga dos cuotas de L 5,000.00 por lote.
    */
    $this->pagarLosCuatroRecibos = function (Venta $venta): array {
        [$uno, $dos] = ($this->lotes)($venta);
        $recibos = [];

        foreach (['2026-07-10', '2026-08-10', '2026-09-10', '2026-10-10'] as $dia) {
            $this->travelTo(CarbonImmutable::parse($dia.' 09:00:00'));

            array_push($recibos, ...app(RegistroDePagos::class)->cobrarVariosLotes(
                venta: $venta,
                cliente: $this->cliente,
                renglones: [
                    ['lote' => $uno, 'monto' => new Monto('10000.00')],
                    ['lote' => $dos, 'monto' => new Monto('10000.00')],
                ],
                forma: FormaDePago::Efectivo,
            ));
        }

        return $recibos;
    };

    $this->registro = static fn (): RegistroDeReestructuraciones => app(RegistroDeReestructuraciones::class);

    $this->a24Meses = static fn (CarbonImmutable $primero): CondicionesDeReestructuracion => CondicionesDeReestructuracion::porMeses(
        24,
        true,
        TasaDeInteres::cero(),
        $primero,
    );

    $this->cuotasDe = static fn (Compromiso $lote): array => Cuota::query()
        ->where('compromiso_id', $lote->getKey())
        ->reorder()
        ->orderBy('numero')
        ->get()
        ->all();

    /*
    | Por cada recibo, cuánto quedó aplicado a cada lote: reestructurar cada
    | lote por su lado no puede mover un centavo de un lote al otro.
    */
    $this->porLoteDe = static fn (Recibo $recibo): array => DB::table('aplicaciones_de_pago')
        ->join('cuotas', 'cuotas.id', '=', 'aplicaciones_de_pago.cuota_id')
        ->where('aplicaciones_de_pago.recibo_id', $recibo->getKey())
        ->groupBy('cuotas.compromiso_id')
        ->orderBy('cuotas.compromiso_id')
        ->selectRaw('cuotas.compromiso_id AS lote, SUM(aplicaciones_de_pago.monto) AS monto')
        ->pluck('monto', 'lote')
        ->map(static fn (mixed $monto): string => is_scalar($monto) ? (string) $monto : '')
        ->all();
});

test('el escenario arranca como el 0033: L 40,000.00 pagados en cada lote, las cuotas 1 a 8', function (): void {
    $venta = ($this->vender)();
    ($this->pagarLosCuatroRecibos)($venta);

    foreach (($this->lotes)($venta) as $lote) {
        $cuotas = ($this->cuotasDe)($lote);

        expect($cuotas)->toHaveCount(48)
            ->and($cuotas[0]->getAttribute('fecha_vencimiento')?->toDateString())->toBe('2026-07-15')
            ->and($cuotas[0]->montoTotal())->toBeMonto('5000.00')
            ->and($cuotas[7]->saldo()->esCero())->toBeTrue()
            ->and($cuotas[8]->montoPagado())->toBeMonto('0.00');
    }

    expect($venta->fresh()?->saldoPendiente())->toBeMonto('400000.00');
});

test('🔴 desde la cuota 1, cada lote con su cuota: 24 de L 10,000.00, al día, y lo próximo el 15/11 por L 20,000.00', function (): void {
    $venta = ($this->vender)();
    $recibos = ($this->pagarLosCuatroRecibos)($venta);
    $this->travelTo(CarbonImmutable::parse('2026-10-25 11:00:00'));

    $papeles = Recibo::query()->count();
    $antes = array_map($this->porLoteDe, $recibos);

    $situacion = ($this->registro)()->situacion($venta, desdeLaPrimera: true);

    expect($situacion->sePuede())->toBeTrue()
        ->and($situacion->primerNumero())->toBe(1)
        ->and($situacion->capitalTotal())->toBeMonto('480000.00')
        ->and($situacion->yaPagado())->toBeMonto('80000.00');

    $condiciones = ($this->a24Meses)(CarbonImmutable::parse('2026-07-15'))->cadaLoteConSuCuota();
    $plan = ($this->registro)()->propuesta($situacion, $condiciones);

    expect(($this->registro)()->vencidasAlNacer($situacion, $plan)['cuotas'])->toBe(0);

    $acta = ($this->registro)()->reestructurar(
        $venta,
        $condiciones,
        'Cada lote a 24 meses, con lo pagado desde la cuota 1',
        desdeLaPrimera: true,
    );

    foreach (($this->lotes)($venta) as $lote) {
        $cuotas = ($this->cuotasDe)($lote);

        expect($cuotas)->toHaveCount(24)
            ->and(array_map(static fn (Cuota $cuota): int => (int) $cuota->getAttribute('numero'), $cuotas))->toBe(range(1, 24))
            ->and(array_unique(array_map(static fn (Cuota $cuota): string => $cuota->montoTotal()->redondeado(), $cuotas)))->toBe(['10000.00'])
            ->and($cuotas[23]->getAttribute('fecha_vencimiento')?->toDateString())->toBe('2028-06-15');

        // Las cuatro primeras, pagadas enteras con los recibos de siempre.
        foreach ([0, 1, 2, 3] as $i) {
            expect($cuotas[$i]->saldo()->esCero())->toBeTrue();
        }

        expect($cuotas[4]->montoPagado())->toBeMonto('0.00')
            ->and($cuotas[4]->getAttribute('fecha_vencimiento')?->toDateString())->toBe('2026-11-15')
            ->and($cuotas[4]->estaVencida())->toBeFalse();
    }

    // Lo próximo que paga el contrato: el 15/11/2026, L 20,000.00 entre los dos.
    $monto = DB::table('cuotas')
        ->where('venta_id', $venta->getKey())
        ->where('numero', 5)
        ->selectRaw('SUM(monto) AS valor')
        ->value('valor');

    $vence = DB::table('cuotas')
        ->where('venta_id', $venta->getKey())
        ->where('numero', 5)
        ->selectRaw('MIN(fecha_vencimiento) AS valor')
        ->value('valor');

    expect($monto)->toBe('20000.00')
        ->and($vence)->toBe('2026-11-15');

    // Ni un papel nuevo ni uno anulado, y cada recibo sigue pagando lo mismo a cada lote.
    expect(Recibo::query()->count())->toBe($papeles)
        ->and(array_map($this->porLoteDe, $recibos))->toBe($antes);

    foreach ($recibos as $recibo) {
        $fresco = Recibo::query()->whereKey($recibo->getKey())->firstOrFail();

        expect($fresco->estaAnulado())->toBeFalse()
            ->and($fresco->cuadra())->toBeTrue();
    }

    // Debe exactamente lo mismo que antes.
    $venta->refresh();

    expect($venta->saldoPendiente())->toBeMonto('400000.00')
        ->and((int) $venta->getAttribute('plazo_meses'))->toBe(24)
        ->and((string) $venta->getAttribute('cuota_mensual'))->toBe('20000.00')
        ->and((int) $venta->getAttribute('dia_pago'))->toBe(15);

    // El acta: cada lote con su cuota, y cómo quedó cada uno.
    expect($acta->folio())->toEndWith('-R1')
        ->and($acta->cadaLoteConSuCuota())->toBeTrue()
        ->and($acta->esRenegociacion())->toBeFalse()
        ->and((int) $acta->getAttribute('lotes'))->toBe(2)
        ->and((int) $acta->getAttribute('meses_antes'))->toBe(48)
        ->and((int) $acta->getAttribute('meses_despues'))->toBe(24)
        ->and((string) $acta->getAttribute('cuota_nueva'))->toBe('20000.00')
        ->and((string) $acta->getAttribute('ya_pagado'))->toBe('80000.00')
        ->and($acta->planNuevo())->toHaveCount(24);

    $detalle = $acta->lotesDetalle();

    expect($detalle)->toHaveCount(2);

    foreach ($detalle as $lote) {
        expect($lote['capital'])->toBeMonto('240000.00')
            ->and($lote['cuotas'])->toBe(24)
            ->and($lote['cuota'])->toBeMonto('10000.00')
            ->and($lote['ultima'])->toBeMonto('10000.00')
            ->and($lote['desde'])->toBe(1)
            ->and($lote['primera'])->toBe('2026-07-15')
            ->and($lote['termina'])->toBe('2028-06-15');
    }

    // Una constancia por lote, sin recibo, sin abono y apuntando al acta.
    $constancias = Reprogramacion::query()->where('reestructuracion_id', $acta->getKey())->get();

    expect($constancias)->toHaveCount(2);

    foreach ($constancias as $constancia) {
        expect($constancia->getAttribute('recibo_id'))->toBeNull()
            ->and($constancia->getAttribute('modalidad'))->toBeNull()
            ->and($constancia->montoAbonado())->toBeMonto('0.00')
            ->and($constancia->esReestructuracion())->toBeTrue()
            ->and($constancia->planAnterior())->toHaveCount(48);
    }
});

test('🔴 desde la próxima, cada lote con su cuota: las cuotas pagadas no se tocan y lo que falta va a 24 meses', function (): void {
    $venta = ($this->vender)();
    ($this->pagarLosCuatroRecibos)($venta);
    $this->travelTo(CarbonImmutable::parse('2026-10-25 11:00:00'));

    $pagadas = static fn (): array => DB::table('cuotas')
        ->where('venta_id', $venta->getKey())
        ->where('numero', '<=', 8)
        ->orderBy('id')
        ->get(['id', 'numero', 'monto', 'monto_pagado', 'fecha_vencimiento'])
        ->map(static fn (object $fila): array => (array) $fila)
        ->all();

    $antes = $pagadas();
    $situacion = ($this->registro)()->situacion($venta);

    expect($situacion->primerNumero())->toBe(9)
        ->and($situacion->capitalTotal())->toBeMonto('400000.00')
        ->and($situacion->yaPagado())->toBeMonto('0.00')
        ->and($situacion->proximoVencimiento()?->toDateString())->toBe('2027-03-15');

    ($this->registro)()->reestructurar(
        $venta,
        ($this->a24Meses)(CarbonImmutable::parse('2027-03-15'))->cadaLoteConSuCuota(),
        'Lo que le falta a cada lote, a 24 meses',
    );

    expect($pagadas())->toBe($antes);

    foreach (($this->lotes)($venta) as $lote) {
        $cuotas = ($this->cuotasDe)($lote);
        $nuevas = array_slice($cuotas, 8);
        $montos = array_map(static fn (Cuota $cuota): string => $cuota->montoTotal()->redondeado(), $nuevas);

        expect($cuotas)->toHaveCount(32)
            ->and(array_map(static fn (Cuota $cuota): int => (int) $cuota->getAttribute('numero'), $nuevas))->toBe(range(9, 32))
            ->and(array_unique(array_slice($montos, 0, 23)))->toBe(['8333.33'])
            ->and($montos[23])->toBe('8333.41')
            ->and($nuevas[0]->getAttribute('fecha_vencimiento')?->toDateString())->toBe('2027-03-15');
    }

    expect($venta->fresh()?->saldoPendiente())->toBeMonto('400000.00');
});

test('🔴 cada lote por su lado, lo que pagó un lote se queda en ese lote; unificados, pasa al otro', function (): void {
    $venta = ($this->vender)();
    [$uno] = ($this->lotes)($venta);

    // Un solo recibo, de L 40,000.00, todo al primer lote.
    $this->travelTo(CarbonImmutable::parse('2026-07-10 09:00:00'));

    $recibo = app(RegistroDePagos::class)->cobrarVariosLotes(
        venta: $venta,
        cliente: $this->cliente,
        renglones: [['lote' => $uno, 'monto' => new Monto('40000.00')]],
        forma: FormaDePago::Efectivo,
    )[0];

    $this->travelTo(CarbonImmutable::parse('2026-07-12 09:00:00'));

    $condiciones = ($this->a24Meses)(CarbonImmutable::parse('2026-07-15'));

    // Unificados: el mismo recibo llena el mes 1 y el 2 de los dos lotes.
    $unificados = ($this->registro)()->cobertura(
        ($this->registro)()->situacion($venta, desdeLaPrimera: true),
        ($this->registro)()->propuesta(($this->registro)()->situacion($venta, desdeLaPrimera: true), $condiciones),
    );

    expect($unificados->mesesCubiertos())->toBe(2);

    // Cada lote por su lado: cubre las cuatro primeras del primero y nada del segundo.
    ($this->registro)()->reestructurar(
        $venta,
        $condiciones->cadaLoteConSuCuota(),
        'Cada lote con su cuota',
        desdeLaPrimera: true,
    );

    expect(($this->porLoteDe)($recibo))->toBe([(int) $uno->getKey() => '40000.00'])
        ->and(Recibo::query()->whereKey($recibo->getKey())->value('compromiso_id'))->toBe((int) $uno->getKey());

    [$primero, $segundo] = ($this->lotes)($venta);

    expect(($this->cuotasDe)($primero)[3]->saldo()->esCero())->toBeTrue()
        ->and(($this->cuotasDe)($segundo)[0]->montoPagado())->toBeMonto('0.00');
});

test('🔴 cada lote por su lado tampoco puede nacer atrasado: el lote que no pagó se lo dice', function (): void {
    $venta = ($this->vender)();
    [$uno] = ($this->lotes)($venta);

    $this->travelTo(CarbonImmutable::parse('2026-07-10 09:00:00'));

    app(RegistroDePagos::class)->cobrarVariosLotes(
        venta: $venta,
        cliente: $this->cliente,
        renglones: [['lote' => $uno, 'monto' => new Monto('40000.00')]],
        forma: FormaDePago::Efectivo,
    );

    $this->travelTo(CarbonImmutable::parse('2026-10-25 11:00:00'));

    // El segundo lote no tiene nada pagado y sus cuotas de julio a octubre ya vencieron.
    expect(fn () => ($this->registro)()->reestructurar(
        $venta,
        ($this->a24Meses)(CarbonImmutable::parse('2026-07-15'))->cadaLoteConSuCuota(),
        'Cada lote con su cuota',
        desdeLaPrimera: true,
    ))->toThrow(ReestructuracionInvalidaException::class);

    expect(Reestructuracion::query()->count())->toBe(0);
});

test('con recibos a nombres distintos no se unifican, pero cada lote por su lado sí', function (): void {
    $venta = ($this->vender)('OTRA PERSONA');
    ($this->pagarLosCuatroRecibos)($venta);
    $this->travelTo(CarbonImmutable::parse('2026-10-25 11:00:00'));

    $condiciones = ($this->a24Meses)(CarbonImmutable::parse('2026-07-15'));

    expect(fn () => ($this->registro)()->reestructurar($venta, $condiciones, 'Juntarlos', desdeLaPrimera: true))
        ->toThrow(ReestructuracionInvalidaException::class, 'nombre de personas distintas');

    $acta = ($this->registro)()->reestructurar($venta, $condiciones->cadaLoteConSuCuota(), 'Cada uno con su cuota', desdeLaPrimera: true);

    expect($acta->cadaLoteConSuCuota())->toBeTrue();
});

test('a fechas y con descuento todavía no: se niega con el motivo y sin tocar nada', function (): void {
    $venta = ($this->vender)();
    ($this->pagarLosCuatroRecibos)($venta);
    $this->travelTo(CarbonImmutable::parse('2026-10-25 11:00:00'));

    $conDescuento = ($this->a24Meses)(CarbonImmutable::parse('2027-03-15'))->conDescuento(new Monto('10000.00'));

    expect(fn () => ($this->registro)()->renegociar($venta, $conDescuento, 'Un descuento'))
        ->toThrow(ReestructuracionInvalidaException::class, 'todavía no está disponible')
        ->and(fn () => ($this->registro)()->reestructurar($venta, $conDescuento, 'Un descuento'))
        ->toThrow(ReestructuracionInvalidaException::class);

    expect(Reestructuracion::query()->count())->toBe(0);
});

test('sin motivo no se reestructura', function (): void {
    $venta = ($this->vender)();

    ($this->registro)()->reestructurar($venta, ($this->a24Meses)(CarbonImmutable::parse('2026-07-15')), '   ');
})->throws(ReestructuracionInvalidaException::class);

test('🔴 un abono a capital de antes ya no se puede anular: el plan se reestructuró después', function (): void {
    $venta = ($this->vender)();
    [$uno, $dos] = ($this->lotes)($venta);

    $this->travelTo(CarbonImmutable::parse('2026-07-10 09:00:00'));

    $recibo = app(RegistroDePagos::class)->cobrarYAbonar(
        venta: $venta,
        cliente: $this->cliente,
        cuotas: [
            ['lote' => $uno, 'monto' => new Monto('5000.00')],
            ['lote' => $dos, 'monto' => new Monto('5000.00')],
        ],
        abonos: [
            ['lote' => $uno, 'monto' => new Monto('10000.00'), 'modalidad' => ModalidadDeReprogramacion::AcortarPlazo],
        ],
        motivo: 'Abono a capital solicitado por el cliente',
        forma: FormaDePago::Efectivo,
    )[0];

    $this->travelTo(CarbonImmutable::parse('2026-07-12 09:00:00'));

    ($this->registro)()->reestructurar(
        $venta,
        ($this->a24Meses)(CarbonImmutable::parse('2026-08-15'))->cadaLoteConSuCuota(),
        'Cada lote a 24 meses',
    );

    expect(fn () => app(RegistroDePagos::class)->anular($recibo, 'Se cobró mal'))
        ->toThrow(PagoInvalidoException::class);

    expect(Recibo::query()->whereKey($recibo->getKey())->firstOrFail()->estaAnulado())->toBeFalse()
        ->and(AplicacionDePago::query()->where('recibo_id', $recibo->getKey())->exists())->toBeTrue();
});

test('el valor de un lote reestructurado no se corrige: se reestructura de nuevo', function (): void {
    $venta = ($this->vender)();
    ($this->pagarLosCuatroRecibos)($venta);
    $this->travelTo(CarbonImmutable::parse('2026-10-25 11:00:00'));

    ($this->registro)()->reestructurar(
        $venta,
        ($this->a24Meses)(CarbonImmutable::parse('2026-07-15'))->cadaLoteConSuCuota(),
        'Cada lote a 24 meses',
        desdeLaPrimera: true,
    );

    [$uno] = ($this->lotes)($venta);
    $codigo = (string) $uno->lote?->getAttribute('codigo');

    app(CorreccionDeValor::class)->corregir($venta->fresh() ?? $venta, [$codigo => new Monto('300010.00')], 'Valor del contrato');
})->throws(CorreccionDeValorInvalidaException::class, 'reestructurado');
