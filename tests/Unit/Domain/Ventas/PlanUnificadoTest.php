<?php

declare(strict_types=1);

use App\Domain\Exceptions\PlanDeCuotasInvalidoException;
use App\Domain\ValueObjects\Monto;
use App\Domain\Ventas\CondicionesDeReestructuracion;
use App\Domain\Ventas\CuotaProyectada;
use App\Domain\Ventas\PlanUnificado;
use App\Domain\Ventas\TasaDeInteres;
use Carbon\CarbonImmutable;

/*
|--------------------------------------------------------------------------
| El plan de una reestructuración: unificados, o cada lote con su cuota
|--------------------------------------------------------------------------
| Traído de Maya (25-sep-2026) con la pieza que pidió Praderas el
| 25-oct-2026: «se reestructurarían los dos lotes de manera individual a 24
| meses cada uno» —expediente 0033—.
|
| Unitario como el resto del motor: sin base de datos ni Laravel. Las fechas
| son parámetros de una función pura, no `now()` (§9.C2).
|
| Los números dorados del 0033, sin interés (R1):
|
|   Desde la cuota 1: L 240,000.00 por lote a 24 meses → 24 de L 10,000.00
|   por lote, L 20,000.00 por mes el contrato.
|   Desde la próxima: L 200,000.00 por lote → 23 de L 8,333.33 y la última
|   de L 8,333.41 por lote; el contrato, L 16,666.66 y L 16,666.82.
*/

beforeEach(function (): void {
    $this->primero = CarbonImmutable::parse('2026-07-15');

    $this->aMeses = fn (int $meses, ?TasaDeInteres $tasa = null): CondicionesDeReestructuracion => CondicionesDeReestructuracion::porMeses(
        $meses,
        true,
        $tasa ?? TasaDeInteres::cero(),
        $this->primero,
    );

    /*
    | Las dos invariantes, verificadas en cada caso: cada lote cierra contra
    | su capital, y cada mes del contrato suma lo que pagan los lotes ese mes.
    */
    $this->invariantes = function (PlanUnificado $plan, array $capitales): void {
        foreach ($plan->porLote as $id => $cuotas) {
            $capital = Monto::cero();

            foreach ($cuotas as $cuota) {
                $capital = $capital->sumar($cuota->capital);
            }

            expect($capital)->toBeMonto($capitales[$id]->redondeado());
        }

        foreach ($plan->contrato->cuotas as $i => $renglon) {
            $monto = Monto::cero();

            foreach ($plan->porLote as $cuotas) {
                $cuota = $cuotas[$i] ?? null;

                if ($cuota instanceof CuotaProyectada) {
                    expect($cuota->numero)->toBe($renglon->numero)
                        ->and($cuota->vencimientoParaBase())->toBe($renglon->vencimientoParaBase());

                    $monto = $monto->sumar($cuota->monto);
                }
            }

            expect($monto)->toBeMonto($renglon->montoParaBase());
        }
    };

    $this->montos = static fn (array $cuotas): array => array_map(
        static fn (CuotaProyectada $cuota): string => $cuota->montoParaBase(),
        $cuotas,
    );
});

it('🔴 el 0033 desde la cuota 1: cada lote con su cuota de L 10,000.00 a 24 meses', function (): void {
    $capitales = [33 => new Monto('240000.00'), 34 => new Monto('240000.00')];

    $plan = PlanUnificado::armar($capitales, ($this->aMeses)(24)->cadaLoteConSuCuota(), 1);

    ($this->invariantes)($plan, $capitales);

    expect($plan->cadaLote)->toBeTrue()
        ->and($plan->meses())->toBe(24)
        ->and(array_unique(($this->montos)($plan->porLote[33])))->toBe(['10000.00'])
        ->and(array_unique(($this->montos)($plan->porLote[34])))->toBe(['10000.00'])
        ->and(array_unique(($this->montos)($plan->contrato->cuotas)))->toBe(['20000.00'])
        ->and($plan->contrato->cuotas[0]->vencimientoParaBase())->toBe('2026-07-15')
        ->and($plan->contrato->ultima()?->vencimientoParaBase())->toBe('2028-06-15');
});

it('🔴 el 0033 desde la próxima: L 200,000.00 por lote, el resto de cada uno a su última', function (): void {
    $capitales = [33 => new Monto('200000.00'), 34 => new Monto('200000.00')];

    $plan = PlanUnificado::armar($capitales, ($this->aMeses)(24)->cadaLoteConSuCuota(), 9);

    ($this->invariantes)($plan, $capitales);

    foreach ([33, 34] as $lote) {
        $montos = ($this->montos)($plan->porLote[$lote]);

        expect($montos)->toHaveCount(24)
            ->and(array_unique(array_slice($montos, 0, 23)))->toBe(['8333.33'])
            ->and($montos[23])->toBe('8333.41')
            ->and($plan->porLote[$lote][0]->numero)->toBe(9);
    }

    expect($plan->contrato->cuotas[0]->montoParaBase())->toBe('16666.66')
        ->and($plan->contrato->ultima()?->montoParaBase())->toBe('16666.82')
        ->and($plan->contrato->ultima()?->numero)->toBe(32);
});

