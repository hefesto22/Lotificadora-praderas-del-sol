<?php

declare(strict_types=1);

use App\Domain\Enums\FormaDePago;
use App\Domain\Enums\ModalidadDeReprogramacion;
use App\Domain\Pagos\RegistroDePagos;
use App\Domain\ValueObjects\Monto;
use App\Domain\Ventas\RegistroDeVentas;
use App\Models\AplicacionDePago;
use App\Models\Bloque;
use App\Models\Cliente;
use App\Models\Cuota;
use App\Models\Lote;
use App\Models\Proyecto;

/*
|--------------------------------------------------------------------------
| `olympo:cuadrar-recibos` — 27-ago-2026
|--------------------------------------------------------------------------
| El comando repara lo que dejó el defecto del modo «Ambas»: un recibo que
| cobró más de lo que aplicó. El caso real es el RPS-00000005 de Praderas —
| L 24,000.00 en el papel, L 17,020.83 movidos, L 6,979.17 en el aire.
|
| 🔴 COMO SE FABRICA EL DAÑO ACA
|
| El defecto ya está arreglado, así que un cobro normal sale bien. Para tener
| el estado roto se le BORRA al recibo la aplicación que el paso 5 escribe y
| se le devuelve la cuota a cero: es exactamente la fila que faltaba en
| producción, ni una más.
|
| Un lote de 250 vr² a L 1,400.00 son L 350,000.00; con L 50,000.00 de prima
| quedan L 300,000.00 a 12 meses, cuotas de L 25,000.00 exactas.
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

    // Dos cuotas vencidas, se marca UNA sola y el resto entra como abono:
    // 25,000 ponen al día la que quedó y 10,000 bajan el capital.
    Cuota::query()
        ->where('compromiso_id', $this->renglon->getKey())
        ->whereIn('numero', [1, 2])
        ->update(['fecha_vencimiento' => today()->subMonths(2)->toDateString()]);

    $recibos = $this->pagos->cobrarYAbonar(
        venta: $this->venta,
        cliente: $this->cliente,
        cuotas: [['lote' => $this->renglon, 'monto' => new Monto('25000.00')]],
        loteDelAbono: $this->renglon,
        aCapital: new Monto('35000.00'),
        modalidad: ModalidadDeReprogramacion::AcortarPlazo,
        motivo: 'Abono a capital solicitado por el cliente',
        forma: FormaDePago::Efectivo,
    );

    $this->recibo = $recibos[0];

    $this->romper = function (): void {
        $cuota = Cuota::query()
            ->where('compromiso_id', $this->renglon->getKey())
            ->where('numero', 2)
            ->firstOrFail();

        AplicacionDePago::query()->where('cuota_id', $cuota->getKey())->delete();
        $cuota->update(['monto_pagado' => '0.00']);
    };
});

test('con todo en orden no encuentra nada y devuelve éxito', function (): void {
    $this->artisan('olympo:cuadrar-recibos')
        ->expectsOutputToContain('Todos los recibos cuadran')
        ->assertSuccessful();
});

test('encuentra el recibo que cobró más de lo que aplicó, y sin --reparar no escribe nada', function (): void {
    ($this->romper)();

    $this->artisan('olympo:cuadrar-recibos')
        ->expectsOutputToContain('1 recibo(s) no cuadran')
        ->assertFailed();

    // Sigue roto: mirar no repara.
    expect($this->recibo->refresh()->descuadre())->toBeMonto('25000.00')
        ->and($this->venta->refresh()->saldoPendiente())->toBeMonto('265000.00');
});

test('--reparar le escribe el pago que faltaba y el saldo vuelve a cuadrar', function (): void {
    ($this->romper)();

    $this->artisan('olympo:cuadrar-recibos', ['--reparar' => true])
        ->assertSuccessful();

    $recibo = $this->recibo->refresh();

    expect($recibo->cuadra())->toBeTrue()
        ->and($recibo->montoAplicadoACuotas())->toBeMonto('50000.00')
        ->and($recibo->montoACapital())->toBeMonto('10000.00');

    // La cuota que el cliente había pagado deja de aparecerle pendiente.
    expect(Cuota::query()
        ->where('compromiso_id', $this->renglon->getKey())
        ->where('numero', 2)
        ->value('monto_pagado'))->toBe('25000.00');

    // Y el saldo es el que siempre debió ser: 300,000 − 60,000.
    expect($this->venta->refresh()->saldoPendiente())->toBeMonto('240000.00');
});
