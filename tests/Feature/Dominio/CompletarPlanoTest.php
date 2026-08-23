<?php

declare(strict_types=1);

use App\Domain\Enums\EstadoLote;
use App\Models\Bloque;
use App\Models\Lote;
use App\Models\Proyecto;

/*
| `olympo:completar-plano` existe para el caso en que ya no se puede volver
| a sembrar: la lotificadora está operando y el plano resultó incompleto.
| Toda la utilidad del comando depende de UNA propiedad —agrega y no toca
| nada más—, así que eso es lo que fijan estos tests. Si un día empieza a
| pisar un lote vendido, se pone rojo acá y no en un contrato.
*/

/**
 * Un plano de dos manzanas escrito a disco, como el que consume el seeder.
 *
 * @param list<array{bloque: string, numero: string, area: float, poligono?: list<array{float, float}>}> $lotes
 */
function planoEnDisco(array $lotes): string
{
    $ruta = sys_get_temp_dir().'/plano-'.uniqid().'.json';

    file_put_contents($ruta, json_encode(['lotes' => $lotes], JSON_THROW_ON_ERROR));

    return $ruta;
}

/**
 * @return list<array{float, float}>
 */
function cuadraDelPlano(float $x, float $y): array
{
    return [[$x, $y], [$x + 12.5, $y], [$x + 12.5, $y + 20.0], [$x, $y + 20.0]];
}

function proyectoConLaPrimeraFila(): Proyecto
{
    $proyecto = Proyecto::factory()->create(['codigo' => 'RPS']);
    $manzana = Bloque::factory()->delProyecto($proyecto)->create([
        'nombre'             => 'I',
        'lotes_planificados' => 3,
        'area_total_varas'   => '750.00',
    ]);

    foreach (['1', '2', '3'] as $numero) {
        Lote::factory()->enBloque($manzana)->conMedidas('250.0000', '1400.00')
            ->create(['numero' => $numero, 'poligono' => cuadraDelPlano(((float) $numero - 1) * 12.5, 0.0)]);
    }

    return $proyecto;
}

