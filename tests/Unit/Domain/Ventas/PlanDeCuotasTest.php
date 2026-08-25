<?php

declare(strict_types=1);

use App\Domain\Exceptions\PlanDeCuotasInvalidoException;
use App\Domain\ValueObjects\Monto;
use App\Domain\Ventas\CuotaProyectada;
use App\Domain\Ventas\PlanDeCuotas;
use Carbon\CarbonImmutable;

/*
|--------------------------------------------------------------------------
| Motor de cuotas — R1 y R3 de docs/dominio.md
|--------------------------------------------------------------------------
| Test unitario a proposito: el motor no toca base de datos ni Laravel, y
| esa es justamente la razon por la que el formulario de venta puede
| mostrar el plan antes de guardarlo (§10.8).
|
| Sobre las fechas hardcodeadas: el §9.C2 prohibe fechas fijas en el
| PASADO para lo que dependa del calendario del sistema. Aca la fecha es un
| PARAMETRO de una funcion pura — necesitamos un 31 de enero de verdad para
| probar que febrero no desborda. No hay `now()` de por medio.
*/

// ─── El golden test del §9.C9 ────────────────────────────────────────

it('reproduce el golden test del documento rector al centimo', function (): void {
    // 250 varas² x L 1,400.00/vara² = L 350,000.00
    $valor = new Monto('1400.00')->multiplicarPor('250.0000');

    $plan = PlanDeCuotas::nuevo(
        valorTotal: $valor,
        prima: new Monto('100000.00'),
        plazoMeses: 72,
        diaPago: 5,
        fechaContrato: CarbonImmutable::parse('2026-08-03'),
    );

    expect($valor)->toBeMonto('350000.00')
        ->and($plan->saldoFinanciado)->toBeMonto('250000.00')
        ->and($plan)->toHaveCount(72)
        ->and($plan->cuotaMensual())->toBeMonto('3472.22')
        ->and($plan->ultima()?->monto)->toBeMonto('3472.38')
        ->and($plan->total())->toBeMonto('250000.00')
        ->and($plan->cierraExacto())->toBeTrue();

    // 71 x 3,472.22 = 246,527.62, y el residuo de 16 centavos va a la
    // ultima. Se escribe la aritmetica completa porque es EL numero que
    // el §9.C9 congela.
    $regulares = new Monto('3472.22')->multiplicarPor(71);

    expect($regulares)->toBeMonto('246527.62')
        ->and($regulares->sumar(new Monto('3472.38')))->toBeMonto('250000.00');
});

// ─── R1: la suma cierra siempre ──────────────────────────────────────

it('cierra exacto contra el saldo en cualquier combinacion de valor, prima y plazo', function (): void {
    $areas = ['250.0000', '613.0405', '390.9960', '840.5740', '1002.7500', '77.3333'];
    $precios = ['1400.00', '2530.00', '631.25', '4317.50', '899.99'];
    $porcentajesDePrima = ['0', '10', '25', '33', '50'];
    $plazos = [1, 2, 3, 12, 24, 36, 60, 72, 84, 120];

    $planes = 0;

    foreach ($areas as $area) {
        foreach ($precios as $precio) {
            $valor = new Monto($precio)->multiplicarPor($area);

            foreach ($porcentajesDePrima as $porcentaje) {
                $prima = new Monto($valor->aplicarPorcentaje($porcentaje)->redondeado());

                foreach ($plazos as $plazo) {
                    $plan = PlanDeCuotas::nuevo(
                        valorTotal: $valor,
                        prima: $prima,
                        plazoMeses: $plazo,
                        diaPago: 15,
                        fechaContrato: CarbonImmutable::parse('2026-08-03'),
                    );

                    expect($plan->cierraExacto())->toBeTrue(
                        "No cierra: area {$area}, precio {$precio}, prima {$porcentaje}%, plazo {$plazo}"
                    );

                    $planes++;
                }
            }
        }
    }

    expect($planes)->toBe(1500);
});

it('no genera ninguna cuota en cero ni negativa', function (): void {
    $plan = PlanDeCuotas::nuevo(
        valorTotal: new Monto('87654.33'),
        prima: new Monto('12345.67'),
        plazoMeses: 37,
        diaPago: 1,
        fechaContrato: CarbonImmutable::parse('2026-08-03'),
    );

    foreach ($plan->cuotas as $cuota) {
        expect($cuota->monto->esCero())->toBeFalse();
    }

    expect($plan->cierraExacto())->toBeTrue();
});

