<?php

declare(strict_types=1);

use App\Domain\Enums\EstadoLote;
use App\Domain\Enums\UnidadDeArea;
use App\Models\Bloque;
use App\Models\Lote;
use App\Models\Proyecto;
use Database\Seeders\Clientes\AltamiraSeeder;
use Database\Seeders\Clientes\ElBambuSeeder;
use Database\Seeders\Clientes\InmobiliariaMayaSeeder;
use Tests\Fixtures\PlanoQueNoCuadraSeeder;

/*
| Los dos planos de Inmobiliaria Maya entran de un seeder que lee el DXF
| del topografo. Estos tests son la red: si alguien cambia el archivo, el
| importador o la declaracion, se pone rojo aca y no en una escritura.
|
| El numero que importa es el del PLANO IMPRESO, y esta escrito dos veces
| a proposito: en el seeder (que se niega a cargar otra cosa) y aca.
*/

/** 268 lotes en 16 manzanas, A a P. Contados del PDF de MAYAP. */
const ALTAMIRA_LOTES = 268;

const ALTAMIRA_MANZANAS = [
    'A' => 35, 'B' => 11, 'C' => 28, 'D' => 27,
    'E' => 17, 'F' => 16, 'G' => 14, 'H' => 14,
    'I' => 7,  'J' => 17, 'K' => 16, 'L' => 25,
    'M' => 13, 'N' => 15, 'O' => 8,  'P' => 5,
];

/** La suma de los 268 rotulos «A=...m2» del plano. */
const ALTAMIRA_AREA = 64214.72;

/** 84 lotes en seis manzanas, A a F. NO hay G. */
const BAMBU_LOTES = 84;

const BAMBU_MANZANAS = ['A' => 36, 'B' => 7, 'C' => 8, 'D' => 17, 'E' => 8, 'F' => 8];

/** Lo que dijo la importacion a mano del 13-ago-2026, sin una coma de diferencia. */
const BAMBU_AREA = 16438.69;

function sembrarAltamira(): Proyecto
{
    app(AltamiraSeeder::class)->run();

    /** @var Proyecto $proyecto */
    $proyecto = Proyecto::query()->where('codigo', 'RAL')->sole();

    return $proyecto;
}

function sembrarElBambu(): Proyecto
{
    app(ElBambuSeeder::class)->run();

    /** @var Proyecto $proyecto */
    $proyecto = Proyecto::query()->where('codigo', 'REB')->sole();

    return $proyecto;
}

/**
 * Cuantos lotes quedaron en cada manzana del proyecto.
 *
 * @return array<string, int>
 */
function manzanasDe(Proyecto $proyecto): array
{
    $manzanas = [];

    foreach (Bloque::query()->where('proyecto_id', $proyecto->getKey())->orderBy('nombre')->get() as $bloque) {
        $manzanas[(string) $bloque->getAttribute('nombre')] = Lote::query()
            ->where('bloque_id', $bloque->getKey())
            ->count();
    }

    return $manzanas;
}

/**
 * El area guardada de un lote, tal cual sale de la base.
 *
 * Devuelve el string de PostgreSQL —NUMERIC(12,4)— y no un float: lo que
 * se afirma es el numero exacto, y un float no puede afirmar eso.
 */
function areaDelLote(Proyecto $proyecto, string $manzana, string $numero): string
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

    return (string) $lote->getAttribute('area_varas');
}

function areaTotalDe(Proyecto $proyecto): float
{
    $total = '0';

    foreach (Lote::query()->where('proyecto_id', $proyecto->getKey())->pluck('area_varas') as $area) {
        if (is_numeric($area)) {
            $total = bcadd($total, (string) $area, 4);
        }
    }

    return (float) $total;
}

