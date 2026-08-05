<?php

declare(strict_types=1);

use App\Filament\Resources\Proyectos\Pages\VerPlano;
use App\Filament\Resources\Proyectos\ProyectoResource;
use App\Models\Bloque;
use App\Models\Lote;
use App\Models\PlanDePago;
use App\Models\Proyecto;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| El precio de la vara segun el plazo
|--------------------------------------------------------------------------
| Dato nuevo del 5-ago-2026: «no es el mismo precio de vara a 1 año que a 4».
|
| NO es interes —R1 sigue en pie, el saldo no devenga nada— sino precios de
| lista distintos segun el plazo. Vive en la base y no en config porque
| quien lo decide es la administracion, no un despliegue.
*/

beforeEach(function (): void {
    actingAsAdmin();

    $this->proyecto = Proyecto::factory()->create(['codigo' => 'RPS']);
    $this->bloque = Bloque::factory()->create([
        'proyecto_id' => $this->proyecto->getKey(),
        'nombre'      => 'A',
    ]);
});

describe('Como se lee un plan', function (): void {
    test('el plazo cero se llama Contado', function (): void {
        $plan = PlanDePago::factory()->delProyecto($this->proyecto)->aPlazo(0, '1400.00')->create();

        expect($plan->esDeContado())->toBeTrue()
            ->and($plan->nombre())->toBe('Contado');
    });

    test('sin etiqueta, el nombre son los meses', function (): void {
        $plan = PlanDePago::factory()->delProyecto($this->proyecto)->aPlazo(48, '1800.00')->create();

        expect($plan->nombre())->toBe('48 meses')
            ->and($plan->esDeContado())->toBeFalse();
    });

    test('con etiqueta, manda la etiqueta', function (): void {
        $plan = PlanDePago::factory()->delProyecto($this->proyecto)
            ->aPlazo(12, '1500.00')
            ->create(['etiqueta' => '12 meses (feria)']);

        expect($plan->nombre())->toBe('12 meses (feria)');
    });

    /*
    | El precio entra y sale como string. Un float en el camino del dinero
    | es exactamente lo que Monto existe para evitar (§8.3.1).
    */
    test('el precio se lee como Monto y no como float', function (): void {
        $plan = PlanDePago::factory()->delProyecto($this->proyecto)->aPlazo(24, '1650.55')->create();

        expect($plan->montoPrecioVara()->redondeado())->toBe('1650.55')
            ->and($plan->getAttribute('precio_vara'))->toBeString();
    });
});

describe('Lo que no deja la base', function (): void {
    test('no admite dos precios para el mismo plazo del mismo proyecto', function (): void {
        PlanDePago::factory()->delProyecto($this->proyecto)->aPlazo(12, '1500.00')->create();

        expect(fn () => PlanDePago::factory()->delProyecto($this->proyecto)->aPlazo(12, '1600.00')->create())
            ->toThrow(QueryException::class);
    });

    test('dos proyectos si pueden tener el mismo plazo a distinto precio', function (): void {
        $otro = Proyecto::factory()->create(['codigo' => 'OTR']);

        PlanDePago::factory()->delProyecto($this->proyecto)->aPlazo(12, '1500.00')->create();
        PlanDePago::factory()->delProyecto($otro)->aPlazo(12, '900.00')->create();

        expect(PlanDePago::query()->count())->toBe(2);
    });

    test('rechaza un plazo mas alla del tope del motor de cuotas', function (): void {
        expect(fn () => DB::table('planes_de_pago')->insert([
            'proyecto_id' => $this->proyecto->getKey(),
            'meses'       => 601,
            'precio_vara' => '1500.00',
            'activo'      => true,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]))->toThrow(QueryException::class);
    });
});

/*
| Se mira lo que la pagina LE PASA a la vista y no el HTML.
|
| El cuadro vive dentro de un <template x-if>, o sea que su markup esta en
| la respuesta este cargado o no —Alpine lo saca en el navegador—. Un
| assertSee sobre eso daria verde siempre y no probaria nada.
*/
describe('Lo que llega al plano', function (): void {
    test('los planes que se ofrecen viajan a la pantalla, ordenados por plazo', function (): void {
        Lote::factory()->enBloque($this->bloque)->conMedidas('250.0000', '1400.00')->create([
            'numero'   => '1',
            'poligono' => [[0, 0], [10, 0], [10, 25], [0, 25]],
        ]);

        PlanDePago::factory()->delProyecto($this->proyecto)->aPlazo(48, '1800.00')->create();
        PlanDePago::factory()->delProyecto($this->proyecto)->aPlazo(0, '1300.00')->create();
        PlanDePago::factory()->delProyecto($this->proyecto)->aPlazo(12, '1500.00')->create();

        $planes = Livewire::test(VerPlano::class, ['record' => $this->proyecto->getKey()])
            ->viewData('planes');

        // El orden importa: es el que lee el vendedor de arriba hacia abajo.
        expect($planes)->toBe([
            ['meses' => 0,  'etiqueta' => 'Contado',  'precioVara' => '1300.00'],
            ['meses' => 12, 'etiqueta' => '12 meses', 'precioVara' => '1500.00'],
            ['meses' => 48, 'etiqueta' => '48 meses', 'precioVara' => '1800.00'],
        ]);
    });

    test('un plan apagado no se cotiza', function (): void {
        Lote::factory()->enBloque($this->bloque)->create([
            'numero'   => '1',
            'poligono' => [[0, 0], [10, 0], [10, 25], [0, 25]],
        ]);

        PlanDePago::factory()->delProyecto($this->proyecto)->aPlazo(12, '1500.00')->create();
        PlanDePago::factory()->delProyecto($this->proyecto)->aPlazo(36, '9999.00')->inactivo()->create();

        $planes = Livewire::test(VerPlano::class, ['record' => $this->proyecto->getKey()])
            ->viewData('planes');

        expect($planes)->toHaveCount(1)
            ->and($planes[0]['meses'])->toBe(12);
    });

    /*
    | Sin planes cargados el modal NO inventa ninguna cuota: un numero
    | inventado en esa pantalla es un numero que un vendedor le cotiza a un
    | cliente.
    */
    test('sin planes cargados no se cotiza nada', function (): void {
        Lote::factory()->enBloque($this->bloque)->create([
            'numero'   => '1',
            'poligono' => [[0, 0], [10, 0], [10, 25], [0, 25]],
        ]);

        $respuesta = $this->get(ProyectoResource::getUrl('plano', ['record' => $this->proyecto]));

        $respuesta->assertOk()->assertSee('Planes de pago');

        expect(Livewire::test(VerPlano::class, ['record' => $this->proyecto->getKey()])->viewData('planes'))
            ->toBe([]);
    });
});

describe('La pestaña del proyecto', function (): void {
    test('la ficha del proyecto lista los planes cargados', function (): void {
        PlanDePago::factory()->delProyecto($this->proyecto)->aPlazo(24, '1650.00')->create();

        $this->get(ProyectoResource::getUrl('view', ['record' => $this->proyecto]))
            ->assertOk()
            ->assertSee('Planes de pago');
    });
});
