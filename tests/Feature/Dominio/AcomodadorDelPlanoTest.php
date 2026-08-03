<?php

declare(strict_types=1);

use App\Domain\Enums\EstadoLote;
use App\Domain\Enums\Numeracion;
use App\Domain\Plano\AcomodadorDelPlano;
use App\Domain\Plano\ParametrosDeAcomodo;
use App\Models\Bloque;
use App\Models\Lote;
use App\Models\Proyecto;

beforeEach(function (): void {
    $this->proyecto = Proyecto::factory()->create(['codigo' => 'RPS']);
    $this->bloque = Bloque::factory()->create([
        'proyecto_id' => $this->proyecto->getKey(),
        'nombre'      => 'A',
        'orden'       => 1,
    ]);
});

describe('Acomodador — dibuja sin tocar el negocio', function (): void {
    /*
    | La razon de existir de este servicio. Los lotes ya cargados tienen
    | numero, area y precio del documento legal: el acomodador escribe UNA
    | columna, `poligono`, y nada mas.
    */
    test('no cambia numero, area, precio ni valor', function (): void {
        $lote = Lote::factory()->enBloque($this->bloque)
            ->conMedidas('250.0000', '1200.00')
            ->create(['numero' => '7']);

        new AcomodadorDelPlano()->acomodarBloque($this->bloque, new ParametrosDeAcomodo(fondoVaras: '25'));

        $lote->refresh();

        expect($lote->getAttribute('numero'))->toBe('7')
            ->and($lote->getAttribute('area_varas'))->toBe('250.0000')
            ->and($lote->getAttribute('precio_vara'))->toBe('1200.00')
            ->and($lote->getAttribute('valor'))->toBe('300000.00')
            ->and($lote->getAttribute('estado'))->toBe(EstadoLote::Disponible)
            ->and($lote->tienePoligono())->toBeTrue();
    });

    /*
    | Con fondo fijo y frente = area / fondo, el rectangulo encierra
    | exactamente el area cargada. Si esto se pone rojo, el plano empezo a
    | contradecir al documento legal.
    */
    test('ningun lote acomodado nace desalineado', function (): void {
        foreach (['250.0000', '387.5000', '613.0405'] as $indice => $area) {
            Lote::factory()->enBloque($this->bloque)
                ->conMedidas($area, '1200.00')
                ->create(['numero' => (string) ($indice + 1)]);
        }

        new AcomodadorDelPlano()->acomodarBloque($this->bloque, new ParametrosDeAcomodo(fondoVaras: '25'));

        foreach (Lote::query()->get() as $lote) {
            expect($lote->poligonoDesalineado())->toBeFalse()
                ->and($lote->discrepanciaDeAreaEnPorcentaje())->toBeLessThan(0.0001);
        }
    });

    test('el frente sale del area de cada lote', function (): void {
        $angosto = Lote::factory()->enBloque($this->bloque)->conMedidas('250.0000', '1200.00')->create(['numero' => '1']);
        $ancho = Lote::factory()->enBloque($this->bloque)->conMedidas('500.0000', '1200.00')->create(['numero' => '2']);

        new AcomodadorDelPlano()->acomodarBloque($this->bloque, new ParametrosDeAcomodo(fondoVaras: '25'));

        // 250/25 = 10 varas de frente; 500/25 = 20, arrancando donde termino el primero.
        expect($angosto->refresh()->verticesPoligono())
            ->toBe([[0.0, 0.0], [10.0, 0.0], [10.0, 25.0], [0.0, 25.0]])
            ->and($ancho->refresh()->verticesPoligono())
            ->toBe([[10.0, 0.0], [30.0, 0.0], [30.0, 25.0], [10.0, 25.0]]);
    });

    /*
    | El relleno a 3 digitos del codigo existe justo para esto: ordenar por
    | `numero` pondria el 10 antes que el 9 y el plano saldria barajado.
    */
    test('respeta el orden del codigo, no el alfabetico del numero', function (): void {
        $nueve = Lote::factory()->enBloque($this->bloque)->conMedidas('250.0000', '1200.00')->create(['numero' => '9']);
        $diez = Lote::factory()->enBloque($this->bloque)->conMedidas('250.0000', '1200.00')->create(['numero' => '10']);

        new AcomodadorDelPlano()->acomodarBloque($this->bloque, new ParametrosDeAcomodo(fondoVaras: '25'));

        expect($nueve->refresh()->verticesPoligono()[0])->toBe([0.0, 0.0])
            ->and($diez->refresh()->verticesPoligono()[0])->toBe([10.0, 0.0]);
    });

    test('un lote vendido tambien se dibuja', function (): void {
        $vendido = Lote::factory()->enBloque($this->bloque)
            ->conMedidas('250.0000', '1200.00')
            ->conEstado(EstadoLote::Vendido)
            ->create(['numero' => '1']);

        new AcomodadorDelPlano()->acomodarBloque($this->bloque, new ParametrosDeAcomodo(fondoVaras: '25'));

        expect($vendido->refresh()->tienePoligono())->toBeTrue()
            ->and($vendido->getAttribute('estado'))->toBe(EstadoLote::Vendido);
    });
});

