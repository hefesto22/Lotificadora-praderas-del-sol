<?php

declare(strict_types=1);

use App\Filament\Resources\Bloques\BloqueResource;
use App\Filament\Resources\Bloques\Pages\CreateBloque;
use App\Filament\Resources\Bloques\Pages\ListBloques;
use App\Models\Bloque;
use App\Models\Lote;
use App\Models\Proyecto;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Livewire\Livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

describe('BloqueResource — las paginas renderizan', function (): void {
    test('el listado carga', function (): void {
        actingAsAdmin();
        Bloque::factory()->count(3)->create();

        $this->get(BloqueResource::getUrl('index'))->assertOk();
    });

    test('la creacion carga', function (): void {
        actingAsAdmin();

        $this->get(BloqueResource::getUrl('create'))->assertOk();
    });

    test('la edicion carga, con el tab Registro que solo existe ahi', function (): void {
        actingAsAdmin();
        $bloque = Bloque::factory()->create();

        $this->get(BloqueResource::getUrl('edit', ['record' => $bloque]))->assertOk();
    });

    test('la vista de detalle carga', function (): void {
        actingAsAdmin();
        $bloque = Bloque::factory()->create();

        $this->get(BloqueResource::getUrl('view', ['record' => $bloque]))->assertOk();
    });

    test('la tabla lista los bloques con el conteo de lotes', function (): void {
        actingAsAdmin();
        $bloque = Bloque::factory()->create();
        Lote::factory()->enBloque($bloque)->count(3)->create();

        Livewire::test(ListBloques::class)
            ->assertCanSeeTableRecords([$bloque])
            ->assertOk();
    });
});

describe('BloqueResource — formulario', function (): void {
    test('crea un bloque y normaliza el nombre a mayusculas', function (): void {
        actingAsAdmin();
        $proyecto = Proyecto::factory()->create();

        Livewire::test(CreateBloque::class)
            ->fillForm([
                'proyecto_id'        => $proyecto->getKey(),
                'nombre'             => 'c',
                'area_total_varas'   => '4500.0000',
                'lotes_planificados' => 42,
                'orden'              => 3,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        expect(Bloque::query()->where('proyecto_id', $proyecto->getKey())->value('nombre'))->toBe('C');
    });

    test('rechaza dos bloques con el mismo nombre en el mismo proyecto', function (): void {
        actingAsAdmin();
        $proyecto = Proyecto::factory()->create();
        Bloque::factory()->delProyecto($proyecto)->create(['nombre' => 'A']);

        expect(fn () => Livewire::test(CreateBloque::class)
            ->fillForm(['proyecto_id' => $proyecto->getKey(), 'nombre' => 'A'])
            ->call('create'))
            ->toThrow(QueryException::class);
    });
});

describe('BloqueResource — permisos', function (): void {
    test('un panel_user sin permisos no ve el listado', function (): void {
        actingAsPanelUser();

        $this->get(BloqueResource::getUrl('index'))->assertForbidden();
    });
});
