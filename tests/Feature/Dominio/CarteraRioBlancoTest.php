<?php

declare(strict_types=1);

use App\Domain\Enums\EstadoLote;
use App\Domain\Enums\EstadoVenta;
use App\Domain\ValueObjects\Monto;
use App\Models\Cuota;
use App\Models\Lote;
use App\Models\Proyecto;
use App\Models\Recibo;
use App\Models\Venta;
use Carbon\CarbonImmutable;
use Database\Seeders\CarteraRioBlancoSeeder;
use Database\Seeders\Clientes\ColoniaRioBlancoSeeder;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| La cartera de Residencial Río Blanco — el golden test del cuaderno
|--------------------------------------------------------------------------
| Nueve expedientes transcritos el 28-sep-2026. Cada saldo de acá es el
| último que anota el cuaderno en su historial, al centavo —salvo el 0007,
| donde la ficha se equivoca en una resta, y el 0008, cerrado con un
| descuento—: si una carga nueva no da estos números, el cargador o el dato
| cambiaron, y eso se mira antes de subir.
|
| Se viaja al 28-sep-2026 porque lo vencido depende del día.
*/

function cargarLaCarteraDeRioBlanco(): Proyecto
{
    test()->travelTo(CarbonImmutable::parse('2026-09-28 10:00:00'));

    app(ColoniaRioBlancoSeeder::class)->run();
    app(CarteraRioBlancoSeeder::class)->run();

    /** @var Proyecto $proyecto */
    $proyecto = Proyecto::query()->where('codigo', 'RRB')->sole();

    return $proyecto;
}

function ventaDeRioBlanco(Proyecto $proyecto, int $expediente): Venta
{
    /** @var Venta $venta */
    $venta = Venta::query()
        ->where('proyecto_id', $proyecto->getKey())
        ->where('numero_expediente', $expediente)
        ->sole();

    return $venta;
}

describe('Cartera de Residencial Río Blanco', function (): void {
    test('entran los nueve expedientes con el número de contrato del cuaderno', function (): void {
        $proyecto = cargarLaCarteraDeRioBlanco();

        $contratos = Venta::query()
            ->where('proyecto_id', $proyecto->getKey())
            ->orderBy('numero_expediente')
            ->pluck('numero_contrato')
            ->all();

        expect($contratos)->toBe([
            'RRB-2025-0002', 'RRB-2025-0003', 'RRB-2025-0004', 'RRB-2025-0005', 'RRB-2025-0007',
            'RRB-2025-0008', 'RRB-2026-0009', 'RRB-2026-0010', 'RRB-2026-0011',
        ])
            ->and(Lote::query()
                ->where('proyecto_id', $proyecto->getKey())
                ->where('estado', EstadoLote::Vendido->value)
                ->count())->toBe(16)
            // El próximo contrato sigue la cuenta del cuaderno: 0012.
            ->and((int) DB::table('correlativos')
                ->where('proyecto_id', $proyecto->getKey())
                ->where('tipo', 'contrato')
                ->value('ultimo_numero'))->toBe(11);
    });

    test('cada saldo es el último que anota el cuaderno', function (): void {
        $proyecto = cargarLaCarteraDeRioBlanco();

        $saldos = [];
        $valor = Monto::cero();

        foreach (Venta::query()->where('proyecto_id', $proyecto->getKey())->orderBy('numero_expediente')->get() as $venta) {
            $saldos[(int) $venta->getAttribute('numero_expediente')] = $venta->saldoPendiente()->redondeado();
            $valor = $valor->sumar(new Monto((string) $venta->getAttribute('valor_total')));
        }

        expect($saldos)->toBe([
            2  => '100000.00',
            3  => '200000.00',
            4  => '650000.00',
            5  => '562500.00',
            7  => '1462400.00',
            8  => '0.00',
            9  => '298835.00',
            10 => '292500.00',
            11 => '477600.00',
        ])
            ->and($valor->redondeado())->toBe('9870900.00');
    });

    test('el 0009 conserva la cuota de L 9,055.00 y los L 20.00 van en la última', function (): void {
        $venta = ventaDeRioBlanco(cargarLaCarteraDeRioBlanco(), 9);

        /** @var list<string> $cuotas */
        $cuotas = Cuota::query()
            ->where('venta_id', $venta->getKey())
            ->orderBy('numero')
            ->pluck('monto')
            ->all();

        $vencidas = Cuota::query()
            ->where('venta_id', $venta->getKey())
            ->whereDate('fecha_vencimiento', '<', '2026-09-28')
            ->whereColumn('monto_pagado', '<', 'monto')
            ->count();

        expect($cuotas)->toHaveCount(36)
            ->and(array_unique(array_slice($cuotas, 0, 35)))->toBe(['9055.00'])
            ->and($cuotas[35])->toBe('9075.00')
            ->and((string) $venta->getAttribute('valor_total'))->toBe('336000.00')
            ->and($vencidas)->toBe(0);
    });

    test('el 0008 queda liquidado con un pronto pago de L 312,000.00 y L 400.00 de descuento', function (): void {
        $venta = ventaDeRioBlanco(cargarLaCarteraDeRioBlanco(), 8);

        $ultimo = Recibo::query()
            ->where('venta_id', $venta->getKey())
            ->orderByDesc('id')
            ->firstOrFail();

        expect($venta->getAttribute('estado'))->toBe(EstadoVenta::Liquidada)
            ->and((string) $ultimo->getAttribute('monto'))->toBe('312000.00')
            ->and($ultimo->getAttribute('serie'))->toBeNull()
            ->and((string) $venta->getAttribute('valor_total'))->toBe('592400.00');
    });

    test('todos los papeles salen de la serie de antes del sistema', function (): void {
        $proyecto = cargarLaCarteraDeRioBlanco();

        $ventas = Venta::query()->where('proyecto_id', $proyecto->getKey())->pluck('id');

        $recibos = Recibo::query()->whereIn('venta_id', $ventas);

        expect((clone $recibos)->count())->toBeGreaterThan(0)
            ->and((clone $recibos)->whereNotNull('serie')->count())->toBe(0);
    });

    test('correrla dos veces no duplica nada', function (): void {
        $proyecto = cargarLaCarteraDeRioBlanco();
        $recibos = Recibo::query()->count();

        app(CarteraRioBlancoSeeder::class)->run();

        expect(Venta::query()->where('proyecto_id', $proyecto->getKey())->count())->toBe(9)
            ->and(Recibo::query()->count())->toBe($recibos);
    });
});