it('numera las cuotas de 1 a plazo, sin huecos', function (): void {
    $plan = PlanDeCuotas::nuevo(
        valorTotal: new Monto('100000.00'),
        prima: new Monto('20000.00'),
        plazoMeses: 24,
        diaPago: 10,
        fechaContrato: CarbonImmutable::parse('2026-08-03'),
    );

    expect(array_map(static fn (CuotaProyectada $cuota): int => $cuota->numero, $plan->cuotas))
        ->toBe(range(1, 24));
});

// ─── R1: no hay interes ──────────────────────────────────────────────

it('la suma de las cuotas es el saldo pelado: el financiamiento no agrega un centavo', function (): void {
    $plan = PlanDeCuotas::nuevo(
        valorTotal: new Monto('350000.00'),
        prima: new Monto('50000.00'),
        plazoMeses: 60,
        diaPago: 5,
        fechaContrato: CarbonImmutable::parse('2026-08-03'),
    );

    // Si algun dia alguien mete interes, este test es el que lo caza.
    expect($plan->total())->toBeMonto('300000.00');
});

// ─── Venta de contado ────────────────────────────────────────────────

it('devuelve un plan vacio cuando la prima cubre el valor completo', function (): void {
    $plan = PlanDeCuotas::nuevo(
        valorTotal: new Monto('350000.00'),
        prima: new Monto('350000.00'),
        plazoMeses: 0,
        diaPago: 5,
        fechaContrato: CarbonImmutable::parse('2026-08-03'),
    );

    expect($plan)->toHaveCount(0)
        ->and($plan->cuotaMensual())->toBeNull()
        ->and($plan->ultima())->toBeNull()
        ->and($plan->cierraExacto())->toBeTrue();
});

it('rechaza pedir cuotas cuando no queda saldo que financiar', function (): void {
    PlanDeCuotas::nuevo(
        valorTotal: new Monto('350000.00'),
        prima: new Monto('350000.00'),
        plazoMeses: 12,
        diaPago: 5,
        fechaContrato: CarbonImmutable::parse('2026-08-03'),
    );
})->throws(PlanDeCuotasInvalidoException::class, 'venta de contado va con plazo 0');

/*
| ─── El lote sin precio ──────────────────────────────────────────────
|
| Lo vio Mauricio el 24-ago-2026 en el modal del plano, con los lotes en
| L 0.00: las cinco filas de plazo decian «la prima cubre el valor completo».
| Cierto —cero cubre cero— y completamente inutil, porque manda a revisar la
| prima cuando lo que falta es el precio.
*/

it('rechaza el lote sin precio diciendo que le falta el precio', function (): void {
    PlanDeCuotas::nuevo(
        valorTotal: Monto::cero(),
        prima: Monto::cero(),
        plazoMeses: 12,
        diaPago: 5,
        fechaContrato: CarbonImmutable::parse('2026-08-03'),
    );
})->throws(PlanDeCuotasInvalidoException::class, 'todavia no tiene precio');

// Y tampoco pasa como «venta de contado de L 0.00»: un lote sin precio no se
// vende, ni siquiera al contado.
it('rechaza el lote sin precio aunque el plazo sea cero', function (): void {
    PlanDeCuotas::nuevo(
        valorTotal: Monto::cero(),
        prima: Monto::cero(),
        plazoMeses: 0,
        diaPago: 5,
        fechaContrato: CarbonImmutable::parse('2026-08-03'),
    );
})->throws(PlanDeCuotasInvalidoException::class, 'todavia no tiene precio');

// ─── Validaciones ────────────────────────────────────────────────────

it('rechaza una prima mayor que el valor de la venta', function (): void {
    PlanDeCuotas::nuevo(
        valorTotal: new Monto('100000.00'),
        prima: new Monto('100000.01'),
        plazoMeses: 12,
        diaPago: 5,
        fechaContrato: CarbonImmutable::parse('2026-08-03'),
    );
})->throws(PlanDeCuotasInvalidoException::class);

it('rechaza plazos fuera de rango', function (int $plazo): void {
    PlanDeCuotas::nuevo(
        valorTotal: new Monto('100000.00'),
        prima: new Monto('10000.00'),
        plazoMeses: $plazo,
        diaPago: 5,
        fechaContrato: CarbonImmutable::parse('2026-08-03'),
    );
})->with([-1, 0, 601])->throws(PlanDeCuotasInvalidoException::class);

