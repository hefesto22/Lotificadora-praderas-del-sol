<?php

declare(strict_types=1);

use App\Filament\Resources\Proyectos\Pages\VerPlano;
use App\Filament\Resources\Proyectos\ProyectoResource;
use App\Models\Bloque;
use App\Models\Lote;
use App\Models\Proyecto;
use Livewire\Livewire;

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

/*
| Este test existe por un error concreto: la accion se escribio con el
| parametro $datos y Filament inyecta por nombre contra una lista fija
| ('data', 'record', 'livewire', 'arguments'). El codigo compilaba, PHPStan
| pasaba y la pagina abria — reventaba recien al hacer clic en el boton.
|
| Renderizar la pagina no alcanza: hay que DISPARAR la accion.
*/
test('la accion de acomodar dibuja los lotes que ya existen', function (): void {
    Lote::factory()->enBloque($this->bloque)
        ->conMedidas('250.0000', '1200.00')
        ->count(2)
        ->create();

    Livewire::test(VerPlano::class, ['record' => $this->proyecto->getKey()])
        ->callAction('acomodar', [
            'fondo'             => '25',
            'filas'             => 1,
            'separacionBloques' => '10',
        ])
        ->assertHasNoActionErrors();

    expect(Lote::query()->whereNotNull('poligono')->count())->toBe(2)
        ->and($this->proyecto->refresh()->getAttribute('plano_esquematico'))->toBeTrue();
});

test('acomodar un proyecto sin lotes no lo marca como esquematico', function (): void {
    Livewire::test(VerPlano::class, ['record' => $this->proyecto->getKey()])
        ->callAction('acomodar', [
            'fondo'             => '25',
            'filas'             => 1,
            'separacionBloques' => '10',
        ])
        ->assertHasNoActionErrors();

    expect($this->proyecto->refresh()->getAttribute('plano_esquematico'))->toBeFalse();
});
