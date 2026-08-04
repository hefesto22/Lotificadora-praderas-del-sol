<?php

declare(strict_types=1);

use App\Domain\Enums\EstadoLote;
use App\Domain\Plano\PlanoDelProyecto;
use App\Models\Bloque;
use App\Models\Calle;
use App\Models\Lote;
use App\Models\Proyecto;
use Database\Seeders\PlanoRealPraderasSeeder;

/*
| El seeder carga geometria que despues multiplica al precio por vara.
| Estos tests son la red: si alguien toca el JSON del plano o el seeder y
| cambia una cuenta, se pone rojo aca y no en una escritura.
*/

/** Los 24 del plano, de la A a la X: el DXF nativo los trae rotulados. */
const BLOQUES = 24;

const TOTAL_LOTES = 301;

/**
 * Las calles no se cargan: el calco del plano nativo las dibuja con sus
 * nombres y sus anchos, que es mejor que un poligono deducido.
 */
const TOTAL_CALLES = 0;

/** Lotes tipo de 12.50V x 20.00V. El area sale del texto del plano. */
const LOTES_TIPO = 233;

function sembrarPlanoReal(): Proyecto
{
    putenv('PRECIO_VARA=1500');
    app(PlanoRealPraderasSeeder::class)->run();

    /** @var Proyecto $p */
    $p = Proyecto::query()->where('codigo', 'RPS')->sole();

    return $p;
}

