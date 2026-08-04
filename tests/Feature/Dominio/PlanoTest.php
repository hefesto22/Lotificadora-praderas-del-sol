<?php

declare(strict_types=1);

use App\Domain\Enums\EstadoLote;
use App\Domain\Enums\TipoCalle;
use App\Models\Calle;
use App\Models\Lote;
use App\Models\Proyecto;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

describe('Plano — el dibujo es opcional', function (): void {
    /*
    | Los ~55 lotes ya cargados no tienen geometria. El plano es una capa
    | encima del negocio, no un requisito para vender: si estos tests se
    | ponen rojos, alguien volvio obligatorio el dibujo y dejo de poder
    | cargarse un lote sin haberlo dibujado antes.
    */
    test('un lote sin poligono se guarda y se lee sin problema', function (): void {
        $lote = Lote::factory()->create();

        expect($lote->getAttribute('poligono'))->toBeNull()
            ->and($lote->tienePoligono())->toBeFalse()
            ->and($lote->areaSegunPoligonoVaras())->toBeNull()
            ->and($lote->discrepanciaDeAreaEnPorcentaje())->toBeNull();
    });

    test('un lote sin poligono NO cuenta como desalineado', function (): void {
        expect(Lote::factory()->create()->poligonoDesalineado())->toBeFalse();
    });
});

describe('Plano — la base rechaza geometria imposible', function (): void {
    test('un poligono de menos de 3 vertices no entra', function (): void {
        expect(fn () => Lote::factory()->create([
            'poligono' => [[0, 0], [10, 0]],
        ]))->toThrow(QueryException::class);
    });

    test('un poligono que no es una lista no entra', function (): void {
        expect(fn () => Lote::factory()->create([
            'poligono' => ['x' => 1, 'y' => 2],
        ]))->toThrow(QueryException::class);
    });

    test('una calle necesita al menos dos puntos', function (): void {
        expect(fn () => Calle::factory()->conTrazo([[0.0, 0.0]])->create())
            ->toThrow(QueryException::class);
    });

    test('una calle no puede tener ancho cero', function (): void {
        expect(fn () => Calle::factory()->create(['ancho_varas' => '0.0000']))
            ->toThrow(QueryException::class);
    });

    /*
    | Por fuera de Eloquent: el cast a TipoCalle atajaria el valor invalido
    | antes de llegar a Postgres, asi que el CHECK solo se puede probar
    | insertando crudo — que es exactamente como entraria un import.
    */
    test('un tipo de calle desconocido no entra ni por SQL crudo', function (): void {
        $proyecto = Proyecto::factory()->create();

        expect(fn () => DB::table('calles')->insert([
            'proyecto_id' => $proyecto->getKey(),
            'tipo'        => 'autopista',
            'ancho_varas' => '7.0000',
            'trazo'       => json_encode([[0, 0], [10, 0]]),
        ]))->toThrow(QueryException::class);
    });
});

