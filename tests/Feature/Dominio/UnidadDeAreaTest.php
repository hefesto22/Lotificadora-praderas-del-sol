<?php

declare(strict_types=1);

use App\Domain\Enums\EstadoLote;
use App\Domain\Enums\UnidadDeArea;
use App\Domain\Plano\PlanoDelProyecto;
use App\Models\Bloque;
use App\Models\Lote;
use App\Models\Proyecto;

/*
| La unidad del área dejó de ser una sola para toda la instalación el
| 13-ago-2026: hay desarrollos que se venden en varas² y otros en metros².
| Lo pidió Mauricio con EL BAMBÚ cargado: «al crear el proyecto debe
| decidirse si en metros o varas, según la que se escoja todo se trabajará
| en base a eso».
*/

describe('UnidadDeArea — las tres formas de escribirla', function (): void {
    /*
    | El repo ya escribía la vara de tres maneras según el lugar —el
    | sufijo de una columna, una frase, el mapa— y las tres se conservan
    | para no moverle la pantalla a Praderas, que está en producción.
    */
    test('cada unidad se escribe distinto según dónde entre', function (): void {
        expect(UnidadDeArea::Varas->plural())->toBe('varas²')
            ->and(UnidadDeArea::Varas->abreviada())->toBe('vr²')
            ->and(UnidadDeArea::Varas->corta())->toBe('v²')
            ->and(UnidadDeArea::Varas->porUnidad())->toBe('por vara²');
    });

    test('en metros las tres formas son la misma', function (): void {
        expect(UnidadDeArea::Metros->plural())->toBe('metros²')
            ->and(UnidadDeArea::Metros->abreviada())->toBe('m²')
            ->and(UnidadDeArea::Metros->corta())->toBe('m²')
            ->and(UnidadDeArea::Metros->porUnidad())->toBe('por m²');
    });

    /*
    | El CHECK de la migración sale de valores(): la base no puede tener
    | una unidad que el código no conoce.
    */
    test('los valores son los que guarda la base', function (): void {
        expect(UnidadDeArea::valores())->toBe(['varas', 'metros'])
            ->and(array_keys(UnidadDeArea::opciones()))->toBe(['varas', 'metros']);
    });

    /*
    | En metros² no hay factor que preguntar: la unidad del área ES el
    | metro. De eso vive el ->visible() que esconde «Medidas del plano».
    */
    test('solo las varas necesitan que alguien diga cuánto miden', function (): void {
        expect(UnidadDeArea::Varas->necesitaFactor())->toBeTrue()
            ->and(UnidadDeArea::Varas->ladoEnMetros())->toBeNull()
            ->and(UnidadDeArea::Metros->necesitaFactor())->toBeFalse()
            ->and(UnidadDeArea::Metros->ladoEnMetros())->toBe('1.000000');
    });
});

describe('Proyecto — la unidad es suya', function (): void {
    test('un proyecto nace en varas², que es la costumbre del país', function (): void {
        expect(Proyecto::factory()->create()->unidadDeArea())->toBe(UnidadDeArea::Varas);
    });

    test('un proyecto en metros² lo dice y no pide vara', function (): void {
        $proyecto = Proyecto::factory()->create([
            'unidad_area' => UnidadDeArea::Metros->value,
            // Aunque alguien le haya dejado una vara puesta de antes.
            'vara_en_metros' => '0.835000',
        ]);

        expect($proyecto->trabajaEnMetros())->toBeTrue()
            // 🔴 La unidad MANDA sobre la columna: el área de un proyecto
            // en metros² no se divide entre nada al importar el plano.
            ->and($proyecto->varaEnMetros())->toBe('1.000000');
    });

    test('un proyecto en varas² sigue usando la vara de su topógrafo', function (): void {
        $proyecto = Proyecto::factory()->create([
            'unidad_area'    => UnidadDeArea::Varas->value,
            'vara_en_metros' => '0.838000',
        ]);

        expect($proyecto->varaEnMetros())->toBe('0.838000');
    });
});

describe('Proyecto — cuándo se traba la unidad', function (): void {
    beforeEach(function (): void {
        $this->proyecto = Proyecto::factory()->create(['codigo' => 'UU']);
        $this->bloque = Bloque::factory()->create([
            'proyecto_id' => $this->proyecto->getKey(),
            'nombre'      => 'A',
        ]);
    });

    test('sin lotes se puede cambiar', function (): void {
        expect($this->proyecto->puedeCambiarLaUnidad())->toBeTrue();
    });

    test('con lotes disponibles todavía se puede corregir un dedazo', function (): void {
        Lote::factory()->enBloque($this->bloque)->count(3)->create();

        expect($this->proyecto->puedeCambiarLaUnidad())->toBeTrue();
    });

    /*
    | Un apartado es reversible —para eso existe la devolución de la
    | seña— y todavía no hay escritura de por medio.
    */
    test('un apartado no traba la unidad', function (): void {
        Lote::factory()->enBloque($this->bloque)->conEstado(EstadoLote::Apartado)->create();

        expect($this->proyecto->puedeCambiarLaUnidad())->toBeTrue();
    });

    /*
    | 🔴 La regla de Mauricio, 13-ago-2026: «se puede editar solo si no se
    | ha vendido ninguno, de ahí no se puede editar». Cambiarla NO
    | reconvierte ningún área, así que después de una venta el número del
    | contrato firmado y el de la pantalla dirían unidades distintas.
    */
    test('un lote vendido la traba para siempre', function (): void {
        Lote::factory()->enBloque($this->bloque)->conEstado(EstadoLote::Vendido)->create();

        expect($this->proyecto->puedeCambiarLaUnidad())->toBeFalse();
    });

    test('una donación también la traba: el lote salió del inventario', function (): void {
        Lote::factory()->enBloque($this->bloque)->conEstado(EstadoLote::Donado)->create();

        expect($this->proyecto->puedeCambiarLaUnidad())->toBeFalse();
    });
});

describe('El plano lleva la unidad del proyecto', function (): void {
    test('un proyecto en varas² muestra las dos unidades', function (): void {
        $proyecto = Proyecto::factory()->create(['unidad_area' => UnidadDeArea::Varas->value]);

        $medidas = new PlanoDelProyecto()->para($proyecto)['medidas'];

        expect($medidas['area'])->toBe('varas²')
            ->and($medidas['areaCorta'])->toBe('v²')
            ->and($medidas['dosUnidades'])->toBeTrue();
    });

    /*
    | En metros² los m² al lado serían el mismo número dos veces.
    */
    test('un proyecto en metros² no repite el número al lado', function (): void {
        $proyecto = Proyecto::factory()->create(['unidad_area' => UnidadDeArea::Metros->value]);

        $medidas = new PlanoDelProyecto()->para($proyecto)['medidas'];

        expect($medidas['area'])->toBe('metros²')
            ->and($medidas['areaCorta'])->toBe('m²')
            ->and($medidas['dosUnidades'])->toBeFalse()
            // Las cotas de los lados ya están en metros sin tocar el toggle.
            ->and($medidas['enMetros'])->toBeTrue()
            ->and($medidas['factor'])->toBe(1.0);
    });
});
