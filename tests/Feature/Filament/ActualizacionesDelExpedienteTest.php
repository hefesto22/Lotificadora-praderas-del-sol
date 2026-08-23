<?php

declare(strict_types=1);

use App\Domain\Enums\EstadoVenta;
use App\Filament\Resources\Ventas\Pages\ViewVenta;
use App\Filament\Resources\Ventas\RelationManagers\ActualizacionesRelationManager;
use App\Models\Cliente;
use App\Models\Venta;
use App\Support\Roles;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/*
|--------------------------------------------------------------------------
| Qué se le tocó a este expediente — 22-ago-2026
|--------------------------------------------------------------------------
| Mauricio corrigió «ORTIZ» por «ORTIS» en el nombre de una clienta y quedó
| la pregunta: mañana, ¿cómo se sabe que ese nombre se cambió, cuándo y
| quién lo hizo? El dato ya se guardaba; lo que faltaba era dónde mirarlo.
|
| 🔴 LO QUE ESTOS TESTS CUIDAN DE VERDAD son dos cosas, y ninguna se ve en
| pantalla cuando falla:
|
|   1. Que NO se cuele lo ajeno. La consulta cruza dos `subject_type` con un
|      `whereIn` sobre el pivot; un paréntesis mal puesto y el expediente
|      muestra los cambios de los otros 115 — o peor, los nombres de
|      clientes que no tienen nada que ver con este contrato.
|
|   2. Que solo la vea el super_admin. Esta pestaña dice quién hizo qué, que
|      es información sobre las personas que usan el sistema.
*/

beforeEach(function (): void {
    $this->admin = actingAsAdmin();

    $this->venta = Venta::factory()->vigente(1)->create();

    $this->duena = Cliente::factory()->create(['nombre' => 'ELVA MARINA ORTIZ SANTAMARIA']);
    $this->venta->clientes()->attach($this->duena, ['titular' => true, 'orden' => 1]);

    $this->pestania = fn () => Livewire::test(ActualizacionesRelationManager::class, [
        'ownerRecord' => $this->venta->refresh(),
        'pageClass'   => ViewVenta::class,
    ]);
});

/*
| EL CASO QUE LA ORIGINO, tal cual pasó.
*/
test('corregir el apellido de un dueño queda escrito en su expediente', function (): void {
    $this->duena->update(['nombre' => 'ELVA MARINA ORTIS SANTAMARIA']);

    $correccion = ultimoAsientoDe(Cliente::class, $this->duena->getKey());

    /*
     * ⚠️ El `expect` de acá arriba no es decorativo: dar de alta a la
     * clienta YA dejó un asiento con el nombre viejo adentro, así que un
     * `assertSeeText('ORTIZ')` a secas pasaría con la corrección perdida.
     * Lo que se exige es que el asiento del UPDATE sea el que está en la
     * tabla; recién después el texto significa algo.
     */
    expect($correccion->getAttribute('event'))->toBe('updated');

    ($this->pestania)()
        ->assertCanSeeTableRecords([$correccion])
        // El antes y el después, que es la pregunta completa: «¿de qué a qué?».
        ->assertSeeText('ORTIZ SANTAMARIA')
        ->assertSeeText('ORTIS SANTAMARIA')
        // Y quién lo hizo, que es la mitad que no se puede reconstruir después.
        ->assertSeeText((string) $this->admin->getAttribute('name'));
});

test('lo que se le cambia al expediente también queda', function (): void {
    $this->venta->update(['estado' => EstadoVenta::Liquidada, 'cerrada_el' => today()]);

    ($this->pestania)()->assertCanSeeTableRecords([ultimoAsientoDe(Venta::class, $this->venta->getKey())]);
});

