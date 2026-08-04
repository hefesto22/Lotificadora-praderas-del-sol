<?php

declare(strict_types=1);

use App\Domain\Enums\EstadoCompromiso;
use App\Domain\Enums\EstadoLote;
use App\Domain\Enums\TipoCalle;
use App\Domain\Exceptions\ProyectoConMovimientoException;
use App\Models\Bloque;
use App\Models\Calle;
use App\Models\Compromiso;
use App\Models\Lote;
use App\Models\Proyecto;

/*
| Borrar un proyecto es la operación más destructiva del sistema: arrastra
| bloques, lotes, calles y compromisos. Las FK son restrictOnDelete, así
| que sin la cascada del modelo esto ni siquiera compila contra la base —
| revienta con un 23503. Estos tests fijan las dos mitades: que la cascada
| esté completa, y que la regla que la frena no se pueda saltar.
*/

describe('Proyecto — borrado en cascada', function (): void {
    test('se lleva bloques, lotes, calles y compromisos', function (): void {
        $proyecto = Proyecto::factory()->create(['codigo' => 'RVV']);
        $bloque = Bloque::factory()->delProyecto($proyecto)->create();
        $lote = Lote::factory()->enBloque($bloque)->create();
        Lote::factory()->enBloque($bloque)->count(4)->create();
        Calle::factory()->enProyecto($proyecto)->deTipo(TipoCalle::Avenida)
            ->conTrazo([[0.0, 0.0], [100.0, 0.0]])->create();

        // Un compromiso cerrado: el lote volvió a estar disponible, pero
        // la fila del compromiso sigue ahí y también tiene que irse.
        Compromiso::factory()->paraLote($lote)->cerrado(EstadoCompromiso::Liberado)->create();

        $proyecto->delete();

        expect(Proyecto::query()->where('codigo', 'RVV')->exists())->toBeFalse()
            ->and(Bloque::query()->where('proyecto_id', $proyecto->getKey())->count())->toBe(0)
            ->and(Lote::query()->where('proyecto_id', $proyecto->getKey())->count())->toBe(0)
            ->and(Calle::query()->where('proyecto_id', $proyecto->getKey())->count())->toBe(0)
            ->and(Compromiso::query()->where('lote_id', $lote->getKey())->count())->toBe(0);
    });

    test('no toca los otros proyectos', function (): void {
        $victima = Proyecto::factory()->create(['codigo' => 'RVV']);
        Lote::factory()->enBloque(Bloque::factory()->delProyecto($victima)->create())->count(3)->create();

        $queda = Proyecto::factory()->create(['codigo' => 'RPS']);
        Lote::factory()->enBloque(Bloque::factory()->delProyecto($queda)->create())->count(2)->create();

        $victima->delete();

        expect(Proyecto::query()->orderBy('codigo')->pluck('codigo')->all())->toBe(['RPS'])
            ->and($queda->lotes()->count())->toBe(2)
            ->and($queda->bloques()->count())->toBe(1);
    });

    test('un proyecto vacio se borra sin ceremonia', function (): void {
        $proyecto = Proyecto::factory()->create(['codigo' => 'PRB']);

        $proyecto->delete();

        expect(Proyecto::query()->where('codigo', 'PRB')->exists())->toBeFalse();
    });
});

describe('Proyecto — la regla que frena el borrado', function (): void {
    /*
    | Misma regla que PlanoRealPraderasSeeder para no pisar geometría
    | (§8.2): si un lote dejó de estar DISPONIBLE hay un cliente detrás.
    */
    test('un lote vendido lo impide', function (): void {
        $proyecto = Proyecto::factory()->create(['codigo' => 'RPS']);
        $bloque = Bloque::factory()->delProyecto($proyecto)->create();
        Lote::factory()->enBloque($bloque)->count(3)->create();
        Lote::factory()->enBloque($bloque)->conEstado(EstadoLote::Vendido)->create();

        expect(fn () => $proyecto->delete())
            ->toThrow(ProyectoConMovimientoException::class);

        expect(Proyecto::query()->where('codigo', 'RPS')->exists())->toBeTrue()
            ->and($proyecto->lotes()->count())->toBe(4);
    });

    test('un lote apartado tambien', function (): void {
        $proyecto = Proyecto::factory()->create(['codigo' => 'RPS']);
        $bloque = Bloque::factory()->delProyecto($proyecto)->create();
        Lote::factory()->enBloque($bloque)->conEstado(EstadoLote::Apartado)->create();

        expect(fn () => $proyecto->delete())
            ->toThrow(ProyectoConMovimientoException::class);
    });

    test('el mensaje dice cuantos son y de que proyecto', function (): void {
        $proyecto = Proyecto::factory()->create(['codigo' => 'RPS']);
        $bloque = Bloque::factory()->delProyecto($proyecto)->create();
        Lote::factory()->enBloque($bloque)->conEstado(EstadoLote::Vendido)->count(2)->create();

        expect(fn () => $proyecto->delete())
            ->toThrow(ProyectoConMovimientoException::class, 'El proyecto RPS tiene 2 lote(s)');
    });
});

