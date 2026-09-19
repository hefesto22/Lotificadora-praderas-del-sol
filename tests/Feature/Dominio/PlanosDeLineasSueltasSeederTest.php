<?php

declare(strict_types=1);

use App\Domain\Enums\UnidadDeArea;
use App\Models\Bloque;
use App\Models\Lote;
use App\Models\Proyecto;
use Database\Seeders\Clientes\ColoniaRioBlancoSeeder;
use Database\Seeders\Clientes\LotificacionLaUnionSeeder;

/*
| Los dos planos de La Union, Copan (18-sep-2026), leidos del DXF del
| ingeniero TAL CUAL llego: sin un solo lote cerrado, todo en la capa «0»,
| los numeros sin letra y la manzana en un texto «BLOQUE X» aparte.
|
| La mecanica se prueba con dibujos chicos en Dxf/LineasSueltasTest. Esto
| es la red sobre los archivos de verdad: si alguien toca el armador, el
| importador o la declaracion, se pone rojo aca y no en una escritura.
|
| Los numeros estan escritos dos veces a proposito: en el seeder -que se
| niega a cargar otra cosa- y aca.
*/

/** 83 lotes en cinco manzanas, A a E. */
const RIO_BLANCO_MANZANAS = ['A' => 17, 'B' => 15, 'C' => 14, 'D' => 17, 'E' => 20];

/** La suma de los 83 rotulos «A=...v2» del plano. */
const RIO_BLANCO_AREA = 36431.17;

/** 91 lotes en seis manzanas con nombre, mas los cuatro de la G provisional. */
const LA_UNION_MANZANAS = ['A' => 8, 'B' => 21, 'C' => 26, 'D' => 24, 'E' => 6, 'F' => 6, 'G' => 4];

/** La suma de los 95 rotulos «... vr2» del plano. */
const LA_UNION_AREA = 34578.87;

function sembrarRioBlanco(): Proyecto
{
    app(ColoniaRioBlancoSeeder::class)->run();

    /** @var Proyecto $proyecto */
    $proyecto = Proyecto::query()->where('codigo', 'CRB')->sole();

    return $proyecto;
}

function sembrarLaUnion(): Proyecto
{
    app(LotificacionLaUnionSeeder::class)->run();

    /** @var Proyecto $proyecto */
    $proyecto = Proyecto::query()->where('codigo', 'LLU')->sole();

    return $proyecto;
}

/**
 * Cuantos lotes quedaron en cada manzana del proyecto.
 *
 * @return array<string, int>
 */
function repartoDe(Proyecto $proyecto): array
{
    $reparto = [];

    foreach (Bloque::query()->where('proyecto_id', $proyecto->getKey())->orderBy('nombre')->get() as $bloque) {
        $reparto[(string) $bloque->getAttribute('nombre')] = Lote::query()
            ->where('bloque_id', $bloque->getKey())
            ->count();
    }

    return $reparto;
}

function loteDe(Proyecto $proyecto, string $manzana, string $numero): Lote
{
    /** @var Bloque $bloque */
    $bloque = Bloque::query()
        ->where('proyecto_id', $proyecto->getKey())
        ->where('nombre', $manzana)
        ->sole();

    /** @var Lote $lote */
    $lote = Lote::query()
        ->where('bloque_id', $bloque->getKey())
        ->where('numero', $numero)
        ->sole();

    return $lote;
}

/**
 * La suma de las areas guardadas, con bcmath: lo que se afirma es el
 * numero exacto, y sumando floats no se puede afirmar eso.
 */
function areaSumadaDe(Proyecto $proyecto): float
{
    $total = '0';

    foreach (Lote::query()->where('proyecto_id', $proyecto->getKey())->pluck('area_varas') as $area) {
        if (is_numeric($area)) {
            $total = bcadd($total, (string) $area, 4);
        }
    }

    return (float) $total;
}