it('cada lote con su cuota: dos capitales distintos dan dos cuotas distintas, mismos meses', function (): void {
    $capitales = [10 => new Monto('240000.00'), 11 => new Monto('120000.00')];

    $plan = PlanUnificado::armar($capitales, ($this->aMeses)(24)->cadaLoteConSuCuota(), 1);

    ($this->invariantes)($plan, $capitales);

    expect(array_unique(($this->montos)($plan->porLote[10])))->toBe(['10000.00'])
        ->and(array_unique(($this->montos)($plan->porLote[11])))->toBe(['5000.00'])
        ->and(array_unique(($this->montos)($plan->contrato->cuotas)))->toBe(['15000.00']);
});

it('con la cuota fija, la cuota es la de CADA lote, y el que debe menos termina antes', function (): void {
    $capitales = [10 => new Monto('240000.00'), 11 => new Monto('120000.00')];

    $condiciones = CondicionesDeReestructuracion::porCuota(
        new Monto('10000.00'),
        true,
        TasaDeInteres::cero(),
        $this->primero,
    )->cadaLoteConSuCuota();

    $plan = PlanUnificado::armar($capitales, $condiciones, 1);

    ($this->invariantes)($plan, $capitales);

    $contrato = ($this->montos)($plan->contrato->cuotas);

    expect($plan->porLote[10])->toHaveCount(24)
        ->and($plan->porLote[11])->toHaveCount(12)
        ->and($plan->meses())->toBe(24)
        // Los primeros doce meses pagan los dos; los otros doce, solo el que debe más.
        ->and(array_unique(array_slice($contrato, 0, 12)))->toBe(['20000.00'])
        ->and(array_unique(array_slice($contrato, 12)))->toBe(['10000.00']);
});

it('unificados, la misma cuenta se hace sobre el total y se reparte', function (): void {
    $capitales = [10 => new Monto('240000.00'), 11 => new Monto('120000.00')];

    $plan = PlanUnificado::armar($capitales, CondicionesDeReestructuracion::porCuota(
        new Monto('10000.00'),
        true,
        TasaDeInteres::cero(),
        $this->primero,
    ), 1);

    ($this->invariantes)($plan, $capitales);

    // L 360,000.00 a L 10,000.00 por mes: 36 meses para el contrato entero.
    expect($plan->cadaLote)->toBeFalse()
        ->and($plan->meses())->toBe(36)
        ->and(array_unique(($this->montos)($plan->contrato->cuotas)))->toBe(['10000.00']);
});

it('con un solo lote da lo mismo pedirlo de una manera o de la otra', function (): void {
    $capitales = [7 => new Monto('200000.00')];

    $unificado = PlanUnificado::armar($capitales, ($this->aMeses)(24), 3);
    $porLote = PlanUnificado::armar($capitales, ($this->aMeses)(24)->cadaLoteConSuCuota(), 3);

    expect(($this->montos)($porLote->porLote[7]))->toBe(($this->montos)($unificado->porLote[7]))
        ->and(($this->montos)($porLote->contrato->cuotas))->toBe(($this->montos)($unificado->contrato->cuotas));
});

it('con interés, cada lote es exactamente el plan que tendría solo', function (): void {
    $capitales = [10 => new Monto('240000.00'), 11 => new Monto('157321.45')];
    $condiciones = ($this->aMeses)(36, new TasaDeInteres('12'))->cadaLoteConSuCuota();

    $plan = PlanUnificado::armar($capitales, $condiciones, 1);

    ($this->invariantes)($plan, $capitales);

    $interes = Monto::cero();

    foreach ($capitales as $id => $capital) {
        $solo = PlanUnificado::delContrato($capital, $condiciones, 1);

        expect(($this->montos)($plan->porLote[$id]))->toBe(($this->montos)($solo->cuotas));

        $interes = $interes->sumar($solo->totalInteres());
    }

    expect($plan->totalInteres())->toBeMonto($interes->redondeado());
});

it('sin lotes no hay plan', function (): void {
    PlanUnificado::armar([], ($this->aMeses)(24)->cadaLoteConSuCuota(), 1);
})->throws(PlanDeCuotasInvalidoException::class);