describe('Plano — el area dibujada se calcula, pero no manda', function (): void {
    test('un cuadrado de 10x10 mide 100 varas cuadradas', function (): void {
        $lote = Lote::factory()->conMedidas('100.0000', '1000.00')->create([
            'poligono' => [[0, 0], [10, 0], [10, 10], [0, 10]],
        ]);

        expect($lote->areaSegunPoligonoVaras())->toBe(100.0);
    });

    test('el sentido del trazo no cambia el area', function (): void {
        $horario = Lote::factory()->create([
            'poligono' => [[0, 0], [20, 0], [20, 15], [0, 15]],
        ]);

        $antihorario = Lote::factory()->create([
            'poligono' => [[0, 15], [20, 15], [20, 0], [0, 0]],
        ]);

        expect($horario->areaSegunPoligonoVaras())->toBe(300.0)
            ->and($antihorario->areaSegunPoligonoVaras())->toBe(300.0);
    });

    test('si el dibujo coincide con el area cargada, no hay desalineacion', function (): void {
        $lote = Lote::factory()->conMedidas('100.0000', '1000.00')->create([
            'poligono' => [[0, 0], [10, 0], [10, 10], [0, 10]],
        ]);

        expect($lote->discrepanciaDeAreaEnPorcentaje())->toBe(0.0)
            ->and($lote->poligonoDesalineado())->toBeFalse();
    });

    test('un dibujo que contradice al plano legal se marca como desalineado', function (): void {
        $lote = Lote::factory()->conMedidas('100.0000', '1000.00')->create([
            'poligono' => [[0, 0], [12, 0], [12, 12], [0, 12]],
        ]);

        expect($lote->areaSegunPoligonoVaras())->toBe(144.0)
            ->and($lote->discrepanciaDeAreaEnPorcentaje())->toBeGreaterThan(40.0)
            ->and($lote->poligonoDesalineado())->toBeTrue();
    });

    /*
    | ESTE es el test que protege la decision de diseno. El dibujo puede
    | decir 144 varas y el documento legal 100: gana el documento. El dia
    | que alguien haga que el editor "corrija" el area, este se pone rojo.
    */
    test('dibujar un lote NUNCA le cambia el area ni el valor', function (): void {
        $lote = Lote::factory()->conMedidas('100.0000', '1000.00')->create();

        expect($lote->getAttribute('valor'))->toBe('100000.00');

        $lote->update(['poligono' => [[0, 0], [12, 0], [12, 12], [0, 12]]]);
        $lote->refresh();

        expect($lote->getAttribute('area_varas'))->toBe('100.0000')
            ->and($lote->getAttribute('valor'))->toBe('100000.00');
    });

    /*
    | El trigger del §8.2 congela area, precio y valor de un lote vendido.
    | El poligono NO es ninguna de esas tres: tiene que poder dibujarse un
    | lote ya vendido, o el plano quedaria incompleto justo en los lotes
    | que mas importa mostrar.
    */
    test('un lote vendido si se puede dibujar', function (): void {
        $lote = Lote::factory()->conEstado(EstadoLote::Vendido)->create();

        $lote->update(['poligono' => [[0, 0], [10, 0], [10, 10], [0, 10]]]);

        expect($lote->fresh()?->tienePoligono())->toBeTrue();
    });
});

describe('Plano — calles', function (): void {
    test('el largo del trazo se suma segmento por segmento', function (): void {
        $calle = Calle::factory()->conTrazo([[0.0, 0.0], [30.0, 40.0]])->create();

        expect($calle->largoVaras())->toBe(50.0);
    });

    test('el ancho sugerido sale del tipo', function (): void {
        $calle = Calle::factory()->deTipo(TipoCalle::Boulevard)->create();

        expect($calle->getAttribute('ancho_varas'))->toBe('16.0000')
            ->and($calle->getAttribute('tipo'))->toBe(TipoCalle::Boulevard);
    });

    test('varias calles sin nombre conviven en el mismo proyecto', function (): void {
        $proyecto = Proyecto::factory()->create();

        Calle::factory()->enProyecto($proyecto)->count(3)->create(['nombre' => null]);

        expect(Calle::query()->delProyecto($proyecto)->count())->toBe(3);
    });

    test('dos calles con el mismo nombre en un proyecto no', function (): void {
        $proyecto = Proyecto::factory()->create();

        Calle::factory()->enProyecto($proyecto)->create(['nombre' => 'Avenida Principal']);

        expect(fn () => Calle::factory()->enProyecto($proyecto)->create(['nombre' => 'Avenida Principal']))
            ->toThrow(QueryException::class);
    });

    test('el mismo nombre si puede repetirse entre proyectos distintos', function (): void {
        Calle::factory()->create(['nombre' => 'Avenida Principal']);
        Calle::factory()->create(['nombre' => 'Avenida Principal']);

        expect(Calle::query()->where('nombre', 'AVENIDA PRINCIPAL')->count())->toBe(2);
    });
});
