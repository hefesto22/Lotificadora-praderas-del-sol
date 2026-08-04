<?php

declare(strict_types=1);

use App\Models\Lote;
use App\Models\Proyecto;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Spatie\Activitylog\Models\Activity;

/*
| activitylog v5 movio el diff de atributos de `properties` a su propia
| columna `attribute_changes` y elimino los batches. Estos tests fijan ese
| contrato: si una v6 vuelve a moverlo, se ponen rojos aca y no en la
| pantalla de auditoria del cliente.
|
| Las consultas van con where() explicito en vez de los scopes forSubject()/
| forEvent(): son la misma query y no dependen de que el paquete mantenga
| los nombres de los scopes.
*/

function ultimaActividadDe(Model $modelo, ?string $evento = null): Activity
{
    $consulta = Activity::query()
        ->where('subject_type', $modelo->getMorphClass())
        ->where('subject_id', $modelo->getKey());

    if ($evento !== null) {
        $consulta->where('event', $evento);
    }

    return $consulta->latest('id')->firstOrFail();
}

describe('Activity Log v5 — esquema', function (): void {
    test('la tabla quedo con attribute_changes y sin batch_uuid', function (): void {
        expect(Schema::hasColumn('activity_log', 'attribute_changes'))->toBeTrue();
        expect(Schema::hasColumn('activity_log', 'batch_uuid'))->toBeFalse();
    });
});

describe('Activity Log v5 — registro de cambios', function (): void {
    test('un update guarda el diff en attribute_changes y deja properties vacio', function (): void {
        $proyecto = Proyecto::factory()->create(['nombre' => 'Praderas del Sol']);

        $proyecto->update(['nombre' => 'Praderas del Sol II']);

        $actividad = ultimaActividadDe($proyecto, 'updated');

        expect($actividad->attribute_changes?->get('attributes'))->toBe(['nombre' => 'PRADERAS DEL SOL II']);
        expect($actividad->attribute_changes?->get('old'))->toBe(['nombre' => 'PRADERAS DEL SOL']);
        expect($actividad->properties?->toArray() ?? [])->toBe([]);
    });

    test('guardar sin cambios no registra nada', function (): void {
        $proyecto = Proyecto::factory()->create();
        $antes = Activity::query()->count();

        $proyecto->update(['nombre' => $proyecto->getAttribute('nombre')]);

        expect(Activity::query()->count())->toBe($antes);
    });

    test('dontLogEmptyChanges: cambiar un atributo fuera de la lista blanca no registra nada', function (): void {
        $proyecto = Proyecto::factory()->create();
        $antes = Activity::query()->count();

        $proyecto->update(['observaciones' => 'Nota interna que no se audita']);

        expect(Activity::query()->count())->toBe($antes);
    });

    test('el causer es el usuario autenticado y la descripcion sale en espanol', function (): void {
        $admin = actingAsAdmin();

        $proyecto = Proyecto::factory()->create();

        $actividad = ultimaActividadDe($proyecto, 'created');

        expect($actividad->causer_id)->toBe($admin->id);
        expect($actividad->description)->toBe('Proyecto created');
    });

    test('el diff de un lote guarda el dinero como string, nunca como float (§8.3.1)', function (): void {
        $lote = Lote::factory()->conMedidas('100.0000', '1000.00')->create();

        $lote->update(['precio_vara' => '1250.50']);

        $nuevos = (array) (ultimaActividadDe($lote, 'updated')->attribute_changes?->get('attributes') ?? []);

        expect($nuevos['precio_vara'] ?? null)->toBe('1250.50');
        expect($nuevos['valor'] ?? null)->toBe('125050.00');
    });
});