describe('RESIDENCIAL ALTAMIRA', function (): void {
    test('entra el plano completo, con su geometria', function (): void {
        $proyecto = sembrarAltamira();

        $lotes = Lote::query()->where('proyecto_id', $proyecto->getKey())->get();

        expect($lotes)->toHaveCount(ALTAMIRA_LOTES)
            ->and($proyecto->getAttribute('plano_esquematico'))->toBeFalse()
            // Ninguno viaja sin dibujo: las 268 caras del DXF cierran.
            ->and($lotes->filter(static fn (Lote $l): bool => ! $l->tienePoligono()))->toBeEmpty();
    });

    test('las 16 manzanas traen la cantidad que dice el plano impreso', function (): void {
        $proyecto = sembrarAltamira();

        expect(manzanasDe($proyecto))->toBe(ALTAMIRA_MANZANAS);
    });

    test('cada manzana declara lo que el plano dice que tiene', function (): void {
        $proyecto = sembrarAltamira();

        /*
        | `lotes_planificados` es un dato DECLARADO del plano, no un cache
        | del conteo: es lo que permite conciliar «el plano dice 35 y hay
        | 34». Que hoy coincidan es justamente lo que se esta afirmando.
        */
        $declarado = [];

        foreach (Bloque::query()->where('proyecto_id', $proyecto->getKey())->orderBy('nombre')->get() as $bloque) {
            $declarado[(string) $bloque->getAttribute('nombre')] = $bloque->getAttribute('lotes_planificados');
        }

        expect($declarado)->toBe(ALTAMIRA_MANZANAS);
    });

    test('la numeracion de cada manzana va de 1 a N, sin saltos ni repetidos', function (): void {
        $proyecto = sembrarAltamira();

        /*
        | Si el importador hubiera renumerado por choque —el desastre del
        | 13-ago-2026, cuando de 84 lotes de EL BAMBU quedaba UNO con el
        | numero correcto— este test lo ve: los numeros dejarian de ser
        | 1..N. El conteo por manzana, solo, no lo veria.
        */
        foreach (ALTAMIRA_MANZANAS as $nombre => $cuantos) {
            /** @var Bloque $bloque */
            $bloque = Bloque::query()
                ->where('proyecto_id', $proyecto->getKey())
                ->where('nombre', $nombre)
                ->sole();

            $numeros = Lote::query()
                ->where('bloque_id', $bloque->getKey())
                ->orderByRaw('numero::int')
                ->pluck('numero')
                ->all();

            expect($numeros)->toBe(array_map(strval(...), range(1, $cuantos)));
        }
    });

    test('se mide en metros2, no en varas2', function (): void {
        $proyecto = sembrarAltamira();

        expect($proyecto->unidadDeArea())->toBe(UnidadDeArea::Metros)
            ->and($proyecto->trabajaEnMetros())->toBeTrue()
            // En metros2 la vara del proyecto ES el metro: el area que se
            // guarda en `area_varas` son metros cuadrados.
            ->and($proyecto->varaEnMetros())->toBe('1.000000')
            ->and($proyecto->getAttribute('medidas_en_metros'))->toBeTrue();
    });

    test('🔴 el area es EXACTAMENTE la del plano, hasta el ultimo centimetro', function (): void {
        /*
        | 25-ago-2026. Mauricio, con el plano al lado de la pantalla: «no
        | esta dando medidas exactas; ese es 314.16 la medida real, tiene
        | que ser exacto».
        |
        | El area sale del rotulo que escribio el topografo, no del
        | contorno: un lado curvo entra teselado y la poligonal inscrita
        | encierra MENOS que el arco. Sin esto la suma daba 64,213.77 y
        | cada lote de esquina, 14 centesimas de menos.
        |
        | Nada de tolerancias en este test. El area multiplica al precio y
        | sale impresa en la escritura: o es el numero del plano, o no es.
        */
        $proyecto = sembrarAltamira();

        expect(areaTotalDe($proyecto))->toBe(ALTAMIRA_AREA);
    });

    test('🔴 los lotes de esquina traen el area del plano, no la del arco teselado', function (): void {
        $proyecto = sembrarAltamira();

        /*
        | Las esquinas de manzana se cierran con un arco de radio 20 m y el
        | plano les pone 314.16 m2 —que es pi por diez al cuadrado, no un
        | numero redondeado a mano—. El contorno teselado da 314.02: si
        | este test ve un 314.02, el area volvio a salir del dibujo.
        |
        | Y el G-7 y el J-1 son los dos que Mauricio senalo en pantalla.
        */
        $esquinas = Lote::query()
            ->where('proyecto_id', $proyecto->getKey())
            ->where('area_varas', '314.1600')
            ->count();

        expect($esquinas)->toBe(10)
            ->and(areaDelLote($proyecto, 'G', '7'))->toBe('314.1600')
            ->and(areaDelLote($proyecto, 'J', '1'))->toBe('296.7200')
            ->and(areaDelLote($proyecto, 'G', '11'))->toBe('382.2900')
            ->and(areaDelLote($proyecto, 'I', '1'))->toBe('507.0600');
    });

    test('entra sin precio, y el precio sin definir se ve', function (): void {
        $proyecto = sembrarAltamira();

        $conPrecio = Lote::query()
            ->where('proyecto_id', $proyecto->getKey())
            ->where('precio_vara', '>', 0)
            ->count();

        // Inmobiliaria Maya todavia no lo definio. Un lote sin precio no
        // se puede vender, y el sistema lo dice con todas las letras.
        expect($conPrecio)->toBe(0);
    });
});

