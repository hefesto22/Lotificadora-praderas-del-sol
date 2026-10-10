<?php

declare(strict_types=1);

use App\Domain\Enums\ConceptoDeRecibo;
use App\Domain\Enums\FormaDePago;
use App\Domain\Reportes\CierreDelMes;
use App\Models\Cliente;
use App\Models\Proyecto;
use App\Models\Recibo;
use App\Models\User;
use App\Models\Venta;
use Carbon\CarbonImmutable;

/*
|--------------------------------------------------------------------------
| Quién recibió el dinero del mes, y en qué forma — 10-oct-2026
|--------------------------------------------------------------------------
| Mauricio: «cuánto dinero recibió cada uno, en pagos y en qué tipo de pagos,
| ya que ellos dos reciben el dinero». Sale de `recibos.recibido_por` (R24).
|
| Lo que cuidan estos tests: que cada persona tenga lo suyo por forma, que la
| suma de todos sea EXACTAMENTE lo cobrado —con los mismos filtros que los
| ingresos—, y que la hoja lo imprima.
*/

beforeEach(function (): void {
    actingAsAdmin();

    $this->proyecto = Proyecto::factory()->create(['codigo' => 'RPS']);
    $this->mes = CarbonImmutable::parse('2026-07-15');

    $this->elder = User::factory()->create(['name' => 'Elder Dionel', 'is_active' => true]);
    $this->edwin = User::factory()->create(['name' => 'Edwin Adonay', 'is_active' => true]);

    $this->cobrar = function (User $quien, string $monto, FormaDePago $forma, array $extra = []): Recibo {
        $venta = Venta::factory()->create(['proyecto_id' => $this->proyecto->getKey()]);

        return Recibo::factory()->create(array_merge([
            'venta_id'     => $venta->getKey(),
            'cliente_id'   => Cliente::factory()->create()->getKey(),
            'concepto'     => ConceptoDeRecibo::Cuota,
            'forma_pago'   => $forma,
            'referencia'   => $forma === FormaDePago::Efectivo ? null : 'REF-123',
            'monto'        => $monto,
            'fecha'        => '2026-07-10',
            'recibido_por' => $quien->getKey(),
        ], $extra));
    };
});

test('cada persona con lo que recibió en cada forma, de mayor a menor', function (): void {
    ($this->cobrar)($this->elder, '1000.00', FormaDePago::Efectivo);
    ($this->cobrar)($this->elder, '500.00', FormaDePago::Transferencia);
    ($this->cobrar)($this->edwin, '2000.00', FormaDePago::Efectivo);
    ($this->cobrar)($this->edwin, '250.00', FormaDePago::Efectivo);

    [$primero, $segundo] = CierreDelMes::de($this->proyecto, $this->mes)->cobradoPorPersona;

    expect($primero['nombre'])->toBe('EDWIN ADONAY')
        ->and($primero['total'])->toBeMonto('2250.00')
        ->and($primero['porForma'])->toHaveCount(1)
        ->and($primero['porForma'][FormaDePago::Efectivo->value])->toBeMonto('2250.00')
        ->and($segundo['nombre'])->toBe('ELDER DIONEL')
        ->and($segundo['total'])->toBeMonto('1500.00')
        ->and($segundo['porForma'][FormaDePago::Efectivo->value])->toBeMonto('1000.00')
        ->and($segundo['porForma'][FormaDePago::Transferencia->value])->toBeMonto('500.00');
});

/*
| La tabla tiene que sumar lo cobrado: los anulados y los de otro mes no
| cuentan, igual que en los ingresos. Si no, la hoja dice dos totales.
*/
test('suma exactamente lo cobrado: sin anulados ni recibos de otro mes', function (): void {
    ($this->cobrar)($this->elder, '1000.00', FormaDePago::Efectivo);
    ($this->cobrar)($this->elder, '9999.00', FormaDePago::Efectivo, [
        'anulado_el'       => '2026-07-11',
        'motivo_anulacion' => 'Error de carga.',
    ]);
    ($this->cobrar)($this->edwin, '7777.00', FormaDePago::Efectivo, ['fecha' => '2026-08-02']);

    $cierre = CierreDelMes::de($this->proyecto, $this->mes);

    expect($cierre->cobradoPorPersona)->toHaveCount(1)
        ->and($cierre->cobradoPorPersona[0]['total'])->toBeMonto('1000.00')
        ->and($cierre->cobradoDelMes)->toBeMonto('1000.00');
});

test('la hoja impresa trae la tabla de quién recibió el dinero', function (): void {
    ($this->cobrar)($this->elder, '1000.00', FormaDePago::Efectivo);
    ($this->cobrar)($this->edwin, '500.00', FormaDePago::Transferencia);

    $this->get(route('documentos.estado-mensual', ['proyecto' => $this->proyecto, 'mes' => '2026-07']))
        ->assertOk()
        ->assertSeeText('Quién recibió el dinero')
        ->assertSeeText('ELDER DIONEL')
        ->assertSeeText('EDWIN ADONAY');
});
