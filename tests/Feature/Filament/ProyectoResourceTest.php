<?php

declare(strict_types=1);

use App\Filament\Resources\Proyectos\Pages\CreateProyecto;
use App\Filament\Resources\Proyectos\Pages\EditProyecto;
use App\Filament\Resources\Proyectos\Pages\ListProyectos;
use App\Filament\Resources\Proyectos\ProyectoResource;
use App\Models\Bloque;
use App\Models\Lote;
use App\Models\Proyecto;
use Filament\Facades\Filament;
use Livewire\Livewire;

/*
| Estos tests existen sobre todo para EJECUTAR el Resource.
|
| PHPStan verifica tipos, no firmas de runtime: si ->counts(),
| Placeholder::content() o ->recordActions() cambiaron en Filament v5, el
| error aparece recién al renderizar la página. Montar cada page es lo
| único que lo detecta antes de que lo vea la contratante.
*/

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

describe('ProyectoResource — las paginas renderizan', function (): void {
    test('el listado carga', function (): void {
        actingAsAdmin();
        Proyecto::factory()->count(3)->create();

        $this->get(ProyectoResource::getUrl('index'))->assertOk();
    });

    test('la pagina de creacion carga', function (): void {
        actingAsAdmin();

        $this->get(ProyectoResource::getUrl('create'))->assertOk();
    });

    test('la pagina de edicion carga', function (): void {
        actingAsAdmin();
        $proyecto = Proyecto::factory()->create();

        $this->get(ProyectoResource::getUrl('edit', ['record' => $proyecto]))->assertOk();
    });

    test('la vista de detalle carga', function (): void {
        actingAsAdmin();
        $proyecto = Proyecto::factory()->create();

        $this->get(ProyectoResource::getUrl('view', ['record' => $proyecto]))->assertOk();
    });

    test('la tabla lista los proyectos con sus conteos', function (): void {
        actingAsAdmin();
        $proyecto = Proyecto::factory()->praderasDelSol()->create();
        $bloque = Bloque::factory()->delProyecto($proyecto)->create();
        Lote::factory()->enBloque($bloque)->count(4)->create();

        Livewire::test(ListProyectos::class)
            ->assertCanSeeTableRecords([$proyecto])
            ->assertOk();
    });
});

describe('ProyectoResource — formulario', function (): void {
    test('crea un proyecto desde el panel', function (): void {
        actingAsAdmin();

        Livewire::test(CreateProyecto::class)
            ->fillForm([
                'nombre'       => 'Residencial Praderas del Sol',
                'codigo'       => 'RPS',
                'municipio'    => 'Cucuyagua',
                'departamento' => 'CP',
                'activo'       => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        expect(Proyecto::query()->where('codigo', 'RPS')->exists())->toBeTrue();
    });

    test('el codigo se normaliza a mayusculas al guardar desde el panel', function (): void {
        actingAsAdmin();

        Livewire::test(CreateProyecto::class)
            ->fillForm([
                'nombre' => 'Residencial Las Colinas',
                'codigo' => 'rlc',
                'activo' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        expect(Proyecto::query()->where('nombre', 'RESIDENCIAL LAS COLINAS')->value('codigo'))
            ->toBe('RLC');
    });

    test('el mutator normaliza aunque no se pase por el formulario', function (): void {
        // La tercera defensa del §10.4: un seeder o un import tampoco
        // pueden meter minúsculas.
        $proyecto = Proyecto::factory()->create(['codigo' => 'abc12']);

        expect($proyecto->fresh()?->getAttribute('codigo'))->toBe('ABC12');
    });

    test('rechaza un codigo duplicado', function (): void {
        actingAsAdmin();
        Proyecto::factory()->create(['codigo' => 'RPS']);

        Livewire::test(CreateProyecto::class)
            ->fillForm(['nombre' => 'Otro Residencial', 'codigo' => 'RPS'])
            ->call('create')
            ->assertHasFormErrors(['codigo']);
    });

    test('edita un proyecto existente', function (): void {
        actingAsAdmin();
        $proyecto = Proyecto::factory()->create(['municipio' => 'Santa Rosa']);

        Livewire::test(EditProyecto::class, ['record' => $proyecto->getKey()])
            ->fillForm(['municipio' => 'Cucuyagua'])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($proyecto->fresh()?->getAttribute('municipio'))->toBe('CUCUYAGUA');
    });
});

describe('ProyectoResource — permisos (§9.E.1)', function (): void {
    test('un panel_user sin permisos no ve el listado', function (): void {
        actingAsPanelUser();

        $this->get(ProyectoResource::getUrl('index'))->assertForbidden();
    });
});
