<?php

declare(strict_types=1);

use App\Domain\Enums\EstadoLote;
use App\Domain\Exceptions\LoteInmutableException;
use App\Domain\Exceptions\ValueObjectInvalidoException;
use App\Models\Bloque;
use App\Models\Lote;
use App\Models\Proyecto;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

describe('Lote — el valor se calcula al centimo (§8.3.4 y §9.C9)', function (): void {
    /*
    | Los mismos pares que rompian la version float de Monto. Si alguien
    | vuelve a meter float en el camino del dinero, estos se ponen rojos
    | antes de que un lote mal valuado llegue a un contrato.
    */
    test('valor = area x precio, exacto', function (string $area, string $precio, string $esperado): void {
        $lote = Lote::factory()->conMedidas($area, $precio)->create();

        expect($lote->getAttribute('valor'))->toBe($esperado);
    })->with([
        ['613.0405', '2530.00', '1550992.47'],
        ['390.9960', '631.25', '246816.23'],
        ['840.5740', '4317.50', '3629178.25'],
        ['250.0000', '1500.00', '375000.00'],
    ]);

    test('el valor se recalcula al cambiar el precio', function (): void {
        $lote = Lote::factory()->conMedidas('100.0000', '1000.00')->create();
        expect($lote->getAttribute('valor'))->toBe('100000.00');

        $lote->update(['precio_vara' => '1250.50']);

        expect($lote->fresh()?->getAttribute('valor'))->toBe('125050.00');
    });

    test('el valor persistido se puede recalcular desde cero y coincide', function (): void {
        $lote = Lote::factory()->create();

        expect($lote->fresh()?->calcularValor())->toBe($lote->getAttribute('valor'));
    });

    test('los numeros vuelven de Postgres como string, no como float', function (): void {
        $lote = Lote::factory()->conMedidas('613.0405', '2530.00')->create()->fresh();

        expect($lote?->getAttribute('area_varas'))->toBeString()
            ->and($lote?->getAttribute('precio_vara'))->toBeString()
            ->and($lote?->getAttribute('valor'))->toBeString();
    });

    test('rechaza un float asignado a un campo de dinero', function (): void {
        expect(fn () => Lote::factory()->create(['precio_vara' => 1350.50]))
            ->toThrow(ValueObjectInvalidoException::class);
    });
});

describe('Lote — un lote vendido no se edita (§8.2)', function (): void {
    test('el modelo lanza una excepcion del dominio', function (string $campo, string $valor): void {
        $lote = Lote::factory()->conEstado(EstadoLote::Vendido)->create();

        expect(fn () => $lote->update([$campo => $valor]))
            ->toThrow(LoteInmutableException::class);
    })->with([
        ['area_varas', '999.0000'],
        ['precio_vara', '9999.00'],
    ]);

    test('el trigger de Postgres lo impide aunque se escriba por fuera de Eloquent', function (): void {
        $lote = Lote::factory()->conEstado(EstadoLote::Vendido)->create();

        expect(fn () => DB::table('lotes')
            ->where('id', $lote->getKey())
            ->update(['precio_vara' => '9999.00']))
            ->toThrow(QueryException::class);
    });

    test('si el lote NO esta vendido si se puede editar', function (): void {
        $lote = Lote::factory()->conEstado(EstadoLote::Apartado)->create();

        $lote->update(['precio_vara' => '2000.00']);

        expect($lote->fresh()?->getAttribute('precio_vara'))->toBe('2000.00');
    });

    test('un lote vendido si puede cambiar de estado y observaciones', function (): void {
        $lote = Lote::factory()->conEstado(EstadoLote::Vendido)->create();

        $lote->update(['observaciones' => 'Escritura entregada']);

        expect($lote->fresh()?->getAttribute('observaciones'))->toBe('Escritura entregada');
    });
});

