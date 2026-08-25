<?php

declare(strict_types=1);

use App\Domain\Enums\EstadoLote;
use App\Domain\ValueObjects\Monto;
use App\Domain\Ventas\RegistroDeVentas;
use App\Models\Bloque;
use App\Models\Cliente;
use App\Models\Lote;
use App\Models\PlanDePago;
use App\Models\Proyecto;
use App\Models\Venta;

/*
|--------------------------------------------------------------------------
| `olympo:limpiar-cartera` — 24-ago-2026
|--------------------------------------------------------------------------
| El comando existe para UN momento: la lotificadora probó el sistema con
| ventas de mentira y llega el día de cargar la cartera de verdad. Borra sin
| poder deshacer, así que hasta hoy no tenía un solo test — y el día que se
| usó en el servidor se descubrió por qué hacía falta.
|
| 🔴 EL BUG QUE ESTOS TESTS FIJAN
|
| `--planes` se iba en silencio cuando la cartera ya estaba en cero: el corte
| temprano («no hay nada que borrar») devolvía éxito sin mirar las opciones.
| El plan de lista de prueba sobrevivió, y la carga de la cartera histórica se
| plantó pidiendo motivo de descuento por un lote que se vendió a su propio
| precio.
|
| Un comando que dice «listo» y no hizo lo que se le pidió es peor que uno que
| falla.
*/

function proyectoConPlanDeLista(): Proyecto
{
    $proyecto = Proyecto::factory()->create(['codigo' => 'RPS']);

    PlanDePago::factory()->delProyecto($proyecto)->aPlazo(12, '1400.00')->create();

    return $proyecto;
}

describe('Con la cartera ya en cero', function (): void {
    test('--planes borra los planes igual', function (): void {
        $proyecto = proyectoConPlanDeLista();

        $this->artisan('olympo:limpiar-cartera', ['codigo' => 'RPS', '--planes' => true, '--forzar' => true])
            ->assertSuccessful();

        expect(PlanDePago::query()->where('proyecto_id', $proyecto->getKey())->count())->toBe(0);
    });

    /*
    | La otra mitad de la regla: lo que NO se pidió no se toca. Sin `--planes`
    | el precio de lista se queda donde está.
    */
    test('sin --planes los planes se quedan', function (): void {
        $proyecto = proyectoConPlanDeLista();

        $this->artisan('olympo:limpiar-cartera', ['codigo' => 'RPS', '--forzar' => true])
            ->assertSuccessful();

        expect(PlanDePago::query()->where('proyecto_id', $proyecto->getKey())->count())->toBe(1);
    });
});

describe('Con cartera que borrar', function (): void {
    test('borra la venta, libera el lote y borra los planes si se piden', function (): void {
        actingAsAdmin();

        $proyecto = proyectoConPlanDeLista();
        $bloque = Bloque::factory()->delProyecto($proyecto)->create(['nombre' => 'A']);
        $lote = Lote::factory()->enBloque($bloque)->conMedidas('250.0000', '1400.00')->create(['numero' => '1']);
        $cliente = Cliente::factory()->create();

        app(RegistroDeVentas::class)->activar(
            proyecto: $proyecto,
            lotes: [$lote],
            clientes: [$cliente],
            prima: new Monto('50000.00'),
            plazoMeses: 12,
            diaPago: 5,
        );

        expect(Venta::query()->count())->toBe(1);

        $this->artisan('olympo:limpiar-cartera', ['codigo' => 'RPS', '--planes' => true, '--forzar' => true])
            ->assertSuccessful();

        expect(Venta::query()->count())->toBe(0)
            ->and($lote->refresh()->getAttribute('estado'))->toBe(EstadoLote::Disponible)
            ->and(PlanDePago::query()->where('proyecto_id', $proyecto->getKey())->count())->toBe(0)
            // El plano no se toca: es lo que costó importar.
            ->and(Lote::query()->where('proyecto_id', $proyecto->getKey())->count())->toBe(1);
    });
});