it('rechaza dias de pago que no existen', function (int $dia): void {
    PlanDeCuotas::nuevo(
        valorTotal: new Monto('100000.00'),
        prima: new Monto('10000.00'),
        plazoMeses: 12,
        diaPago: $dia,
        fechaContrato: CarbonImmutable::parse('2026-08-03'),
    );
})->with([0, 32, -5])->throws(PlanDeCuotasInvalidoException::class);

it('se planta cuando el saldo es tan chico que la ultima cuota no cierra', function (): void {
    // L 17.70 en 60 cuotas: cada una redondea a L 0.30 y las 59 primeras
    // ya suman el saldo entero. Emitir ese plan seria emitir una cuota
    // final de cero.
    PlanDeCuotas::nuevo(
        valorTotal: new Monto('17.70'),
        prima: Monto::cero(),
        plazoMeses: 60,
        diaPago: 5,
        fechaContrato: CarbonImmutable::parse('2026-08-03'),
    );
})->throws(PlanDeCuotasInvalidoException::class, 'Bajar el plazo o subir la prima');

// ─── Fechas de vencimiento ───────────────────────────────────────────

it('vence la primera cuota el dia de pago del mes siguiente al contrato', function (): void {
    $plan = PlanDeCuotas::nuevo(
        valorTotal: new Monto('120000.00'),
        prima: new Monto('20000.00'),
        plazoMeses: 3,
        diaPago: 15,
        fechaContrato: CarbonImmutable::parse('2026-08-03'),
    );

    expect(array_map(static fn (CuotaProyectada $cuota): string => $cuota->vencimientoParaBase(), $plan->cuotas))
        ->toBe(['2026-09-15', '2026-10-15', '2026-11-15']);
});

it('acomoda el dia de pago 31 al ultimo dia de los meses cortos, sin arrastrar el corrimiento', function (): void {
    $plan = PlanDeCuotas::nuevo(
        valorTotal: new Monto('120000.00'),
        prima: new Monto('20000.00'),
        plazoMeses: 5,
        diaPago: 31,
        fechaContrato: CarbonImmutable::parse('2026-01-31'),
    );

    // Febrero cae al 28 y marzo VUELVE al 31. Si el calculo sumara meses
    // sobre la fecha anterior en vez de sobre el dia 1 del mes, todo el
    // plan quedaria pegado al 28.
    expect(array_map(static fn (CuotaProyectada $cuota): string => $cuota->vencimientoParaBase(), $plan->cuotas))
        ->toBe(['2026-02-28', '2026-03-31', '2026-04-30', '2026-05-31', '2026-06-30']);
});

it('respeta el anio bisiesto', function (): void {
    $plan = PlanDeCuotas::nuevo(
        valorTotal: new Monto('60000.00'),
        prima: Monto::cero(),
        plazoMeses: 2,
        diaPago: 30,
        fechaContrato: CarbonImmutable::parse('2028-01-15'),
    );

    expect($plan->cuotas[0]->vencimientoParaBase())->toBe('2028-02-29');
});

// ─── R3: el abono extraordinario acorta el plazo ─────────────────────

it('acorta el plazo sin tocar el monto de la cuota', function (): void {
    // Venta de L 250,000.00 a 72 cuotas de L 3,472.22. El cliente lleva 12
    // pagadas y abona fuerte: quedan L 100,000.00 de saldo.
    $plan = PlanDeCuotas::porCuotaFija(
        saldo: new Monto('100000.00'),
        cuota: new Monto('3472.22'),
        diaPago: 5,
        mesDelPrimerVencimiento: CarbonImmutable::parse('2027-09-01'),
    );

    // 100,000 / 3,472.22 = 28.8 → 29 cuotas: 28 iguales y la ultima menor.
    expect($plan)->toHaveCount(29)
        ->and($plan->cuotaMensual())->toBeMonto('3472.22')
        ->and($plan->ultima()?->monto)->toBeMonto('2777.84')
        ->and($plan->total())->toBeMonto('100000.00')
        ->and($plan->cierraExacto())->toBeTrue();

    // La regla de la contratante: la cuota NO baja. La ultima es menor
    // solo porque es el residuo.
    expect($plan->ultima()?->monto->menorQue(new Monto('3472.22')))->toBeTrue();
});

it('deja el plan en cero cuando el abono termina de pagar la venta', function (): void {
    $plan = PlanDeCuotas::porCuotaFija(
        saldo: Monto::cero(),
        cuota: new Monto('3472.22'),
        diaPago: 5,
        mesDelPrimerVencimiento: CarbonImmutable::parse('2027-09-01'),
    );

    // Sin cuotas de L 0.00 colgando (R3).
    expect($plan)->toHaveCount(0)
        ->and($plan->cierraExacto())->toBeTrue();
});