describe('Lote — invariantes en la base de datos', function (): void {
    test('el numero es unico dentro del bloque', function (): void {
        $bloque = Bloque::factory()->create();
        Lote::factory()->enBloque($bloque)->create(['numero' => '15']);

        expect(fn () => Lote::factory()->enBloque($bloque)->create(['numero' => '15']))
            ->toThrow(QueryException::class);
    });

    test('el mismo numero si puede repetirse en otro bloque', function (): void {
        $proyecto = Proyecto::factory()->create();
        $bloqueA = Bloque::factory()->delProyecto($proyecto)->create(['nombre' => 'A']);
        $bloqueB = Bloque::factory()->delProyecto($proyecto)->create(['nombre' => 'B']);

        Lote::factory()->enBloque($bloqueA)->create(['numero' => '15']);
        Lote::factory()->enBloque($bloqueB)->create(['numero' => '15']);

        expect(Lote::query()->where('numero', '15')->count())->toBe(2);
    });

    test('un lote no puede apuntar a un bloque de otro proyecto', function (): void {
        $bloqueAjeno = Bloque::factory()->create();
        $otroProyecto = Proyecto::factory()->create();

        expect(fn () => Lote::factory()->create([
            'bloque_id'   => $bloqueAjeno->getKey(),
            'proyecto_id' => $otroProyecto->getKey(),
        ]))->toThrow(QueryException::class);
    });

    test('rechaza un estado fuera de los cuatro contractuales', function (): void {
        $lote = Lote::factory()->create();

        expect(fn () => DB::table('lotes')
            ->where('id', $lote->getKey())
            ->update(['estado' => 'inventado']))
            ->toThrow(QueryException::class);
    });

    test('rechaza area menor o igual a cero', function (): void {
        expect(fn () => Lote::factory()->conMedidas('0.0000', '1500.00')->create())
            ->toThrow(QueryException::class);
    });
});

describe('Lote — scopes y estados', function (): void {
    test('disponibles y comprometidos filtran correctamente', function (): void {
        $bloque = Bloque::factory()->create();
        Lote::factory()->enBloque($bloque)->conEstado(EstadoLote::Disponible)->count(3)->create();
        Lote::factory()->enBloque($bloque)->conEstado(EstadoLote::Apartado)->create();
        Lote::factory()->enBloque($bloque)->conEstado(EstadoLote::Vendido)->create();
        Lote::factory()->enBloque($bloque)->conEstado(EstadoLote::Cancelado)->create();

        expect(Lote::query()->disponibles()->count())->toBe(3)
            ->and(Lote::query()->comprometidos()->count())->toBe(2);
    });

    test('el enum expone exactamente los cuatro estados del contrato', function (): void {
        expect(EstadoLote::valores())
            ->toBe(['disponible', 'apartado', 'vendido', 'cancelado']);
    });

    test('solo el estado vendido bloquea la edicion de valores', function (): void {
        expect(EstadoLote::Vendido->permiteEditarValores())->toBeFalse()
            ->and(EstadoLote::Apartado->permiteEditarValores())->toBeTrue()
            ->and(EstadoLote::Disponible->permiteEditarValores())->toBeTrue()
            ->and(EstadoLote::Cancelado->permiteEditarValores())->toBeTrue();
    });
});

describe('Bloque — declarado del plano contra lo cargado', function (): void {
    test('detecta lotes pendientes de cargar', function (): void {
        $bloque = Bloque::factory()->create(['lotes_planificados' => 5]);
        Lote::factory()->enBloque($bloque)->count(3)->create();

        expect($bloque->lotesRegistrados())->toBe(3)
            ->and($bloque->tieneLotesPendientesDeCargar())->toBeTrue();
    });

    test('no reporta pendientes cuando el plano esta completo', function (): void {
        $bloque = Bloque::factory()->create(['lotes_planificados' => 2]);
        Lote::factory()->enBloque($bloque)->count(2)->create();

        expect($bloque->tieneLotesPendientesDeCargar())->toBeFalse();
    });

    test('sin dato de plano no reporta pendientes', function (): void {
        $bloque = Bloque::factory()->create(['lotes_planificados' => null]);

        expect($bloque->tieneLotesPendientesDeCargar())->toBeFalse();
    });
});

describe('Proyecto — jerarquia', function (): void {
    test('accede a sus lotes sin pasar por bloques', function (): void {
        $proyecto = Proyecto::factory()->praderasDelSol()->create();
        $bloque = Bloque::factory()->delProyecto($proyecto)->create();
        Lote::factory()->enBloque($bloque)->count(4)->create();

        expect($proyecto->lotes()->count())->toBe(4)
            ->and($proyecto->bloques()->count())->toBe(1);
    });

    test('el scope activos excluye los inactivos', function (): void {
        Proyecto::factory()->count(2)->create();
        Proyecto::factory()->inactivo()->create();

        expect(Proyecto::query()->activos()->count())->toBe(2);
    });
});

