<?php

declare(strict_types=1);

use App\Filament\Resources\Proyectos\Pages\CreateProyecto;
use App\Filament\Resources\Proyectos\Pages\EditProyecto;
use App\Filament\Resources\Proyectos\Pages\ListProyectos;
use App\Filament\Resources\Proyectos\ProyectoResource;
use App\Models\Bloque;
use App\Models\Lote;
use App\Models\Proyecto;
use App\Models\Socio;
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

/*
| ═══ MEDIDAS DEL PLANO ═══
|
| Dos campos que se ven parecidos y no lo son: el toggle es presentacion y
| el numero de abajo decide cuantas varas² tiene cada lote —o sea, cuanto
| cuesta—. Los dos tienen que guardarse, y el rango tiene que frenar en el
| formulario y no recien contra el CHECK de la base.
*/
describe('ProyectoResource — medidas del plano', function (): void {
    test('el proyecto guarda en que unidad se lee su plano', function (): void {
        actingAsAdmin();
        $proyecto = Proyecto::factory()->create(['codigo' => 'REB']);

        Livewire::test(EditProyecto::class, ['record' => $proyecto->getKey()])
            ->fillForm([
                'medidas_en_metros' => true,
                'vara_en_metros'    => '0.8467',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $proyecto->refresh();

        expect($proyecto->getAttribute('medidas_en_metros'))->toBeTrue()
            ->and($proyecto->varaEnMetros())->toBe('0.846700');
    });

    /*
    | Vacio NO es cero ni 0.8359 copiado en la fila: es «la del sistema».
    | La diferencia se ve el dia que cambie el default de la config.
    */
    test('vacio significa la vara del sistema, no cero', function (): void {
        actingAsAdmin();
        config()->set('lotificadora.area.vara_en_metros', '0.8359');

        $proyecto = Proyecto::factory()->create(['codigo' => 'REB']);

        Livewire::test(EditProyecto::class, ['record' => $proyecto->getKey()])
            ->fillForm(['vara_en_metros' => null])
            ->call('save')
            ->assertHasNoFormErrors();

        $proyecto->refresh();

        expect($proyecto->getAttribute('vara_en_metros'))->toBeNull()
            ->and($proyecto->varaEnMetros())->toBe('0.8359');
    });

    test('el formulario frena una vara que no puede ser una vara', function (): void {
        actingAsAdmin();
        $proyecto = Proyecto::factory()->create(['codigo' => 'REB']);

        Livewire::test(EditProyecto::class, ['record' => $proyecto->getKey()])
            ->fillForm(['vara_en_metros' => '83.59'])
            ->call('save')
            ->assertHasFormErrors(['vara_en_metros']);
    });
});

/*
|--------------------------------------------------------------------------
| El reparto de los socios — 27-ago-2026
|--------------------------------------------------------------------------
| «Que me permita como 66.67 y 33.33, que sea obligatorio completar el 100%»
| — Mauricio.
|
| Las dos mitades del pedido se prueban acá porque las dos viven en el
| formulario y en ningún otro lado: la escala la garantiza la columna, pero que
| las partes SUMEN 100 no lo puede mirar un CHECK —mira una fila y esto es la
| suma de todas—, así que si esta regla se cae, se cae en silencio y el reparto
| del mes empieza a repartir 90.
*/
describe('ProyectoResource — el reparto de los socios', function (): void {
    test('sin el 100% repartido no se guarda', function (): void {
        actingAsAdmin();

        Livewire::test(CreateProyecto::class)
            ->fillForm([
                'nombre' => 'Residencial Prueba',
                'codigo' => 'RPB',
                'activo' => true,
                'socios' => [
                    ['nombre' => 'SOCIO UNO', 'porcentaje' => '60', 'activo' => true],
                    ['nombre' => 'SOCIO DOS', 'porcentaje' => '30', 'activo' => true],
                ],
            ])
            ->call('create')
            ->assertHasFormErrors(['socios']);

        expect(Proyecto::query()->where('codigo', 'RPB')->exists())->toBeFalse();
    });

    test('dos tercios y un tercio si se guardan', function (): void {
        actingAsAdmin();

        Livewire::test(CreateProyecto::class)
            ->fillForm([
                'nombre' => 'Residencial Prueba',
                'codigo' => 'RPB',
                'activo' => true,
                'socios' => [
                    ['nombre' => 'SOCIO UNO', 'porcentaje' => '66.67', 'activo' => true],
                    ['nombre' => 'SOCIO DOS', 'porcentaje' => '33.33', 'activo' => true],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $proyecto = Proyecto::query()->where('codigo', 'RPB')->first();

        expect($proyecto)->not->toBeNull();

        $partes = $proyecto?->socios()->activos()->get()
            ->map(static fn (Socio $socio): string => $socio->porcentaje()->redondeado(2))
            ->all() ?? [];

        expect($partes)->toBe(['66.67', '33.33'])
            ->and($proyecto?->partesDeLosSocios()->redondeado(2))->toBe('100.00');
    });

    /*
    | Un tercer decimal la base NO lo rechaza: numeric(5,2) lo redondea sola y
    | sin avisar. El formulario es el único lugar donde el usuario se entera.
    */
    test('un tercer decimal lo frena el formulario', function (): void {
        actingAsAdmin();

        Livewire::test(CreateProyecto::class)
            ->fillForm([
                'nombre' => 'Residencial Prueba',
                'codigo' => 'RPB',
                'activo' => true,
                'socios' => [
                    ['nombre' => 'SOCIO UNO', 'porcentaje' => '33.333', 'activo' => true],
                    ['nombre' => 'SOCIO DOS', 'porcentaje' => '66.667', 'activo' => true],
                ],
            ])
            ->call('create')
            ->assertHasFormErrors(['socios.0.porcentaje']);
    });

    /*
    | Cero socios SÍ se guarda: no es un reparto mal hecho, es que no hay
    | reparto. Exigirlo obligaría a conocer a los socios antes de poder crear el
    | proyecto.
    */
    test('un proyecto sin socios se guarda igual', function (): void {
        actingAsAdmin();

        Livewire::test(CreateProyecto::class)
            ->fillForm(['nombre' => 'Residencial Prueba', 'codigo' => 'RPB', 'activo' => true])
            ->call('create')
            ->assertHasNoFormErrors();

        expect(Proyecto::query()->where('codigo', 'RPB')->exists())->toBeTrue();
    });
});
