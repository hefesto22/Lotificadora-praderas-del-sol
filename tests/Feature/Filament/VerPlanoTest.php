<?php

declare(strict_types=1);

use App\Filament\Resources\Proyectos\ProyectoResource;
use App\Models\Bloque;
use App\Models\Lote;
use App\Models\Proyecto;

beforeEach(function (): void {
    actingAsAdmin();

    $this->proyecto = Proyecto::factory()->create(['codigo' => 'RPS']);
    $this->bloque = Bloque::factory()->create([
        'proyecto_id' => $this->proyecto->getKey(),
        'nombre'      => 'A',
    ]);
});

test('el plano abre y muestra los lotes dibujados', function (): void {
    Lote::factory()->enBloque($this->bloque)->create([
        'numero'   => '1',
        'poligono' => [[0, 0], [10, 0], [10, 25], [0, 25]],
    ]);

    $this->get(ProyectoResource::getUrl('plano', ['record' => $this->proyecto]))
        ->assertOk()
        ->assertSee('RPS-A-001')
        ->assertSee('0,0 10,0 10,25 0,25', escape: false);
});

/*
| El caso del dia uno otra vez, pero desde el navegador: 55 lotes cargados
| y ninguno dibujado. La pagina tiene que abrir y explicarlo, no romperse.
*/
test('el plano abre aunque no haya nada dibujado', function (): void {
    Lote::factory()->enBloque($this->bloque)->count(3)->create();

    $this->get(ProyectoResource::getUrl('plano', ['record' => $this->proyecto]))
        ->assertOk()
        ->assertSee('Todavía no hay nada dibujado');
});

test('un proyecto sin un solo lote tampoco rompe la pagina', function (): void {
    $this->get(ProyectoResource::getUrl('plano', ['record' => $this->proyecto]))
        ->assertOk();
});