describe('COLONIA RIO BLANCO', function (): void {
    test('de un plano sin un solo lote cerrado entran los 83, cada uno en su manzana', function (): void {
        $proyecto = sembrarRioBlanco();

        $lotes = Lote::query()->where('proyecto_id', $proyecto->getKey())->get();

        expect(repartoDe($proyecto))->toBe(RIO_BLANCO_MANZANAS)
            ->and($lotes)->toHaveCount(83)
            ->and($proyecto->getAttribute('plano_esquematico'))->toBeFalse()
            ->and($lotes->filter(static fn (Lote $l): bool => ! $l->tienePoligono()))->toBeEmpty();
    });

    test('el area es la del rotulo, tambien en los cinco que vienen sin unidad', function (): void {
        $proyecto = sembrarRioBlanco();

        expect(areaSumadaDe($proyecto))->toBe(RIO_BLANCO_AREA)
            // «A=1,581.31v2», con coma de miles.
            ->and(loteDe($proyecto, 'A', '1')->getAttribute('area_varas'))->toBe('1581.3100')
            // «A=226.16 v2», con un espacio de mas.
            ->and(loteDe($proyecto, 'E', '19')->getAttribute('area_varas'))->toBe('226.1600')
            // Los que dicen «A=447.08» a secas: el dibujo los confirma.
            ->and(loteDe($proyecto, 'A', '9')->getAttribute('area_varas'))->toBe('447.0800')
            ->and(loteDe($proyecto, 'A', '17')->getAttribute('area_varas'))->toBe('1911.2900')
            ->and(loteDe($proyecto, 'B', '6')->getAttribute('area_varas'))->toBe('417.3600')
            ->and(loteDe($proyecto, 'E', '1')->getAttribute('area_varas'))->toBe('393.4100')
            ->and(loteDe($proyecto, 'E', '11')->getAttribute('area_varas'))->toBe('312.2900');
    });

    test('se vende en varas², con la vara del ingeniero', function (): void {
        $proyecto = sembrarRioBlanco();

        expect($proyecto->unidadDeArea())->toBe(UnidadDeArea::Varas)
            ->and($proyecto->varaEnMetros())->toBe('0.835000');
    });

    test('el E-10 entra con lo que dice su rotulo y queda marcado, y sus vecinos no', function (): void {
        $proyecto = sembrarRioBlanco();

        $e10 = loteDe($proyecto, 'E', '10');

        // El rotulo dice 312.00 y el dibujo mide 320.81. El area es la del
        // rotulo; la marca es para que alguien le pregunte al ingeniero.
        expect($e10->getAttribute('area_varas'))->toBe('312.0000')
            ->and($e10->poligonoDesalineado())->toBeTrue()
            ->and(loteDe($proyecto, 'E', '9')->poligonoDesalineado())->toBeFalse()
            ->and(loteDe($proyecto, 'A', '9')->poligonoDesalineado())->toBeFalse();
    });
});

describe('LOTIFICACION LA UNION', function (): void {
    test('entran los 95: los 91 de las seis manzanas y los cuatro sin nombre en la G', function (): void {
        $proyecto = sembrarLaUnion();

        expect(repartoDe($proyecto))->toBe(LA_UNION_MANZANAS)
            ->and(Lote::query()->where('proyecto_id', $proyecto->getKey())->count())->toBe(95)
            ->and(areaSumadaDe($proyecto))->toBe(LA_UNION_AREA);
    });

    test('los cuatro «1» del oriente quedan numerados del 1 al 4, enteros y con su area', function (): void {
        $proyecto = sembrarLaUnion();

        /*
        | El plano los numera «1», «1», «1» y «1», y a dos de ellos los
        | cruza una linea sobrante que los parte por la mitad. Que el
        | dibujo no quede desalineado es la prueba de que las dos mitades
        | se unieron: una sola mide 586.88 contra los 1,109.88 del rotulo.
        */
        foreach (['1', '2', '3', '4'] as $numero) {
            $lote = loteDe($proyecto, 'G', $numero);

            expect($lote->getAttribute('area_varas'))->toBe('1109.8800')
                ->and($lote->poligonoDesalineado())->toBeFalse();
        }
    });

    test('el A-4, que rotula «260.00» sin unidad, entra con 260', function (): void {
        $proyecto = sembrarLaUnion();

        expect(loteDe($proyecto, 'A', '4')->getAttribute('area_varas'))->toBe('260.0000');
    });

    test('correrlo dos veces deja lo mismo', function (): void {
        sembrarLaUnion();
        $proyecto = sembrarLaUnion();

        expect(repartoDe($proyecto))->toBe(LA_UNION_MANZANAS)
            ->and(Proyecto::query()->where('codigo', 'LLU')->count())->toBe(1);
    });
});