describe('proyecto:eliminar', function (): void {
    test('borra el proyecto que se le pide', function (): void {
        $proyecto = Proyecto::factory()->create(['codigo' => 'RVV']);
        Lote::factory()->enBloque(Bloque::factory()->delProyecto($proyecto)->create())->count(3)->create();

        $this->artisan('proyecto:eliminar', ['codigo' => ['RVV'], '--forzar' => true])
            ->assertSuccessful();

        expect(Proyecto::query()->where('codigo', 'RVV')->exists())->toBeFalse();
    });

    test('acepta varios codigos de una', function (): void {
        Proyecto::factory()->create(['codigo' => 'RVV']);
        Proyecto::factory()->create(['codigo' => 'PRB']);
        Proyecto::factory()->create(['codigo' => 'RPS']);

        $this->artisan('proyecto:eliminar', ['codigo' => ['rvv', 'prb'], '--forzar' => true])
            ->assertSuccessful();

        expect(Proyecto::query()->orderBy('codigo')->pluck('codigo')->all())->toBe(['RPS']);
    });

    /*
    | El comando pregunta la regla ANTES en vez de provocar la excepcion y
    | atajarla: en consola la respuesta tiene que ser una linea legible.
    */
    test('falla sin borrar nada si el proyecto tiene movimiento', function (): void {
        $proyecto = Proyecto::factory()->create(['codigo' => 'RPS']);
        $bloque = Bloque::factory()->delProyecto($proyecto)->create();
        Lote::factory()->enBloque($bloque)->conEstado(EstadoLote::Vendido)->create();

        $this->artisan('proyecto:eliminar', ['codigo' => ['RPS'], '--forzar' => true])
            ->expectsOutputToContain('no se borra')
            ->assertFailed();

        expect(Proyecto::query()->where('codigo', 'RPS')->exists())->toBeTrue()
            ->and($proyecto->lotes()->count())->toBe(1);
    });

    /*
    | --liberar es la puerta explicita para un proyecto de prueba: no se
    | abre sola, hay que escribirla, y deja dicho que lotes toco.
    */
    test('con --liberar borra igual y dice que lotes libero', function (): void {
        $proyecto = Proyecto::factory()->create(['codigo' => 'RVV']);
        $bloque = Bloque::factory()->delProyecto($proyecto)->create();
        Lote::factory()->enBloque($bloque)->create(['numero' => '1']);
        Lote::factory()->enBloque($bloque)->conEstado(EstadoLote::Vendido)->create(['numero' => '2']);

        $this->artisan('proyecto:eliminar', [
            'codigo'    => ['RVV'],
            '--forzar'  => true,
            '--liberar' => true,
        ])
            ->expectsOutputToContain('RVV-'.$bloque->getAttribute('nombre').'-002')
            ->assertSuccessful();

        expect(Proyecto::query()->where('codigo', 'RVV')->exists())->toBeFalse()
            ->and(Lote::query()->where('proyecto_id', $proyecto->getKey())->count())->toBe(0);
    });

    test('un codigo que no existe falla y lo dice', function (): void {
        $this->artisan('proyecto:eliminar', ['codigo' => ['NADA'], '--forzar' => true])
            ->expectsOutputToContain('No existe ningún proyecto con código NADA.')
            ->assertFailed();
    });
});