it('deja una sola cuota cuando el saldo es menor que la cuota pactada', function (): void {
    $plan = PlanDeCuotas::porCuotaFija(
        saldo: new Monto('1200.00'),
        cuota: new Monto('3472.22'),
        diaPago: 5,
        mesDelPrimerVencimiento: CarbonImmutable::parse('2027-09-01'),
    );

    expect($plan)->toHaveCount(1)
        ->and($plan->cuotas[0]->monto)->toBeMonto('1200.00');
});

it('no agrega una cuota de mas cuando el saldo es multiplo exacto de la cuota', function (): void {
    $plan = PlanDeCuotas::porCuotaFija(
        saldo: new Monto('10416.66'),
        cuota: new Monto('3472.22'),
        diaPago: 5,
        mesDelPrimerVencimiento: CarbonImmutable::parse('2027-09-01'),
    );

    expect($plan)->toHaveCount(3)
        ->and($plan->ultima()?->monto)->toBeMonto('3472.22');
});

it('rechaza una cuota fija en cero', function (): void {
    PlanDeCuotas::porCuotaFija(
        saldo: new Monto('100000.00'),
        cuota: Monto::cero(),
        diaPago: 5,
        mesDelPrimerVencimiento: CarbonImmutable::parse('2027-09-01'),
    );
})->throws(PlanDeCuotasInvalidoException::class);

it('cierra exacto tras cualquier abono, en cualquier punto del plan', function (): void {
    $cuota = new Monto('3472.22');

    foreach (['250000.00', '199999.99', '123456.78', '3472.23', '3472.21', '0.01', '87654.32'] as $saldo) {
        $plan = PlanDeCuotas::porCuotaFija(
            saldo: new Monto($saldo),
            cuota: $cuota,
            diaPago: 5,
            mesDelPrimerVencimiento: CarbonImmutable::parse('2027-09-01'),
        );

        expect($plan->cierraExacto())->toBeTrue("No cierra con saldo {$saldo}")
            ->and($plan->ultima()?->monto->mayorQue($cuota))->toBeFalse();
    }
});

/*
|--------------------------------------------------------------------------
| R21 · El otro camino: mismos meses, cuota mas baja
|--------------------------------------------------------------------------
| La contratante agrego en la reunion del 6-ago-2026 que el cliente ELIGE.
| `porCuotaFija` de arriba es «misma cuota, menos meses» (el default
| historico, R3); `porPlazoFijo` es el otro.
|
| Los numeros salen del mismo lote de siempre: 250 vr² a L 1,400.00 son
| L 350,000.00, con L 50,000.00 de prima quedan L 300,000.00 a 12 meses, o
| sea cuotas de L 25,000.00 exactas. Se pueden verificar sin calculadora.
*/

