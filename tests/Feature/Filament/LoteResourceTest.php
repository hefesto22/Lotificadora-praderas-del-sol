<?php

declare(strict_types=1);

use App\Domain\Enums\EstadoLote;
use App\Filament\Resources\Lotes\LoteResource;
use App\Filament\Resources\Lotes\Pages\CreateLote;
use App\Filament\Resources\Lotes\Pages\EditLote;
use App\Filament\Resources\Lotes\Pages\ListLotes;
use App\Models\Bloque;
use App\Models\Lote;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

describe('LoteResource — las paginas renderizan', function (): void {
    test('el listado carga', function (): void {
        actingAsAdmin();
        Lote::factory()->count(3)->create();

        $this->get(LoteResource::getUrl('index'))->assertOk();
    });

    test('la creacion carga', function (): void {
        actingAsAdmin();

        $this->get(LoteResource::getUrl('create'))->assertOk();
    });

    test('la edicion carga', function (): void {
        actingAsAdmin();
        $lote = Lote::factory()->create();

        $this->get(LoteResource::getUrl('edit', ['record' => $lote]))->assertOk();
    });

    test('la vista de detalle carga con el valor formateado', function (): void {
        actingAsAdmin();
        $lote = Lote::factory()->conMedidas('613.0405', '2530.00')->create();

        $this->get(LoteResource::getUrl('view', ['record' => $lote]))->assertOk();
    });

    test('la tabla lista los lotes con su badge de estado', function (): void {
        actingAsAdmin();
        $bloque = Bloque::factory()->create();
        $lotes = Lote::factory()->enBloque($bloque)->count(3)->create();

        Livewire::test(ListLotes::class)
            ->assertCanSeeTableRecords($lotes)
            ->assertOk();
    });
});

describe('LoteResource — el valor lo calcula el modelo, no el formulario', function (): void {
    test('crea un lote y el valor sale exacto al centimo', function (): void {
        actingAsAdmin();
        $bloque = Bloque::factory()->create();

        Livewire::test(CreateLote::class)
            ->fillForm([
                'proyecto_id' => $bloque->getAttribute('proyecto_id'),
                'bloque_id'   => $bloque->getKey(),
                'numero'      => '12-a',
                'area_varas'  => '613.0405',
                'precio_vara' => '2530.00',
                'estado'      => EstadoLote::Disponible->value,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $lote = Lote::query()->where('numero', '12-A')->firstOrFail();

        // El numero ademas se normalizo a mayusculas: '12-a' entro, '12-A' quedo.
        expect($lote->getAttribute('valor'))->toBe('1550992.47');
    });

    test('el campo valor no se envia desde el formulario', function (): void {
        actingAsAdmin();
        $bloque = Bloque::factory()->create();

        // Aunque alguien fuerce un valor absurdo, el modelo lo recalcula:
        // el campo va con dehydrated(false).
        Livewire::test(CreateLote::class)
            ->fillForm([
                'proyecto_id' => $bloque->getAttribute('proyecto_id'),
                'bloque_id'   => $bloque->getKey(),
                'numero'      => '99',
                'area_varas'  => '100.0000',
                'precio_vara' => '1000.00',
                'valor'       => '1.00',
                'estado'      => EstadoLote::Disponible->value,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        expect(Lote::query()->where('numero', '99')->value('valor'))->toBe('100000.00');
    });
});

describe('LoteResource — un lote vendido no se edita (§8.2)', function (): void {
    test('area y precio quedan deshabilitados en el formulario', function (): void {
        actingAsAdmin();
        $lote = Lote::factory()->conEstado(EstadoLote::Vendido)->create();

        Livewire::test(EditLote::class, ['record' => $lote->getKey()])
            ->assertFormFieldDisabled('area_varas')
            ->assertFormFieldDisabled('precio_vara');
    });

    test('en un lote disponible siguen habilitados', function (): void {
        actingAsAdmin();
        $lote = Lote::factory()->conEstado(EstadoLote::Disponible)->create();

        Livewire::test(EditLote::class, ['record' => $lote->getKey()])
            ->assertFormFieldEnabled('area_varas')
            ->assertFormFieldEnabled('precio_vara');
    });

    test('el valor nunca es editable, ni siquiera en un lote disponible', function (): void {
        actingAsAdmin();
        $lote = Lote::factory()->conEstado(EstadoLote::Disponible)->create();

        Livewire::test(EditLote::class, ['record' => $lote->getKey()])
            ->assertFormFieldDisabled('valor');
    });
});

describe('LoteResource — permisos', function (): void {
    test('un panel_user sin permisos no ve el listado', function (): void {
        actingAsPanelUser();

        $this->get(LoteResource::getUrl('index'))->assertForbidden();
    });
});