describe('EL BAMBÚ', function (): void {
    test('entra con seis manzanas y ninguna G', function (): void {
        $proyecto = sembrarElBambu();

        /*
        | La manzana G venia del plano viejo de 26 lotes, repartido en
        | A…G, y quedo vacia en la carga del 13-ago-2026. El plano de 84
        | va de la A a la F.
        */
        expect(manzanasDe($proyecto))->toBe(BAMBU_MANZANAS)
            ->and(Lote::query()->where('proyecto_id', $proyecto->getKey())->count())->toBe(BAMBU_LOTES);
    });

    test('da la misma cuenta que la importacion a mano del 13-ago', function (): void {
        $proyecto = sembrarElBambu();

        expect(areaTotalDe($proyecto))->toBe(BAMBU_AREA)
            ->and($proyecto->unidadDeArea())->toBe(UnidadDeArea::Metros);
    });
});

describe('el seeder de la instalacion', function (): void {
    test('carga los dos desarrollos de una corrida', function (): void {
        app(InmobiliariaMayaSeeder::class)->run();

        expect(Proyecto::query()->whereIn('codigo', ['RAL', 'REB'])->count())->toBe(2)
            ->and(Lote::query()->count())->toBe(ALTAMIRA_LOTES + BAMBU_LOTES);
    });

    test('correrlo dos veces deja lo mismo', function (): void {
        sembrarElBambu();
        $proyecto = sembrarElBambu();

        expect(Lote::query()->where('proyecto_id', $proyecto->getKey())->count())->toBe(BAMBU_LOTES)
            ->and(Bloque::query()->where('proyecto_id', $proyecto->getKey())->count())->toBe(count(BAMBU_MANZANAS))
            ->and(Proyecto::query()->where('codigo', 'REB')->count())->toBe(1);
    });

    test('no pisa un lote que ya salio de disponible', function (): void {
        $proyecto = sembrarElBambu();

        /** @var Lote $lote */
        $lote = Lote::query()->where('proyecto_id', $proyecto->getKey())->firstOrFail();
        $lote->update(['estado' => EstadoLote::Vendido]);

        app(ElBambuSeeder::class)->run();

        // Ni borro ni recargo: el trazado quedo como estaba, con el
        // vendido adentro.
        expect(Lote::query()->where('proyecto_id', $proyecto->getKey())->count())->toBe(BAMBU_LOTES)
            ->and($lote->refresh()->getAttribute('estado'))->toBe(EstadoLote::Vendido);
    });
});

describe('el control del plano declarado', function (): void {
    test('un plano que no cuadra con el papel no deja NADA cargado', function (): void {
        /*
        | La leccion de la manzana I, 22-ago-2026: un plano a medias no
        | avisa que esta a medias. Aca se declara una manzana G de seis
        | lotes que el archivo no tiene, y lo que se afirma no es solo que
        | falle: es que la base quede como estaba.
        */
        expect(static fn () => app(PlanoQueNoCuadraSeeder::class)->run())
            ->toThrow(RuntimeException::class);

        expect(Proyecto::query()->where('codigo', 'ZZP')->count())->toBe(0)
            ->and(Bloque::query()->count())->toBe(0)
            ->and(Lote::query()->count())->toBe(0);
    });

    test('el error dice cual es la manzana que falta', function (): void {
        try {
            app(PlanoQueNoCuadraSeeder::class)->run();
        } catch (RuntimeException $e) {
            // Que el mensaje nombre la diferencia es la mitad del valor
            // del control: el que lo lee tiene que saber donde mirar.
            expect($e->getMessage())->toContain('-6 en G')
                ->and($e->getMessage())->toContain('no se cargo nada');

            return;
        }

        $this->fail('El seeder cargo un plano que no cuadra con lo declarado.');
    });
});