describe('olympo:completar-plano', function (): void {
    test('agrega los lotes que el plano tiene y la base no', function (): void {
        $proyecto = proyectoConLaPrimeraFila();

        $archivo = planoEnDisco([
            ['bloque' => 'I', 'numero' => '1', 'area' => 250.0, 'poligono' => cuadraDelPlano(0.0, 0.0)],
            ['bloque' => 'I', 'numero' => '2', 'area' => 250.0, 'poligono' => cuadraDelPlano(12.5, 0.0)],
            ['bloque' => 'I', 'numero' => '3', 'area' => 250.0, 'poligono' => cuadraDelPlano(25.0, 0.0)],
            ['bloque' => 'I', 'numero' => '4', 'area' => 337.5, 'poligono' => cuadraDelPlano(0.0, 20.0)],
            ['bloque' => 'I', 'numero' => '5', 'area' => 337.5, 'poligono' => cuadraDelPlano(12.5, 20.0)],
        ]);

        $this->artisan('olympo:completar-plano', ['codigo' => 'RPS', 'archivo' => $archivo])
            ->assertSuccessful();

        $numeros = Lote::query()
            ->where('proyecto_id', $proyecto->getKey())
            ->orderByRaw('numero::int')
            ->pluck('numero')
            ->all();

        expect($numeros)->toBe(['1', '2', '3', '4', '5']);

        /** @var Lote $nuevo */
        $nuevo = Lote::query()->where('proyecto_id', $proyecto->getKey())->where('numero', '4')->sole();

        expect($nuevo->getAttribute('area_varas'))->toBe('337.5000')
            ->and($nuevo->getAttribute('estado'))->toBe(EstadoLote::Disponible)
            ->and($nuevo->tienePoligono())->toBeTrue();

        unlink($archivo);
    });

    test('el lote nuevo hereda el precio que ya tiene su manzana', function (): void {
        $proyecto = proyectoConLaPrimeraFila();

        $archivo = planoEnDisco([
            ['bloque' => 'I', 'numero' => '4', 'area' => 337.5, 'poligono' => cuadraDelPlano(0.0, 20.0)],
        ]);

        $this->artisan('olympo:completar-plano', ['codigo' => 'RPS', 'archivo' => $archivo])
            ->assertSuccessful();

        /** @var Lote $viejo */
        $viejo = Lote::query()->where('proyecto_id', $proyecto->getKey())->where('numero', '1')->sole();
        /** @var Lote $nuevo */
        $nuevo = Lote::query()->where('proyecto_id', $proyecto->getKey())->where('numero', '4')->sole();

        $precio = $viejo->getAttribute('precio_vara');

        // bcmul pide `numeric-string` y un `(string)` sobre `mixed` no lo es:
        // el `is_numeric()` es lo unico que estrecha el tipo de verdad.
        $numerico = is_numeric($precio) ? (string) $precio : '0';

        // El precio no se inventa ni se toma del default: sale de los
        // hermanos de manzana, que son los que ya se están vendiendo.
        expect($nuevo->getAttribute('precio_vara'))->toBe($precio)
            ->and($nuevo->getAttribute('valor'))->toBe(bcmul('337.5000', $numerico, 2));

        unlink($archivo);
    });

    test('NO toca un lote que ya existe, aunque el archivo le dé otra área', function (): void {
        $proyecto = proyectoConLaPrimeraFila();

        /** @var Lote $vendido */
        $vendido = Lote::query()->where('proyecto_id', $proyecto->getKey())->where('numero', '1')->sole();
        $vendido->forceFill(['estado' => EstadoLote::Vendido])->saveQuietly();

        $archivo = planoEnDisco([
            // El archivo dice 999: es el caso peligroso, porque el área
            // multiplica al precio y ese lote ya tiene contrato.
            ['bloque' => 'I', 'numero' => '1', 'area' => 999.0, 'poligono' => cuadraDelPlano(0.0, 0.0)],
            ['bloque' => 'I', 'numero' => '4', 'area' => 337.5, 'poligono' => cuadraDelPlano(0.0, 20.0)],
        ]);

        $this->artisan('olympo:completar-plano', ['codigo' => 'RPS', 'archivo' => $archivo])
            ->assertSuccessful();

        expect($vendido->refresh()->getAttribute('area_varas'))->toBe('250.0000')
            ->and($vendido->getAttribute('estado'))->toBe(EstadoLote::Vendido);

        unlink($archivo);
    });

    test('NO borra lo que está en la base y no en el archivo', function (): void {
        $proyecto = proyectoConLaPrimeraFila();

        $archivo = planoEnDisco([
            ['bloque' => 'I', 'numero' => '9', 'area' => 337.5, 'poligono' => cuadraDelPlano(0.0, 20.0)],
        ]);

        $this->artisan('olympo:completar-plano', ['codigo' => 'RPS', 'archivo' => $archivo])
            ->assertSuccessful();

        expect(Lote::query()->where('proyecto_id', $proyecto->getKey())->count())->toBe(4);

        unlink($archivo);
    });

    test('correrlo dos veces no duplica nada', function (): void {
        $proyecto = proyectoConLaPrimeraFila();

        $archivo = planoEnDisco([
            ['bloque' => 'I', 'numero' => '4', 'area' => 337.5, 'poligono' => cuadraDelPlano(0.0, 20.0)],
        ]);

        $this->artisan('olympo:completar-plano', ['codigo' => 'RPS', 'archivo' => $archivo])->assertSuccessful();
        $this->artisan('olympo:completar-plano', ['codigo' => 'RPS', 'archivo' => $archivo])
            ->expectsOutputToContain('No hay nada que agregar')
            ->assertSuccessful();

        expect(Lote::query()->where('proyecto_id', $proyecto->getKey())->count())->toBe(4);

        unlink($archivo);
    });

    test('--ensayo informa y no escribe una sola fila', function (): void {
        $proyecto = proyectoConLaPrimeraFila();

        $archivo = planoEnDisco([
            ['bloque' => 'I', 'numero' => '4', 'area' => 337.5, 'poligono' => cuadraDelPlano(0.0, 20.0)],
        ]);

        $this->artisan('olympo:completar-plano', ['codigo' => 'RPS', 'archivo' => $archivo, '--ensayo' => true])
            ->assertSuccessful();

        expect(Lote::query()->where('proyecto_id', $proyecto->getKey())->count())->toBe(3);

        unlink($archivo);
    });

    test('el declarado de la manzana crece con el plano', function (): void {
        $proyecto = proyectoConLaPrimeraFila();

        $archivo = planoEnDisco([
            ['bloque' => 'I', 'numero' => '4', 'area' => 337.5, 'poligono' => cuadraDelPlano(0.0, 20.0)],
            ['bloque' => 'I', 'numero' => '5', 'area' => 337.5, 'poligono' => cuadraDelPlano(12.5, 20.0)],
        ]);

        $this->artisan('olympo:completar-plano', ['codigo' => 'RPS', 'archivo' => $archivo])
            ->assertSuccessful();

        /** @var Bloque $manzana */
        $manzana = Bloque::query()->where('proyecto_id', $proyecto->getKey())->where('nombre', 'I')->sole();

        // 3 x 250 + 2 x 337.50. `lotes_planificados` es el DECLARADO del
        // plano: si el plano creció, el declarado crece con él.
        expect($manzana->getAttribute('lotes_planificados'))->toBe(5)
            ->and((float) $manzana->getAttribute('area_total_varas'))->toBe(1425.0);

        unlink($archivo);
    });

    test('una manzana que nace vacía pide precio y no escribe nada', function (): void {
        $proyecto = proyectoConLaPrimeraFila();

        $archivo = planoEnDisco([
            ['bloque' => 'Z', 'numero' => '1', 'area' => 250.0, 'poligono' => cuadraDelPlano(0.0, 60.0)],
        ]);

        $this->artisan('olympo:completar-plano', ['codigo' => 'RPS', 'archivo' => $archivo])
            ->expectsOutputToContain('no tienen ningún lote del que heredar el precio')
            ->assertFailed();

        expect(Bloque::query()->where('proyecto_id', $proyecto->getKey())->count())->toBe(1);

        $this->artisan('olympo:completar-plano', [
            'codigo'        => 'RPS',
            'archivo'       => $archivo,
            '--precio-vara' => '1500',
        ])->assertSuccessful();

        /** @var Bloque $zeta */
        $zeta = Bloque::query()->where('proyecto_id', $proyecto->getKey())->where('nombre', 'Z')->sole();
        /** @var Lote $nuevo */
        $nuevo = Lote::query()->where('bloque_id', $zeta->getKey())->where('numero', '1')->sole();

        expect((float) $nuevo->getAttribute('precio_vara'))->toBe(1500.0)
            ->and(Bloque::query()->where('proyecto_id', $proyecto->getKey())->count())->toBe(2);

        unlink($archivo);
    });

    test('un lote sin polígono entra igual, marcado como sin dibujo', function (): void {
        $proyecto = proyectoConLaPrimeraFila();

        $archivo = planoEnDisco([
            ['bloque' => 'I', 'numero' => '4', 'area' => 337.5, 'poligono' => []],
        ]);

        $this->artisan('olympo:completar-plano', ['codigo' => 'RPS', 'archivo' => $archivo])
            ->assertSuccessful();

        /** @var Lote $nuevo */
        $nuevo = Lote::query()->where('proyecto_id', $proyecto->getKey())->where('numero', '4')->sole();

        // El área y el número son los que se venden: el lote entra. Lo que
        // no entra es un polígono inventado.
        expect($nuevo->tienePoligono())->toBeFalse()
            ->and($nuevo->getAttribute('observaciones'))->toContain('NO SE DIBUJA EN EL MAPA');

        unlink($archivo);
    });

    test('un proyecto o un archivo que no existen fallan sin escribir', function (): void {
        proyectoConLaPrimeraFila();

        $archivo = planoEnDisco([
            ['bloque' => 'I', 'numero' => '4', 'area' => 337.5, 'poligono' => cuadraDelPlano(0.0, 20.0)],
        ]);

        $this->artisan('olympo:completar-plano', ['codigo' => 'NADA', 'archivo' => $archivo])
            ->assertFailed();

        $this->artisan('olympo:completar-plano', ['codigo' => 'RPS', 'archivo' => 'no/existe.json'])
            ->assertFailed();

        expect(Lote::query()->count())->toBe(3);

        unlink($archivo);
    });
});
