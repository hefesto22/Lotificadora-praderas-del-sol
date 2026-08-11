<?php

declare(strict_types=1);

use App\Domain\Plano\PlanoDelProyecto;
use App\Domain\Plano\PlanoPublico;
use App\Models\Bloque;
use App\Models\Lote;
use App\Models\Proyecto;
use Illuminate\Database\QueryException;

/*
| El topografo acota en METROS y el negocio cobra en VARAS². Un cliente
| parado frente al plano impreso, con el telefono en la mano, tiene que
| ver los mismos numeros en los dos lados.
|
| Estos tests fijan las dos mitades de esa promesa:
|
|   1. La vara es de cada DESARROLLO. De ella sale cuantas varas² tiene un
|      lote al importar el DXF, y el precio es por vara²: es un numero que
|      toca el dinero, no una preferencia de pantalla.
|   2. Mostrar en metros es SOLO presentacion. La geometria se sigue
|      guardando en varas pase lo que pase.
*/

beforeEach(function (): void {
    config()->set('lotificadora.area.vara_en_metros', '0.8359');

    $this->proyecto = Proyecto::factory()->create(['codigo' => 'REB']);
    $this->bloque = Bloque::factory()->create([
        'proyecto_id' => $this->proyecto->getKey(),
        'nombre'      => 'A',
    ]);
});

describe('La vara de cada desarrollo', function (): void {
    test('sin configurar, el proyecto usa la vara del sistema', function (): void {
        expect($this->proyecto->getAttribute('vara_en_metros'))->toBeNull()
            ->and($this->proyecto->varaEnMetros())->toBe('0.8359');
    });

    /*
    | Null significa «la del sistema» y NO «0.8359 copiado en la fila». La
    | diferencia se ve el dia que se cambie el default: los proyectos que
    | nunca eligieron nada tienen que moverse con el.
    */
    test('cambiar la vara del sistema mueve a los proyectos que no eligieron una', function (): void {
        config()->set('lotificadora.area.vara_en_metros', '0.8467');

        expect($this->proyecto->varaEnMetros())->toBe('0.8467');
    });

    test('la vara propia del proyecto le gana a la del sistema', function (): void {
        $this->proyecto->update(['vara_en_metros' => '0.8467']);
        $this->proyecto->refresh();

        expect($this->proyecto->varaEnMetros())->toBe('0.846700');
    });

    /*
    | El CHECK de la base y no el del formulario: este numero tambien entra
    | por un seeder o por tinker, y afuera de ese rango no hay una vara
    | distinta —hay un dedo que se resbalo—.
    */
    test('la base rechaza un numero que no puede ser una vara', function (): void {
        expect(fn (): bool => $this->proyecto->update(['vara_en_metros' => '83.59']))
            ->toThrow(QueryException::class);
    });
});

describe('En que unidad se acota el plano', function (): void {
    test('por defecto los lados se acotan en varas', function (): void {
        $medidas = new PlanoDelProyecto()->para($this->proyecto)['medidas'];

        expect($medidas['enMetros'])->toBeFalse()
            ->and($medidas['factor'])->toBe(1.0)
            ->and($medidas['unidad'])->toBe('V')
            ->and($medidas['pie'])->toContain('varas');
    });

    test('en metros, el factor es la vara del proyecto', function (): void {
        $this->proyecto->update([
            'medidas_en_metros' => true,
            'vara_en_metros'    => '0.8359',
        ]);
        $this->proyecto->refresh();

        $medidas = new PlanoDelProyecto()->para($this->proyecto)['medidas'];

        expect($medidas['enMetros'])->toBeTrue()
            ->and($medidas['factor'])->toBe(0.8359)
            ->and($medidas['unidad'])->toBe('m')
            ->and($medidas['pie'])->toContain('metros');
    });

    /*
    | Lo que NO puede pasar: que cambiar el ajuste toque la geometria. El
    | poligono se guarda en varas para siempre, porque en varas² es como
    | se cobra.
    */
    test('mostrar en metros no mueve ni un vertice ni un area', function (): void {
        Lote::factory()->enBloque($this->bloque)->create([
            'numero'     => '12',
            'area_varas' => '458.3600',
            'poligono'   => [[0, 0], [10, 0], [10, 10], [0, 10]],
        ]);

        $enVaras = new PlanoDelProyecto()->para($this->proyecto)['lotes'][0];

        $this->proyecto->update(['medidas_en_metros' => true]);
        $this->proyecto->refresh();

        $enMetros = new PlanoDelProyecto()->para($this->proyecto)['lotes'][0];

        expect($enMetros['puntos'])->toBe($enVaras['puntos'])
            ->and($enMetros['areaVaras'])->toBe($enVaras['areaVaras'])
            ->and($enMetros['valor'])->toBe($enVaras['valor']);
    });
});

describe('El area en las dos unidades', function (): void {
    /*
    | El lote A12 de El Bambu: el plano del topografo lo rotula
    | «A=320.19m2 / 459.22v2». El sistema guarda 458.36 v² —la diferencia
    | es del contorno reconstruido, no de la cuenta— y tiene que
    | convertirlo a los mismos 320 m² que el cliente esta leyendo.
    */
    test('458.36 varas² son 320.27 m² con la vara castellana', function (): void {
        Lote::factory()->enBloque($this->bloque)->create([
            'area_varas' => '458.3600',
            'poligono'   => [[0, 0], [10, 0], [10, 10], [0, 10]],
        ]);

        expect(new PlanoDelProyecto()->para($this->proyecto)['lotes'][0]['areaMetros'])
            ->toBe('320.27');
    });

    test('con otra vara, el mismo lote mide otros metros', function (): void {
        $this->proyecto->update(['vara_en_metros' => '1.000000']);
        $this->proyecto->refresh();

        Lote::factory()->enBloque($this->bloque)->create([
            'area_varas' => '458.3600',
            'poligono'   => [[0, 0], [10, 0], [10, 10], [0, 10]],
        ]);

        expect(new PlanoDelProyecto()->para($this->proyecto)['lotes'][0]['areaMetros'])
            ->toBe('458.36');
    });

    /*
    | La vara² NUNCA desaparece de la ficha: es la unidad con la que se
    | vende. Los m² van al lado, igual que los rotula el topografo.
    */
    test('el plano publico muestra las dos unidades cuando el proyecto esta en metros', function (): void {
        $this->proyecto->update([
            'medidas_en_metros' => true,
            'plano_publico'     => true,
        ]);
        $this->proyecto->refresh();

        Lote::factory()->enBloque($this->bloque)->create([
            'area_varas' => '458.3600',
            'poligono'   => [[0, 0], [10, 0], [10, 10], [0, 10]],
        ]);

        expect(resolve(PlanoPublico::class)->para($this->proyecto)['lotes'][0]['areaFormateada'])
            ->toBe('458.36 vr² · 320.27 m²');
    });

    test('en varas, el plano publico muestra solo la vara²', function (): void {
        Lote::factory()->enBloque($this->bloque)->create([
            'area_varas' => '458.3600',
            'poligono'   => [[0, 0], [10, 0], [10, 10], [0, 10]],
        ]);

        expect(resolve(PlanoPublico::class)->para($this->proyecto)['lotes'][0]['areaFormateada'])
            ->toBe('458.36 vr²');
    });
});