describe('Bajar la cuota manteniendo los meses', function (): void {
    it('reparte el saldo nuevo entre los meses que quedaban', function (): void {
        // Quedaban 8 cuotas de L 25,000.00 (L 200,000.00) y abono L 40,000.00.
        $plan = PlanDeCuotas::porPlazoFijo(
            saldo: new Monto('160000.00'),
            plazoMeses: 8,
            diaPago: 5,
            mesDelPrimerVencimiento: CarbonImmutable::parse('2027-01-01'),
        );

        expect($plan)->toHaveCount(8)
            ->and($plan->cuotaMensual())->toBeMonto('20000.00')
            ->and($plan->total())->toBeMonto('160000.00')
            ->and($plan->cierraExacto())->toBeTrue();
    });

    it('manda el residuo a la ultima, igual que los otros dos constructores', function (): void {
        $plan = PlanDeCuotas::porPlazoFijo(
            saldo: new Monto('100000.00'),
            plazoMeses: 7,
            diaPago: 5,
            mesDelPrimerVencimiento: CarbonImmutable::parse('2027-01-01'),
        );

        // 100000 / 7 = 14285.714... → 14285.71 x 6 = 85714.26, residuo 14285.74
        expect($plan->cuotaMensual())->toBeMonto('14285.71')
            ->and($plan->ultima()?->monto)->toBeMonto('14285.74')
            ->and($plan->cierraExacto())->toBeTrue();
    });

    it('cierra exacto en cualquier combinacion de saldo y meses', function (): void {
        $saldos = ['160000.00', '199999.99', '123456.78', '87654.32', '1.03', '50000.01'];
        $plazos = [1, 2, 3, 7, 12, 24, 47, 60];

        foreach ($saldos as $saldo) {
            foreach ($plazos as $plazo) {
                try {
                    $plan = PlanDeCuotas::porPlazoFijo(
                        saldo: new Monto($saldo),
                        plazoMeses: $plazo,
                        diaPago: 15,
                        mesDelPrimerVencimiento: CarbonImmutable::parse('2027-01-01'),
                    );
                } catch (PlanDeCuotasInvalidoException) {
                    // L 1.03 en 60 meses no da un plan: da 60 cuotas de un
                    // centavo y pico que no cierran. Rechazarlo es el
                    // comportamiento correcto, no un agujero del test.
                    continue;
                }

                expect($plan->cierraExacto())->toBeTrue("No cierra: saldo {$saldo}, plazo {$plazo}");
            }
        }
    });

    /*
    | El agujero que este constructor tapo: una cuota base de L 0.00 pasaba de
    | largo y la frenaba el CHECK `cuotas_monto_positivo_chk` de Postgres. O
    | sea un error de base de datos en la cara del usuario, en vez de una
    | frase que le diga que hacer.
    */
    it('rechaza un saldo de centavos repartido entre muchos meses', function (): void {
        PlanDeCuotas::porPlazoFijo(
            saldo: new Monto('0.05'),
            plazoMeses: 12,
            diaPago: 5,
            mesDelPrimerVencimiento: CarbonImmutable::parse('2027-01-01'),
        );
    })->throws(PlanDeCuotasInvalidoException::class);

    it('devuelve un plan vacio si el abono cancelo el saldo', function (): void {
        $plan = PlanDeCuotas::porPlazoFijo(
            saldo: Monto::cero(),
            plazoMeses: 8,
            diaPago: 5,
            mesDelPrimerVencimiento: CarbonImmutable::parse('2027-01-01'),
        );

        // Ninguna cuota de L 0.00 colgando (R3).
        expect($plan)->toHaveCount(0)
            ->and($plan->cuotaMensual())->toBeNull()
            ->and($plan->cierraExacto())->toBeTrue();
    });
});

/*
|--------------------------------------------------------------------------
| R21 · Un plan reprogramado no empieza en la cuota 1
|--------------------------------------------------------------------------
| Las cuotas pagadas —y la que quedo a medias— se respetan tal como estan, y
| el plan nuevo empieza en la siguiente. Renumerar desde 1 chocaria contra el
| indice unico `cuotas_numero_por_lote_uidx` y dejaria el recibo viejo
| apuntando a una cuota 5 que ahora significa otra cosa.
*/

describe('Numerar desde donde arranca el plan nuevo', function (): void {
    it('sigue la numeracion del lote al acortar el plazo', function (): void {
        $plan = PlanDeCuotas::porCuotaFija(
            saldo: new Monto('75000.00'),
            cuota: new Monto('25000.00'),
            diaPago: 5,
            mesDelPrimerVencimiento: CarbonImmutable::parse('2027-02-01'),
            primerNumero: 6,
        );

        $numeros = array_map(static fn (CuotaProyectada $cuota): int => $cuota->numero, $plan->cuotas);

        expect($numeros)->toBe([6, 7, 8])
            ->and($plan->cuotas[0]->vencimiento->toDateString())->toBe('2027-02-05');
    });

    it('sigue la numeracion del lote al bajar la cuota', function (): void {
        $plan = PlanDeCuotas::porPlazoFijo(
            saldo: new Monto('60000.00'),
            plazoMeses: 4,
            diaPago: 5,
            mesDelPrimerVencimiento: CarbonImmutable::parse('2027-02-01'),
            primerNumero: 9,
        );

        $numeros = array_map(static fn (CuotaProyectada $cuota): int => $cuota->numero, $plan->cuotas);

        expect($numeros)->toBe([9, 10, 11, 12])
            ->and($plan->cuotaMensual())->toBeMonto('15000.00');
    });

    it('empieza en 1 cuando nadie pide otra cosa', function (): void {
        $plan = PlanDeCuotas::nuevo(
            valorTotal: new Monto('350000.00'),
            prima: new Monto('50000.00'),
            plazoMeses: 12,
            diaPago: 5,
            fechaContrato: CarbonImmutable::parse('2026-08-06'),
        );

        expect($plan->cuotas[0]->numero)->toBe(1)
            ->and($plan->ultima()?->numero)->toBe(12)
            ->and($plan->cuotaMensual())->toBeMonto('25000.00');
    });
});