/*
| 🔴 LOS DOS QUE IMPORTAN: nada de lo ajeno entra.
|
| Uno por cada lado del OR de la consulta. Si el filtro se rompe, la
| pantalla no se ve rota — se ve llena, que es peor: parece que el
| expediente tuvo movimiento cuando no tuvo ninguno.
*/
test('el cliente de otro contrato no se cuela', function (): void {
    $ajeno = Cliente::factory()->create(['nombre' => 'RAMON ORDONEZ']);
    $ajeno->update(['nombre' => 'RAMON ORDOÑEZ']);

    ($this->pestania)()
        ->assertDontSeeText('RAMON')
        ->assertCanNotSeeTableRecords([ultimoAsientoDe(Cliente::class, $ajeno->getKey())]);
});

test('el expediente de al lado tampoco', function (): void {
    $otra = Venta::factory()->vigente(2)->create();
    $otra->update(['estado' => EstadoVenta::Liquidada, 'cerrada_el' => today()]);

    ($this->pestania)()->assertCanNotSeeTableRecords([ultimoAsientoDe(Venta::class, $otra->getKey())]);
});

/*
| Salir de titular no borra el pasado: `CambioDeTitular` deja al anterior en
| el pivot con su `titular_hasta`, y lo que se le corrigió mientras firmaba
| el contrato sigue siendo parte de la historia de ese contrato.
*/
test('el ex titular sigue apareciendo', function (): void {
    $this->venta->clientes()->updateExistingPivot($this->duena->getKey(), [
        'titular'       => false,
        'titular_hasta' => today(),
    ]);

    $this->duena->update(['nombre' => 'ELVA MARINA ORTIS SANTAMARIA']);

    // Por el registro y no por el texto, por lo mismo que arriba: su nombre
    // nuevo sale igual en la columna «En qué» de cualquier asiento suyo.
    ($this->pestania)()->assertCanSeeTableRecords([
        ultimoAsientoDe(Cliente::class, $this->duena->getKey()),
    ]);
});

describe('Quién la ve y qué puede hacerle', function (): void {
    test('solo el super_admin', function (): void {
        expect(ActualizacionesRelationManager::canViewForRecord($this->venta, ViewVenta::class))->toBeTrue();

        // Rosa Elena administra el residencial entero y aun así no la ve:
        // esto no habla de lotes, habla de las personas que usan el sistema.
        $this->actingAs(crearUsuarioConRol(Roles::ADMINISTRADORA));
        expect(ActualizacionesRelationManager::canViewForRecord($this->venta, ViewVenta::class))->toBeFalse();

        $this->actingAs(crearUsuarioConRol(Roles::RECEPTOR));
        expect(ActualizacionesRelationManager::canViewForRecord($this->venta, ViewVenta::class))->toBeFalse();
    });

    /*
    | Un historial que se puede editar no prueba nada — ni ante un cliente
    | que reclama que su nombre estaba bien escrito, ni ante uno mismo dentro
    | de dos años. Ni el super_admin le entra.
    */
    test('ni el super_admin puede crear, editar o borrar un asiento', function (): void {
        $this->duena->update(['nombre' => 'ELVA MARINA ORTIS SANTAMARIA']);

        $asiento = ultimoAsientoDe(Cliente::class, $this->duena->getKey());

        ($this->pestania)()
            ->assertActionDoesNotExist(TestAction::make('create')->table())
            ->assertActionDoesNotExist(TestAction::make('edit')->table($asiento))
            ->assertActionDoesNotExist(TestAction::make('delete')->table($asiento));
    });
});

/**
 * El asiento más nuevo de un registro concreto.
 *
 * ⚠️ Por `id` y no por `created_at`: el alta y la corrección de un mismo
 * cliente caen en el mismo segundo dentro de un test, y ahí `latest()`
 * devuelve cualquiera de los dos. Un test que a veces pasa es peor que uno
 * que siempre falla.
 */
function ultimoAsientoDe(string $modelo, mixed $id): Activity
{
    /** @var Activity $asiento */
    $asiento = Activity::query()
        ->where('subject_type', $modelo)
        ->where('subject_id', $id)
        ->latest('id')
        ->firstOrFail();

    return $asiento;
}