describe('PlanoRealPraderasSeeder', function (): void {
    test('carga el plano completo con su geometria', function (): void {
        $proyecto = sembrarPlanoReal();

        $lotes = Lote::query()->where('proyecto_id', $proyecto->getKey())->get();
        $bloques = Bloque::query()->where('proyecto_id', $proyecto->getKey())->get();
        $calles = Calle::query()->where('proyecto_id', $proyecto->getKey())->get();

        expect($lotes)->toHaveCount(TOTAL_LOTES)
            ->and($bloques)->toHaveCount(BLOQUES)
            ->and($calles)->toHaveCount(TOTAL_CALLES)
            ->and($proyecto->getAttribute('plano_esquematico'))->toBeFalse();

        // Los 301 dibujados: el plano nativo cierra todas las caras.
        expect($lotes->filter(fn (Lote $l): bool => ! $l->tienePoligono()))->toBeEmpty();
    });

    test('estan los 24 bloques del plano, con su letra', function (): void {
        $proyecto = sembrarPlanoReal();

        $nombres = Bloque::query()
            ->where('proyecto_id', $proyecto->getKey())
            ->orderBy('nombre')
            ->pluck('nombre')
            ->all();

        expect($nombres)->toBe(range('A', 'X'))
            ->and($nombres)->toHaveCount(BLOQUES);
    });

    test('no entra ninguna cara que no sea un lote vendible', function (): void {
        $proyecto = sembrarPlanoReal();

        /*
        | El plano rotula con area tanto los lotes como las areas verdes
        | (4,668.94 y 2,436.33 vr2) y el resto de finca (17,198.06 y
        | 12,213.06). Ninguno de esos se vende como lote, asi que ninguno
        | debe existir como lote.
        */
        $areas = Lote::query()
            ->where('proyecto_id', $proyecto->getKey())
            ->pluck('area_varas')
            ->map(static fn (string $a): float => (float) $a);

        expect($areas->min())->toBeGreaterThanOrEqual(40.0)
            ->and($areas->max())->toBeLessThanOrEqual(1500.0);
    });

    test('el plano expone el calco del dibujo original', function (): void {
        $proyecto = sembrarPlanoReal();

        $plano = (new PlanoDelProyecto)->para($proyecto);

        // El calco es el dibujo del topografo: el perimetro, las calles con
        // su nombre, las areas verdes y la cancha. Los lotes van encima.
        expect($plano['calco'])->toBeString()
            ->and($plano['calco'])->toContain('rps-fondo.json')
            ->and(is_file(public_path('planos/rps-fondo.json')))->toBeTrue();
    });

    test('solo el X-15 tiene el dibujo peleado con su area, y viaja marcado', function (): void {
        $proyecto = sembrarPlanoReal();

        /*
        | §8.2 / TOLERANCIA_DE_AREA. El area la manda el texto del plano;
        | el poligono es el dibujo. Cuando los dos no cuentan lo mismo, el
        | lote sale MARCADO en el mapa en vez de callarselo.
        |
        | Hay uno solo: el X-15. Su cara da 461.78 vr2 contra las 471.68
        | que dice el plano — le faltan 6.89 m2 que en el dibujo quedaron
        | del lado de la calzada, y no hay ninguna pieza suelta que
        | sumarle. Se carga con el area del plano (que es la que se vende)
        | y con el dibujo que hay (que es el que se ve), y la marca avisa
        | de la diferencia.
        |
        | Si aparece un segundo desalineado, algo cambio: hay que mirarlo.
        */
        $desalineados = Lote::query()
            ->where('proyecto_id', $proyecto->getKey())
            ->get()
            ->filter(fn (Lote $l): bool => $l->poligonoDesalineado())
            ->map(fn (Lote $l): string => (string) $l->getAttribute('codigo'))
            // values(): filter() conserva la clave original, y un array
            // [281 => '...'] no es identico a [0 => '...'].
            ->values()
            ->all();

        expect($desalineados)->toBe(['RPS-X-015']);
    });

    test('los lotes tipo llevan el area exacta del plano', function (): void {
        $proyecto = sembrarPlanoReal();

        $tipo = Lote::query()
            ->where('proyecto_id', $proyecto->getKey())
            ->where('area_varas', '250.0000')
            ->count();

        expect($tipo)->toBe(LOTES_TIPO);
    });

    test('el valor de cada lote sale de su area, sin float', function (): void {
        $proyecto = sembrarPlanoReal();

        /** @var Lote $lote */
        $lote = Lote::query()
            ->where('proyecto_id', $proyecto->getKey())
            ->where('area_varas', '250.0000')
            ->firstOrFail();

        expect($lote->getAttribute('valor'))
            ->toBe(bcmul('250.0000', '1500.00', 2));
    });

    test('todo entra en mayusculas', function (): void {
        $proyecto = sembrarPlanoReal();

        $bloques = Bloque::query()->where('proyecto_id', $proyecto->getKey())->pluck('nombre');

        expect($proyecto->getAttribute('nombre'))->toBe('RESIDENCIAL PRADERAS DEL SOL')
            ->and($proyecto->getAttribute('municipio'))->toBe('CORPUS')
            ->and($proyecto->getAttribute('observaciones'))->toBe(mb_strtoupper((string) $proyecto->getAttribute('observaciones')))
            ->and($bloques->every(static fn (string $n): bool => $n === mb_strtoupper($n)))->toBeTrue();
    });

    test('correrlo dos veces deja el mismo plano, no el doble', function (): void {
        sembrarPlanoReal();
        $proyecto = sembrarPlanoReal();

        expect(Lote::query()->where('proyecto_id', $proyecto->getKey())->count())->toBe(TOTAL_LOTES)
            ->and(Bloque::query()->where('proyecto_id', $proyecto->getKey())->count())
            ->toBe(BLOQUES);
    });

    test('NO pisa un lote que ya esta vendido', function (): void {
        $proyecto = sembrarPlanoReal();

        /** @var Lote $lote */
        $lote = Lote::query()->where('proyecto_id', $proyecto->getKey())->firstOrFail();
        $lote->forceFill(['estado' => EstadoLote::Vendido])->saveQuietly();

        $antes = Lote::query()->where('proyecto_id', $proyecto->getKey())->count();

        app(PlanoRealPraderasSeeder::class)->run();

        expect(Lote::query()->where('proyecto_id', $proyecto->getKey())->count())->toBe($antes)
            ->and($lote->refresh()->getAttribute('estado'))->toBe(EstadoLote::Vendido);
    });
});
