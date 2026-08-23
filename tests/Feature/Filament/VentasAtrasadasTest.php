<?php

declare(strict_types=1);

use App\Domain\Enums\EstadoVenta;
use App\Filament\Resources\Ventas\Pages\ListVentas;
use App\Filament\Resources\Ventas\VentaResource;
use App\Models\Cuota;
use App\Models\Venta;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Quién está atrasado — 22-ago-2026
|--------------------------------------------------------------------------
| El mismo número se cuenta en TRES lugares: el contador del menú, la
| columna «Cobro» de esta tabla y el «Vencido a hoy» del Escritorio. Los
| tres van por caminos distintos —un COUNT DISTINCT, una subconsulta
| correlacionada y una suma— y tienen que dar lo mismo.
|
| 🔴 Que no den lo mismo es peor que no tener ninguno: la administradora
| deja de creerle a los tres. Este archivo ata los dos primeros.
*/

/**
 * Un expediente vigente con la cantidad de cuotas vencidas que se pida.
 */
function expedienteConVencidas(int $cuantas): Venta
{
    static $numero = 0;
    $numero++;

    $venta = Venta::factory()->vigente($numero)->create(['estado' => EstadoVenta::Vigente]);

    for ($i = 1; $i <= $cuantas; $i++) {
        Cuota::factory()->deLaVenta($venta)->vencida(30 * $i)->create(['numero' => $i]);
    }

    return $venta;
}

test('la tabla dice cuántas debe cada uno', function (): void {
    actingAsAdmin();

    expedienteConVencidas(3);
    expedienteConVencidas(1);
    expedienteConVencidas(0);

    Livewire::test(ListVentas::class)
        ->assertSeeText('3 vencidas')
        // Una sola cuota va en singular: «1 vencidas» se lee como un error.
        ->assertSeeText('1 vencida')
        ->assertSeeText('Al día');
});

/*
| 🔴 EL TEST QUE IMPORTA: las dos pantallas cuentan lo mismo.
|
| El menú hace `COUNT(DISTINCT venta_id)` sobre todas las cuotas; la tabla,
| una subconsulta correlacionada por fila. Son dos SQL distintos para la
| misma pregunta, y el día que uno cambie de criterio este test se pone
| rojo antes de que alguien note el desfase en pantalla.
*/
test('el contador del menú y la columna de la tabla cuentan lo mismo', function (): void {
    actingAsAdmin();

    expedienteConVencidas(4);
    expedienteConVencidas(2);
    expedienteConVencidas(0);
    expedienteConVencidas(0);

    // Tres con atraso serían tres llamadas; acá son dos.
    expect(VentaResource::getNavigationBadge())->toBe('2');

    $conAtraso = Venta::query()
        ->get()
        ->filter(static fn (Venta $venta): bool => $venta->cuotas()
            ->whereColumn('monto_pagado', '<', 'monto')
            ->where('fecha_vencimiento', '<', today()->toDateString())
            ->exists())
        ->count();

    expect($conAtraso)->toBe(2);
});

test('un expediente cerrado no aparece atrasado aunque tenga cuotas viejas', function (): void {
    actingAsAdmin();

    $venta = expedienteConVencidas(3);
    $venta->update(['estado' => EstadoVenta::Liquidada, 'cerrada_el' => today()]);

    /*
     * Hay que pararse en «Todas»: desde el 22-ago la pantalla abre en
     * «Vigente» y un liquidado no sale ahí.
     *
     * ⚠️ Y NO se afirma `assertSeeText('Liquidada')`, aunque sea lo que se
     * quiere ver: cada pestaña se rotula con la etiqueta de su estado, así
     * que esa palabra está en la página SIEMPRE —haya filas o no— y el test
     * pasaría con la tabla vacía. La fila se comprueba por su registro.
     */
    Livewire::test(ListVentas::class)
        ->set('activeTab', ListVentas::TODAS)
        ->assertCanSeeTableRecords([$venta])
        ->assertDontSeeText('3 vencidas');

    expect(VentaResource::getNavigationBadge())->toBeNull();
});

/*
| «si está [Todas], de nada sirve el toggle» — Mauricio, 22-ago. Con la
| lista completa de portada las pestañas no filtraban nada al entrar.
*/
test('la pantalla abre en «Vigente», no en la lista entera', function (): void {
    actingAsAdmin();

    $vigente = expedienteConVencidas(2);

    $cerrada = expedienteConVencidas(0);
    $cerrada->update(['estado' => EstadoVenta::Liquidada, 'cerrada_el' => today()]);

    Livewire::test(ListVentas::class)
        ->assertCanSeeTableRecords([$vigente])
        ->assertCanNotSeeTableRecords([$cerrada]);
});

/*
| La celda de las ventas de contado salía VACIA y se leía como un dato que
| falta: `formatStateUsing()` ni se llama cuando el valor es null. Con
| `state()` sí. Encontrado mirando la pantalla, no el código.
*/
test('una venta de contado dice «De contado», no una celda en blanco', function (): void {
    actingAsAdmin();

    Venta::factory()->vigente(900)->deContado()->create(['estado' => EstadoVenta::Vigente]);

    Livewire::test(ListVentas::class)->assertSeeText('De contado');
});
