<?php

declare(strict_types=1);

use App\Domain\ValueObjects\Monto;
use App\Domain\Ventas\CoberturaDelPlan;
use App\Domain\Ventas\CondicionesDeReestructuracion;
use App\Domain\Ventas\PlanUnificado;
use App\Domain\Ventas\TasaDeInteres;
use Carbon\CarbonImmutable;

/*
|--------------------------------------------------------------------------
| Lo ya pagado, puesto sobre el plan nuevo
|--------------------------------------------------------------------------
| Traído de Maya (26-sep-2026). Unificados, el dinero llena el contrato mes
| por mes y lote por lote. Con cada lote por su lado (Praderas, 25-oct-2026),
| lo de un lote llena SU plan y no toca el otro.
*/

beforeEach(function (): void {
    $this->capitales = [33 => new Monto('240000.00'), 34 => new Monto('240000.00')];
    $this->primero = CarbonImmutable::parse('2026-07-15');

    $this->plan = fn (bool $cadaLote): PlanUnificado => PlanUnificado::armar(
        $this->capitales,
        $cadaLote
            ? CondicionesDeReestructuracion::porMeses(24, true, TasaDeInteres::cero(), $this->primero)->cadaLoteConSuCuota()
            : CondicionesDeReestructuracion::porMeses(24, true, TasaDeInteres::cero(), $this->primero),
        1,
    );
});

it('🔴 el 0033: L 40,000.00 de cada lote cubren sus cuatro primeras cuotas, y nada del otro', function (): void {
    $cobertura = CoberturaDelPlan::de(($this->plan)(true));

    $deUno = $cobertura->aplicarAlLote(33, new Monto('40000.00'));
    $deOtro = $cobertura->aplicarAlLote(34, new Monto('40000.00'));

    expect($deUno['sobra'])->toBeMonto('0.00')
        ->and($deOtro['sobra'])->toBeMonto('0.00')
        ->and(array_unique(array_map(static fn (array $pieza): int => $pieza['lote'], $deUno['piezas'])))->toBe([33])
        ->and(array_map(static fn (array $pieza): int => $pieza['numero'], $deUno['piezas']))->toBe([1, 2, 3, 4])
        ->and($cobertura->mesesCubiertos())->toBe(4)
        ->and($cobertura->ultimaCubierta(33))->toBe(['lote' => 33, 'numero' => 4])
        ->and($cobertura->ultimaCubierta(34))->toBe(['lote' => 34, 'numero' => 4]);

    $debe = $cobertura->primerMesQueDebe();

    expect($debe['numero'] ?? null)->toBe(5)
        ->and($debe['falta'] ?? null)->toBeMonto('20000.00');
});

it('lo que pagó un lote no llena la cuota del otro, aunque el otro deba', function (): void {
    $cobertura = CoberturaDelPlan::de(($this->plan)(true));

    $cobertura->aplicarAlLote(33, new Monto('40000.00'));

    $debe = $cobertura->primerMesQueDebe();

    // El mes 1 lo debe el otro lote entero: L 10,000.00.
    expect($cobertura->cubiertoDelMes(1))->toBeMonto('10000.00')
        ->and($cobertura->mesesCubiertos())->toBe(0)
        ->and($cobertura->ultimaCubierta(34))->toBeNull()
        ->and($debe['numero'] ?? null)->toBe(1)
        ->and($debe['falta'] ?? null)->toBeMonto('10000.00');
});

it('unificados, el mismo dinero sí pasa al otro lote: llena el mes antes de seguir', function (): void {
    $cobertura = CoberturaDelPlan::de(($this->plan)(false));

    $resultado = $cobertura->aplicar(new Monto('40000.00'));

    expect(array_map(static fn (array $pieza): string => $pieza['lote'].'-'.$pieza['numero'], $resultado['piezas']))
        ->toBe(['33-1', '34-1', '33-2', '34-2'])
        ->and($cobertura->mesesCubiertos())->toBe(2);
});

it('lo que no cabe en el plan de ESE lote vuelve como sobra', function (): void {
    $cobertura = CoberturaDelPlan::de(($this->plan)(true));

    $resultado = $cobertura->aplicarAlLote(33, new Monto('250000.00'));

    expect($resultado['sobra'])->toBeMonto('10000.00')
        ->and($cobertura->totalDelLote(33))->toBeMonto('240000.00')
        ->and($cobertura->totalDelLote(34))->toBeMonto('240000.00')
        ->and($cobertura->total())->toBeMonto('480000.00')
        ->and($cobertura->cubierto())->toBeMonto('240000.00');
});