/*
| El código legible existe para que buscar un lote entre 2,000 sea escribir
| "RPS-B-12", no filtrar tres veces. Y de paso arregla un bug que ya estaba
| en pantalla con 54 lotes: `numero` es texto, así que ordenar por él ponía
| el lote 2 después del 19.
*/
describe('Lote — código legible', function (): void {
    test('compone el código con el número rellenado a 3 dígitos', function (string $numero, string $esperado): void {
        expect(Lote::componerCodigo('RPS', 'B', $numero))->toBe($esperado);
    })->with([
        ['1', 'RPS-B-001'],
        ['12', 'RPS-B-012'],
        ['200', 'RPS-B-200'],
        ['1500', 'RPS-B-1500'],   // no se trunca si pasa de 3 dígitos
        ['12-A', 'RPS-B-012-A'],  // el sufijo se conserva
        ['BIS', 'RPS-B-BIS'],     // sin dígitos, se deja tal cual
    ]);

    /*
    | El rótulo del mapa es lo contrario del código: sin proyecto, sin
    | guiones y SIN relleno de ceros. Ahí manda que se lea de un vistazo
    | dentro de un polígono chico, no que ordene alfabéticamente.
    */
    test('el rotulo del mapa pega la letra del bloque al numero', function (string $numero, string $esperado): void {
        expect(Lote::componerRotulo('B', $numero))->toBe($esperado);
    })->with([
        ['1', '1B'],
        ['12', '12B'],
        ['200', '200B'],
    ]);

    test('el código se genera solo al crear el lote', function (): void {
        $proyecto = Proyecto::factory()->create(['codigo' => 'RPS']);
        $bloque = Bloque::factory()->delProyecto($proyecto)->create(['nombre' => 'B']);

        $lote = Lote::factory()->enBloque($bloque)->create(['numero' => '12']);

        expect($lote->fresh()?->getAttribute('codigo'))->toBe('RPS-B-012');
    });

    /*
    | El código se lee del bloque con una consulta fresca, no de la relación
    | cacheada: si se leyera de la relación en memoria, mover un lote de
    | bloque dejaría el código apuntando al bloque viejo.
    */
    test('el código se recalcula si el lote cambia de bloque', function (): void {
        $proyecto = Proyecto::factory()->create(['codigo' => 'RPS']);
        $bloqueA = Bloque::factory()->delProyecto($proyecto)->create(['nombre' => 'A']);
        $bloqueB = Bloque::factory()->delProyecto($proyecto)->create(['nombre' => 'B']);

        $lote = Lote::factory()->enBloque($bloqueA)->create(['numero' => '7']);
        expect($lote->fresh()?->getAttribute('codigo'))->toBe('RPS-A-007');

        $lote->update(['bloque_id' => $bloqueB->getKey()]);

        expect($lote->fresh()?->getAttribute('codigo'))->toBe('RPS-B-007');
    });

    test('el orden alfabético del código es el orden natural del número', function (): void {
        $proyecto = Proyecto::factory()->create(['codigo' => 'RPS']);
        $bloque = Bloque::factory()->delProyecto($proyecto)->create(['nombre' => 'A']);

        foreach (['19', '2', '10', '1', '20'] as $numero) {
            Lote::factory()->enBloque($bloque)->create(['numero' => $numero]);
        }

        $ordenados = Lote::query()->orderBy('codigo')->pluck('numero')->all();

        // Ordenando por `numero` esto daría 1, 10, 19, 2, 20.
        expect($ordenados)->toBe(['1', '2', '10', '19', '20']);
    });

    test('dos proyectos pueden tener el mismo bloque y número sin chocar', function (): void {
        $praderas = Proyecto::factory()->create(['codigo' => 'RPS']);
        $colinas = Proyecto::factory()->create(['codigo' => 'LMC']);

        $bloquePraderas = Bloque::factory()->delProyecto($praderas)->create(['nombre' => 'A']);
        $bloqueColinas = Bloque::factory()->delProyecto($colinas)->create(['nombre' => 'A']);

        $uno = Lote::factory()->enBloque($bloquePraderas)->create(['numero' => '1']);
        $otro = Lote::factory()->enBloque($bloqueColinas)->create(['numero' => '1']);

        expect($uno->fresh()?->getAttribute('codigo'))->toBe('RPS-A-001');
        expect($otro->fresh()?->getAttribute('codigo'))->toBe('LMC-A-001');
    });

    test('el código es único en toda la tabla', function (): void {
        $proyecto = Proyecto::factory()->create(['codigo' => 'RPS']);
        $bloque = Bloque::factory()->delProyecto($proyecto)->create(['nombre' => 'A']);

        Lote::factory()->enBloque($bloque)->create(['numero' => '1']);

        expect(fn (): Lote => Lote::factory()->enBloque($bloque)->create(['numero' => '1']))
            ->toThrow(QueryException::class);
    });
});
