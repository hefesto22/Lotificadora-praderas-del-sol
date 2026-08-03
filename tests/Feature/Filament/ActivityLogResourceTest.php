<?php

declare(strict_types=1);

use App\Filament\Resources\ActivityLogResource;
use App\Models\Lote;
use BezhanSalleh\FilamentShield\Resources\Roles\RoleResource;
use Filament\Facades\Filament;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

/*
| Este archivo existe por un bug que llego a la pantalla.
|
| El infolist usaba TextEntry::make('attribute_changes.old'). Con notacion de
| punto Filament resuelve un ARRAY y llama a formatStateUsing UNA VEZ POR
| ELEMENTO, unidos por coma: el callback recibia strings sueltos, is_array()
| daba false y la auditoria mostraba "—, —" con el dato intacto en la base.
|
| Los tests de dominio pasaban en verde porque el dato ESTABA bien. Lo que
| no habia era nadie mirando el render. Estos tests son ese alguien.
*/

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

describe('ActivityLogResource::comoJson', function (): void {
    test('arma un bloque json con los valores', function (): void {
        $salida = ActivityLogResource::comoJson(['precio_vara' => '2530.00', 'valor' => '1550992.47']);

        expect($salida)->toContain('precio_vara');
        expect($salida)->toContain('2530.00');
        expect($salida)->toStartWith("```json\n");
    });

    test('acepta una Collection, que es lo que devuelve el cast del modelo', function (): void {
        $salida = ActivityLogResource::comoJson(new Collection(['numero' => '12-A']));

        expect($salida)->toContain('12-A');
    });

    test('devuelve null cuando no hay nada, para que salga el placeholder', function (): void {
        expect(ActivityLogResource::comoJson(null))->toBeNull();
        expect(ActivityLogResource::comoJson([]))->toBeNull();
        expect(ActivityLogResource::comoJson(new Collection))->toBeNull();
    });
});

describe('ActivityLogResource — las paginas renderizan', function (): void {
    test('el listado carga', function (): void {
        actingAsAdmin();
        Lote::factory()->create();

        $this->get(ActivityLogResource::getUrl('index'))->assertOk();
    });

    test('el detalle de un update MUESTRA el diff, no un guion', function (): void {
        actingAsAdmin();

        $lote = Lote::factory()->conMedidas('613.0405', '2530.00')->create();
        $lote->update(['precio_vara' => '2600.00']);

        $actividad = Activity::query()
            ->where('subject_type', $lote->getMorphClass())
            ->where('subject_id', $lote->getKey())
            ->where('event', 'updated')
            ->latest('id')
            ->firstOrFail();

        $this->get(ActivityLogResource::getUrl('view', ['record' => $actividad]))
            ->assertOk()
            ->assertSee('precio_vara')
            ->assertSee('2530.00')   // valor anterior
            ->assertSee('2600.00')   // valor nuevo
            ->assertSee('1593905.30'); // valor recalculado, al centimo
    });

    test('el detalle de un create no revienta aunque no haya valores anteriores', function (): void {
        actingAsAdmin();

        $lote = Lote::factory()->create();

        $actividad = Activity::query()
            ->where('subject_type', $lote->getMorphClass())
            ->where('subject_id', $lote->getKey())
            ->where('event', 'created')
            ->latest('id')
            ->firstOrFail();

        $this->get(ActivityLogResource::getUrl('view', ['record' => $actividad]))
            ->assertOk()
            ->assertSee('Sin datos anteriores');
    });
});

/*
| Estos dos tests existen por un agujero que estuvo abierto varios commits.
|
| El descubrimiento de policies de Laravel mapea App\Models\X a
| App\Policies\XPolicy. `Activity` es de spatie/laravel-activitylog y `Role`
| es de spatie/laravel-permission: viven en vendor, así que sus policies
| nunca se descubrían solas.
|
| Y Filament, sin policy, NO deniega: get_authorization_response() corre los
| before callbacks del Gate y termina en Response::allow(). O sea que
| cualquier usuario con acceso al panel veía la bitácora completa y la
| pantalla donde se otorgan privilegios.
|
| `shield:generate` lo avisaba —"requires registration"— y ese aviso pasó
| desapercibido entre 78 permisos. El Gate::policy() de AppServiceProvider lo
| cierra; estos tests son los que impiden que vuelva a abrirse.
*/
describe('Policies de modelos que viven en vendor (§9.E.1)', function (): void {
    test('un panel_user sin permisos no puede ver la bitacora', function (): void {
        actingAsPanelUser();

        $this->get(ActivityLogResource::getUrl('index'))->assertForbidden();
    });

    test('un panel_user sin permisos no puede abrir un registro de la bitacora', function (): void {
        actingAsAdmin();
        $lote = Lote::factory()->create();

        $actividad = Activity::query()
            ->where('subject_type', $lote->getMorphClass())
            ->where('subject_id', $lote->getKey())
            ->latest('id')
            ->firstOrFail();

        actingAsPanelUser();

        $this->get(ActivityLogResource::getUrl('view', ['record' => $actividad]))->assertForbidden();
    });

    test('un panel_user sin permisos no puede ver la pantalla de Roles', function (): void {
        actingAsPanelUser();

        $this->get(RoleResource::getUrl('index'))->assertForbidden();
    });
});
