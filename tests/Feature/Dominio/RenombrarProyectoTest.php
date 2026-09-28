<?php

declare(strict_types=1);

use App\Models\Bloque;
use App\Models\Lote;
use App\Models\Proyecto;
use App\Models\Venta;

/*
|--------------------------------------------------------------------------
| `olympo:renombrar-proyecto` — el código se cambia solo si no se imprimió
|--------------------------------------------------------------------------
| Salió de Río Blanco (28-sep-2026): el plano entró como CRB y el cuaderno
| del dueño firma sus contratos RRB. La ficha congela el código porque viaja
| adentro de los papeles; mientras no haya ninguno, cambiarlo no parte nada.
*/

function proyectoRioBlanco(): Proyecto
{
    $proyecto = Proyecto::factory()->create(['codigo' => 'CRB', 'nombre' => 'COLONIA RIO BLANCO']);
    $a = Bloque::factory()->delProyecto($proyecto)->create(['nombre' => 'A']);
    $d = Bloque::factory()->delProyecto($proyecto)->create(['nombre' => 'D']);

    Lote::factory()->enBloque($a)->create(['numero' => '2']);
    Lote::factory()->enBloque($d)->create(['numero' => '17']);

    return $proyecto->refresh();
}

/**
 * @return list<string>
 */
function codigosDeLotes(Proyecto $proyecto): array
{
    /** @var list<string> $codigos */
    $codigos = Lote::query()->where('proyecto_id', $proyecto->getKey())->orderBy('codigo')->pluck('codigo')->all();

    return $codigos;
}

describe('olympo:renombrar-proyecto', function (): void {
    test('cambia el código, el nombre y el de cada lote, y la dirección pública queda', function (): void {
        $proyecto = proyectoRioBlanco();
        $slug = $proyecto->getAttribute('slug');

        $this->artisan('olympo:renombrar-proyecto', [
            'codigo'   => 'crb',
            'nuevo'    => 'RRB',
            '--nombre' => 'Residencial Rio Blanco',
        ])->assertSuccessful();

        $proyecto->refresh();

        expect($proyecto->getAttribute('codigo'))->toBe('RRB')
            ->and($proyecto->getAttribute('nombre'))->toBe('RESIDENCIAL RIO BLANCO')
            ->and($proyecto->getAttribute('slug'))->toBe($slug)
            ->and(codigosDeLotes($proyecto))->toBe(['RRB-A-002', 'RRB-D-017']);
    });

    test('el ensayo muestra y no escribe', function (): void {
        $proyecto = proyectoRioBlanco();

        $this->artisan('olympo:renombrar-proyecto', ['codigo' => 'CRB', 'nuevo' => 'RRB', '--ensayo' => true])
            ->expectsOutputToContain('RRB-A-002')
            ->assertSuccessful();

        $proyecto->refresh();

        expect($proyecto->getAttribute('codigo'))->toBe('CRB')
            ->and(codigosDeLotes($proyecto))->toBe(['CRB-A-002', 'CRB-D-017']);
    });

    test('con un contrato numerado se niega', function (): void {
        $proyecto = proyectoRioBlanco();
        Venta::factory()->delProyecto($proyecto)->vigente(2, 'CRB')->create();

        $this->artisan('olympo:renombrar-proyecto', ['codigo' => 'CRB', 'nuevo' => 'RRB'])
            ->expectsOutputToContain('contrato(s) numerado(s)')
            ->assertFailed();

        expect($proyecto->refresh()->getAttribute('codigo'))->toBe('CRB')
            ->and(codigosDeLotes($proyecto))->toBe(['CRB-A-002', 'CRB-D-017']);
    });

    test('no pisa el código de otro proyecto', function (): void {
        proyectoRioBlanco();
        Proyecto::factory()->create(['codigo' => 'RRB']);

        $this->artisan('olympo:renombrar-proyecto', ['codigo' => 'CRB', 'nuevo' => 'RRB'])
            ->expectsOutputToContain('ya hay otro proyecto con código RRB')
            ->assertFailed();
    });

    test('un código con guión no pasa: el guión separa las partes del número', function (): void {
        proyectoRioBlanco();

        $this->artisan('olympo:renombrar-proyecto', ['codigo' => 'CRB', 'nuevo' => 'R-B'])
            ->expectsOutputToContain('no sirve como código')
            ->assertFailed();
    });
});
