<?php

declare(strict_types=1);

use App\Domain\Enums\EstadoLote;
use App\Domain\Enums\TipoCalle;
use App\Domain\Plano\PlanoDelProyecto;
use App\Models\Bloque;
use App\Models\Calle;
use App\Models\Lote;
use App\Models\Proyecto;

beforeEach(function (): void {
    $this->proyecto = Proyecto::factory()->create(['codigo' => 'RPS']);
    $this->bloque = Bloque::factory()->create([
        'proyecto_id' => $this->proyecto->getKey(),
        'nombre'      => 'A',
    ]);
});

describe('Plano del proyecto — proyecto sin dibujar', function (): void {
    /*
    | El caso del dia uno: 55 lotes cargados y ni uno dibujado. La pagina
    | tiene que abrir igual y decir cuantos faltan, no explotar ni mostrar
    | un SVG de tamano cero que el navegador colapsa.
    */
    test('un proyecto sin geometria devuelve un encuadre usable', function (): void {
        Lote::factory()->enBloque($this->bloque)->count(3)->create();

        $plano = new PlanoDelProyecto()->para($this->proyecto);

        expect($plano['hayGeometria'])->toBeFalse()
            ->and($plano['viewBox'])->toBe('0 0 100 100')
            ->and($plano['lotes'])->toBe([])
            ->and($plano['sinDibujar'])->toBe(3);
    });

    test('los lotes sin dibujar no se pierden, se cuentan', function (): void {
        Lote::factory()->enBloque($this->bloque)->create([
            'numero'   => '1',
            'poligono' => [[0, 0], [10, 0], [10, 25], [0, 25]],
        ]);
        Lote::factory()->enBloque($this->bloque)->count(4)->create();

        $plano = new PlanoDelProyecto()->para($this->proyecto);

        expect($plano['lotes'])->toHaveCount(1)
            ->and($plano['sinDibujar'])->toBe(4);
    });
});

describe('Plano del proyecto — encuadre', function (): void {
    test('el encuadre deja margen alrededor del dibujo', function (): void {
        Lote::factory()->enBloque($this->bloque)->create([
            'poligono' => [[0, 0], [10, 0], [10, 25], [0, 25]],
        ]);

        expect(new PlanoDelProyecto()->para($this->proyecto)['viewBox'])->toBe('-5 -5 20 35');
    });

    /*
    | Una calle se pinta como trazo grueso. Si el encuadre solo mirara la
    | linea central, la calle del borde saldria cortada al medio.
    */
    test('el ancho de una calle ensancha el encuadre', function (): void {
        Calle::factory()
            ->enProyecto($this->proyecto)
            ->deTipo(TipoCalle::Avenida)
            ->conTrazo([[0.0, 0.0], [100.0, 0.0]])
            ->create(['ancho_varas' => '10.0000']);

        expect(new PlanoDelProyecto()->para($this->proyecto)['viewBox'])->toBe('-10 -10 120 20');
    });

    test('coordenadas negativas no rompen el encuadre', function (): void {
        Lote::factory()->enBloque($this->bloque)->create([
            'poligono' => [[-20, -30], [-10, -30], [-10, -5], [-20, -5]],
        ]);

        expect(new PlanoDelProyecto()->para($this->proyecto)['viewBox'])->toBe('-25 -35 20 35');
    });
});

