<?php

declare(strict_types=1);

use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Support\QuienRecibeElDinero;
use App\Models\Proyecto;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Quién recibe dinero, y en qué proyecto — 24-oct-2026
|--------------------------------------------------------------------------
| «Hay que hacer un toggle para marcar si ese usuario aparece como persona que
| recibe dinero o no, y si no está marcado como permitido en X proyecto,
| entonces no aparece en ese proyecto» — Mauricio, viendo a la administradora
| en la lista de un cobro.
|
| Lo que se marca es lo que aparece: ni el permiso ni el rol meten a nadie por
| su cuenta.
*/

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    actingAsAdmin();

    $this->norte = Proyecto::factory()->create(['codigo' => 'NOR']);
    $this->sur = Proyecto::factory()->create(['codigo' => 'SUR']);

    $this->elder = User::factory()->create(['name' => 'ELDER PINTO', 'is_active' => true]);
    $this->edwin = User::factory()->create(['name' => 'EDWIN ESPINOZA', 'is_active' => true]);

    $this->elder->proyectosDondeRecibe()->attach([$this->norte->getKey(), $this->sur->getKey()]);
    $this->edwin->proyectosDondeRecibe()->attach($this->sur->getKey());
});

test('cada proyecto ofrece solo a quien recibe dinero ahí', function (): void {
    expect(QuienRecibeElDinero::lista((int) $this->norte->getKey()))
        ->toBe([(int) $this->elder->getKey() => 'ELDER PINTO'])
        ->and(QuienRecibeElDinero::lista((int) $this->sur->getKey()))
        ->toBe([
            (int) $this->edwin->getKey() => 'EDWIN ESPINOZA',
            (int) $this->elder->getKey() => 'ELDER PINTO',
        ]);
});

/*
| El permiso de cobrar ya no mete a nadie en la lista: antes del 24-oct era
| justamente lo que hacía aparecer a la administradora en todos los cobros.
*/
test('sin ningún proyecto marcado no aparece, aunque pueda cobrar', function (): void {
    $rosa = User::factory()->create(['name' => 'ROSA ELENA', 'is_active' => true]);
    $rosa->givePermissionTo('Create:Recibo');

    expect(QuienRecibeElDinero::lista((int) $this->norte->getKey()))->not->toHaveKey((int) $rosa->getKey())
        ->and(QuienRecibeElDinero::lista())->not->toHaveKey((int) $rosa->getKey());
});

test('una cuenta desactivada no aparece en ninguna lista', function (): void {
    $this->elder->update(['is_active' => false]);

    expect(QuienRecibeElDinero::lista((int) $this->norte->getKey()))->toBe([])
        ->and(QuienRecibeElDinero::lista((int) $this->sur->getKey()))->toBe([(int) $this->edwin->getKey() => 'EDWIN ESPINOZA']);
});

test('una pantalla que no sabe de qué proyecto es el dinero ofrece a quien recibe en alguno', function (): void {
    expect(array_keys(QuienRecibeElDinero::lista()))->toBe([(int) $this->edwin->getKey(), (int) $this->elder->getKey()]);
});

/*
| Al corregir un recibo viejo, quien figura en el papel tiene que poder seguir
| figurando aunque ya no reciba dinero en ese proyecto.
*/
test('quien figura en el recibo se agrega aunque ya no reciba ahí', function (): void {
    expect(QuienRecibeElDinero::lista((int) $this->norte->getKey(), (int) $this->edwin->getKey()))
        ->toHaveKey((int) $this->edwin->getKey());
});

test('el campo viene marcado con quien teclea solo si recibe dinero en ese proyecto', function (): void {
    $this->actingAs($this->edwin);

    expect(QuienRecibeElDinero::porDefecto((int) $this->sur->getKey()))->toBe((int) $this->edwin->getKey())
        ->and(QuienRecibeElDinero::porDefecto((int) $this->norte->getKey()))->toBeNull();
});

// ─── La ficha del usuario ────────────────────────────────────────────

test('la ficha enciende el interruptor de quien ya recibe dinero', function (): void {
    Livewire::test(EditUser::class, ['record' => $this->edwin->getKey()])
        ->assertFormSet(['recibe_dinero' => true]);

    $nuevo = User::factory()->create(['is_active' => true]);

    Livewire::test(EditUser::class, ['record' => $nuevo->getKey()])
        ->assertFormSet(['recibe_dinero' => false]);
});

test('encender el interruptor y marcar un proyecto lo hace aparecer ahí', function (): void {
    $rosa = User::factory()->create(['name' => 'ROSA ELENA', 'is_active' => true]);

    Livewire::test(EditUser::class, ['record' => $rosa->getKey()])
        ->fillForm([
            'recibe_dinero'        => true,
            'proyectosDondeRecibe' => [(string) $this->norte->getKey()],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(QuienRecibeElDinero::lista((int) $this->norte->getKey()))->toHaveKey((int) $rosa->getKey())
        ->and(QuienRecibeElDinero::lista((int) $this->sur->getKey()))->not->toHaveKey((int) $rosa->getKey());
});

/*
| ⚠️ Es la mitad que se rompe en silencio: con el interruptor apagado la lista
| de proyectos se esconde, y Filament no guarda la relación de un campo
| escondido salvo que se lo pidan (`saveRelationshipsWhenHidden()`).
*/
test('apagar el interruptor lo saca de todos los proyectos', function (): void {
    Livewire::test(EditUser::class, ['record' => $this->elder->getKey()])
        ->fillForm(['recibe_dinero' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->elder->proyectosDondeRecibe()->count())->toBe(0)
        ->and(QuienRecibeElDinero::lista((int) $this->norte->getKey()))->toBe([]);
});

test('encendido sin ningún proyecto no se guarda', function (): void {
    $rosa = User::factory()->create(['is_active' => true]);

    Livewire::test(EditUser::class, ['record' => $rosa->getKey()])
        ->fillForm([
            'recibe_dinero'        => true,
            'proyectosDondeRecibe' => [],
        ])
        ->call('save')
        ->assertHasFormErrors(['proyectosDondeRecibe']);

    expect($rosa->proyectosDondeRecibe()->count())->toBe(0);
});
