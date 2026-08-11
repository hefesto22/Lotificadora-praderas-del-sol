<?php

declare(strict_types=1);

use App\Domain\Plano\Dxf\UnidadDxf;
use App\Filament\Resources\Proyectos\Pages\VerPlano;
use App\Filament\Resources\Proyectos\ProyectoResource;
use App\Models\Bloque;
use App\Models\Lote;
use App\Models\Proyecto;
use Illuminate\Http\UploadedFile;
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

/*
| ═══ LA VARA ES DEL DESARROLLO, NO DEL SISTEMA ═══
|
| El seam que este test cubre es de UNA linea —que la accion pase
| `$proyecto->varaEnMetros()` y no `config(...)`— y es la linea de la que
| sale el area de todos los lotes de un residencial. Equivocarla no rompe
| nada: importa igual, dibuja igual, y el precio de cada lote sale corrido
| un 20 %. Nadie lo nota hasta que alguien compara con el plano.
|
| Un cuadro de 10 × 10 metros, dos veces:
|
|   vara = 1.00 m  →  100 varas²   (la del proyecto)
|   vara = 0.8359  →  143 varas²   (la del sistema)
|
| Si el numero que sale es 143, la accion se quedo leyendo la config.
*/
function dxfDeUnCuadro(float $lado): string
{
    $medida = number_format($lado, 4, '.', '');

    return implode("\r\n", [
        '  0', 'SECTION', '  2', 'HEADER', '  9', '$INSUNITS', ' 70', '     6', '  0', 'ENDSEC',
        '  0', 'SECTION', '  2', 'ENTITIES',
        '  0', 'LWPOLYLINE', '  8', 'LOTES', ' 90', '4', ' 70', '1',
        ' 10', '0.0000', ' 20', '0.0000',
        ' 10', $medida,   ' 20', '0.0000',
        ' 10', $medida,   ' 20', $medida,
        ' 10', '0.0000', ' 20', $medida,
        '  0', 'ENDSEC', '  0', 'EOF',
    ])."\r\n";
}

test('importar un plano usa la vara del proyecto y no la del sistema', function (): void {
    config()->set('lotificadora.area.vara_en_metros', '0.8359');

    $this->proyecto->update(['vara_en_metros' => '1.000000']);

    Livewire::test(VerPlano::class, ['record' => $this->proyecto->getKey()])
        ->callAction('importarDxf', [
            'archivo'      => UploadedFile::fake()->createWithContent('cuadro.dxf', dxfDeUnCuadro(10.0)),
            'bloque_id'    => $this->bloque->getKey(),
            'unidad'       => (string) UnidadDxf::Metros->value,
            'precio_vara'  => '1200.00',
            'capa_lotes'   => 'LOTES',
            'capa_rotulos' => '',
            'capa_calles'  => '',
        ])
        ->assertHasNoActionErrors();

    expect(Lote::query()->where('bloque_id', $this->bloque->getKey())->value('area_varas'))
        ->toBe('100.0000');
});

test('sin vara propia, importar cae en la vara del sistema', function (): void {
    config()->set('lotificadora.area.vara_en_metros', '1.000000');

    Livewire::test(VerPlano::class, ['record' => $this->proyecto->getKey()])
        ->callAction('importarDxf', [
            'archivo'      => UploadedFile::fake()->createWithContent('cuadro.dxf', dxfDeUnCuadro(10.0)),
            'bloque_id'    => $this->bloque->getKey(),
            'unidad'       => (string) UnidadDxf::Metros->value,
            'precio_vara'  => '1200.00',
            'capa_lotes'   => 'LOTES',
            'capa_rotulos' => '',
            'capa_calles'  => '',
        ])
        ->assertHasNoActionErrors();

    expect(Lote::query()->where('bloque_id', $this->bloque->getKey())->value('area_varas'))
        ->toBe('100.0000');
});