describe('Plano del proyecto — lo que se dibuja', function (): void {
    test('los vertices salen como lista de puntos de SVG', function (): void {
        Lote::factory()->enBloque($this->bloque)->create([
            'poligono' => [[0, 0], [10, 0], [10, 25], [0, 25]],
        ]);

        $lote = new PlanoDelProyecto()->para($this->proyecto)['lotes'][0];

        expect($lote['puntos'])->toBe('0,0 10,0 10,25 0,25')
            ->and($lote['centro'])->toBe([5.0, 12.5]);
    });

    /*
    | En el mapa cada lote se rotula con su numero y la letra de su
    | bloque pegada: 12B. El codigo entero no entra en 2.4 unidades de
    | alto, y un "12" solo no dice de cual de las 24 manzanas es.
    */
    test('el rotulo del mapa lleva el numero con la letra del bloque', function (): void {
        Lote::factory()->enBloque($this->bloque)->create([
            'numero'   => '12',
            'poligono' => [[0, 0], [10, 0], [10, 25], [0, 25]],
        ]);

        $lote = new PlanoDelProyecto()->para($this->proyecto)['lotes'][0];

        expect($lote['rotulo'])->toBe('12A')
            ->and($lote['bloque'])->toBe('A')
            ->and($lote['numero'])->toBe('12')
            // El codigo NO cambia: es el del contrato, con su relleno.
            ->and($lote['codigo'])->toBe('RPS-A-012');
    });

    test('cada bloque rotula con su propia letra', function (): void {
        $otroBloque = Bloque::factory()->create([
            'proyecto_id' => $this->proyecto->getKey(),
            'nombre'      => 'N',
        ]);

        Lote::factory()->enBloque($this->bloque)->create([
            'numero'   => '1',
            'poligono' => [[0, 0], [10, 0], [10, 25], [0, 25]],
        ]);
        Lote::factory()->enBloque($otroBloque)->create([
            'numero'   => '1',
            'poligono' => [[20, 0], [30, 0], [30, 25], [20, 25]],
        ]);

        $rotulos = array_column(new PlanoDelProyecto()->para($this->proyecto)['lotes'], 'rotulo');

        expect($rotulos)->toBe(['1A', '1N']);
    });

    test('el color sale del estado del lote', function (): void {
        Lote::factory()->enBloque($this->bloque)->conEstado(EstadoLote::Apartado)->create([
            'poligono' => [[0, 0], [10, 0], [10, 25], [0, 25]],
        ]);

        $lote = new PlanoDelProyecto()->para($this->proyecto)['lotes'][0];

        expect($lote['color'])->toBe(EstadoLote::Apartado->colorHex())
            ->and($lote['estado'])->toBe('apartado')
            ->and($lote['etiqueta'])->toBe('Apartado');
    });

    test('el resumen incluye los cuatro estados aunque esten en cero', function (): void {
        Lote::factory()->enBloque($this->bloque)->count(2)->create();
        Lote::factory()->enBloque($this->bloque)->conEstado(EstadoLote::Vendido)->create();

        $resumen = new PlanoDelProyecto()->para($this->proyecto)['resumen'];

        expect($resumen)->toBe([
            'disponible' => 2,
            'apartado'   => 0,
            'vendido'    => 1,
            'cancelado'  => 0,
        ]);
    });

    test('un lote cuyo dibujo contradice su area viaja marcado', function (): void {
        Lote::factory()->enBloque($this->bloque)->conMedidas('100.0000', '1000.00')->create([
            'poligono' => [[0, 0], [12, 0], [12, 12], [0, 12]],
        ]);

        expect(new PlanoDelProyecto()->para($this->proyecto)['lotes'][0]['desalineado'])->toBeTrue();
    });

    test('las calles viajan con su tipo y su ancho', function (): void {
        Calle::factory()
            ->enProyecto($this->proyecto)
            ->deTipo(TipoCalle::Boulevard)
            ->conTrazo([[0.0, 0.0], [50.0, 0.0], [50.0, 40.0]])
            ->create(['nombre' => 'Boulevard Central']);

        $calle = new PlanoDelProyecto()->para($this->proyecto)['calles'][0];

        expect($calle['nombre'])->toBe('BOULEVARD CENTRAL')
            ->and($calle['tipo'])->toBe('boulevard')
            ->and($calle['ancho'])->toBe(16.0)
            ->and($calle['puntos'])->toBe('0,0 50,0 50,40');
    });

    test('los lotes de OTRO proyecto no aparecen', function (): void {
        Lote::factory()->enBloque($this->bloque)->create([
            'poligono' => [[0, 0], [10, 0], [10, 25], [0, 25]],
        ]);

        $otro = Proyecto::factory()->create(['codigo' => 'OTRO']);
        $bloqueAjeno = Bloque::factory()->create(['proyecto_id' => $otro->getKey(), 'nombre' => 'Z']);
        Lote::factory()->enBloque($bloqueAjeno)->create([
            'poligono' => [[500, 500], [510, 500], [510, 525], [500, 525]],
        ]);

        $plano = new PlanoDelProyecto()->para($this->proyecto);

        expect($plano['lotes'])->toHaveCount(1)
            ->and($plano['viewBox'])->toBe('-5 -5 20 35');
    });
});
