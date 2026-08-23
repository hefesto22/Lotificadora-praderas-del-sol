<?php

declare(strict_types=1);

use App\Models\Cuota;

/*
|--------------------------------------------------------------------------
| La cuota viene partida — 22-ago-2026
|--------------------------------------------------------------------------
| `monto` es lo que el cliente paga ese mes; `monto_capital` y
| `monto_interes` son en qué se descompone. La base EXIGE que sumen exacto
| (`cuotas_partes_suman_el_monto_chk`) y que el capital no sea nulo.
|
| 🔴 POR QUÉ EXISTE ESTE ARCHIVO
|
| La factory de cuotas dejaba `monto_capital` en null desde que la columna
| pasó a NOT NULL, el 8-ago. Nadie se enteró porque NINGÚN test la usaba, y
| reventó de golpe el 22-ago —ocho tests en rojo— con un error de Postgres
| que no menciona ni la cuota ni el plan de pago.
|
| Una factory que solo se prueba de rebote, el día que a alguien se le
| ocurre usarla, no está probada. Estos tres la ejercen de frente.
*/

test('la cuota de fábrica es una que la base acepta', function (): void {
    $cuota = Cuota::factory()->create()->refresh();

    // El caso base del §9.C9 sin interés (R1): el capital ES la cuota.
    expect($cuota->getAttribute('monto_capital'))->toBe('3472.22')
        ->and($cuota->getAttribute('monto_interes'))->toBe('0.00');
});

test('cambiar el monto arrastra el capital', function (): void {
    /*
     * El que rompía. Un test que solo quería otro número se llevaba el
     * CHECK por delante: 3472.22 de capital contra 5000.00 de monto no
     * suman, y el mensaje de Postgres no dice de dónde salió el 3472.22.
     */
    $cuota = Cuota::factory()->create(['monto' => '5000.00'])->refresh();

    expect($cuota->getAttribute('monto_capital'))->toBe('5000.00');
});

test('si el test reparte las dos partes a mano, manda el test', function (): void {
    // Una cuota con interés (R1 no es la única regla posible): acá el
    // capital NO es el monto, y la factory no tiene por qué opinar.
    $cuota = Cuota::factory()->create([
        'monto'         => '1000.00',
        'monto_capital' => '900.00',
        'monto_interes' => '100.00',
    ])->refresh();

    expect($cuota->getAttribute('monto_capital'))->toBe('900.00')
        ->and($cuota->getAttribute('monto_interes'))->toBe('100.00');
});