describe('Acomodador — filas', function (): void {
    beforeEach(function (): void {
        foreach (range(1, 5) as $numero) {
            Lote::factory()->enBloque($this->bloque)
                ->conMedidas('250.0000', '1200.00')
                ->create(['numero' => (string) $numero]);
        }
    });

    test('reparte parejo y el sobrante queda arriba', function (): void {
        new AcomodadorDelPlano()->acomodarBloque(
            $this->bloque,
            new ParametrosDeAcomodo(fondoVaras: '25', filas: 2, numeracion: Numeracion::PorFilas)
        );

        $porNumero = Lote::query()->get()->keyBy(fn (Lote $lote): string => (string) $lote->getAttribute('numero'));

        // Fila 0 se lleva 3 lotes (y = 0), fila 1 se lleva 2 (y = 25).
        expect($porNumero['3']->verticesPoligono()[0][1])->toBe(0.0)
            ->and($porNumero['4']->verticesPoligono()[0][1])->toBe(25.0)
            ->and($porNumero['4']->verticesPoligono()[0][0])->toBe(0.0);
    });

    /*
    | En serpentina la fila de abajo se recorre al reves: el 4 queda a la
    | derecha, pegado al 3 que cierra la fila de arriba.
    */
    test('en serpentina la fila impar va al reves', function (): void {
        new AcomodadorDelPlano()->acomodarBloque(
            $this->bloque,
            new ParametrosDeAcomodo(fondoVaras: '25', filas: 2)
        );

        $porNumero = Lote::query()->get()->keyBy(fn (Lote $lote): string => (string) $lote->getAttribute('numero'));

        expect($porNumero['5']->verticesPoligono()[0][0])->toBe(0.0)
            ->and($porNumero['4']->verticesPoligono()[0][0])->toBe(10.0);
    });
});

describe('Acomodador — el proyecto entero', function (): void {
    test('apila los bloques y marca el plano como esquematico', function (): void {
        $segundo = Bloque::factory()->create([
            'proyecto_id' => $this->proyecto->getKey(),
            'nombre'      => 'B',
            'orden'       => 2,
        ]);

        $enA = Lote::factory()->enBloque($this->bloque)->conMedidas('250.0000', '1200.00')->create(['numero' => '1']);
        $enB = Lote::factory()->enBloque($segundo)->conMedidas('250.0000', '1200.00')->create(['numero' => '1']);

        $dibujados = new AcomodadorDelPlano()->acomodarProyecto(
            $this->proyecto,
            new ParametrosDeAcomodo(fondoVaras: '25', filas: 1, separacionBloquesVaras: '10')
        );

        // Bloque A ocupa y = 0..25; B arranca 10 varas mas abajo.
        expect($dibujados)->toBe(2)
            ->and($enA->refresh()->verticesPoligono()[0][1])->toBe(0.0)
            ->and($enB->refresh()->verticesPoligono()[0][1])->toBe(35.0)
            ->and($this->proyecto->refresh()->getAttribute('plano_esquematico'))->toBeTrue();
    });

    test('un bloque sin lotes no deja hueco en el plano', function (): void {
        Bloque::factory()->create([
            'proyecto_id' => $this->proyecto->getKey(),
            'nombre'      => 'B',
            'orden'       => 2,
        ]);

        $tercero = Bloque::factory()->create([
            'proyecto_id' => $this->proyecto->getKey(),
            'nombre'      => 'C',
            'orden'       => 3,
        ]);

        Lote::factory()->enBloque($this->bloque)->conMedidas('250.0000', '1200.00')->create(['numero' => '1']);
        $enC = Lote::factory()->enBloque($tercero)->conMedidas('250.0000', '1200.00')->create(['numero' => '1']);

        new AcomodadorDelPlano()->acomodarProyecto(
            $this->proyecto,
            new ParametrosDeAcomodo(fondoVaras: '25', filas: 1, separacionBloquesVaras: '10')
        );

        // C queda pegado a A, no a 70 varas por el bloque vacio del medio.
        expect($enC->refresh()->verticesPoligono()[0][1])->toBe(35.0);
    });

    test('un proyecto sin lotes no queda marcado como esquematico', function (): void {
        $dibujados = new AcomodadorDelPlano()->acomodarProyecto(
            $this->proyecto,
            new ParametrosDeAcomodo(fondoVaras: '25')
        );

        expect($dibujados)->toBe(0)
            ->and($this->proyecto->refresh()->getAttribute('plano_esquematico'))->toBeFalse();
    });
});
