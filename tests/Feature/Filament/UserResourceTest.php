<?php

declare(strict_types=1);

use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

/*
| CRUD de Usuarios sobre Filament v5 + Livewire 4.
|
| El test de guardado es el que importa: ejercita un formulario Livewire
| completo (mount, fill, validate, save). Es el punto donde el salto de
| Livewire 3 a 4 rompería si algo quedó mal.
*/

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('carga el listado de usuarios para el super admin', function (): void {
    actingAsAdmin();

    $this->get(UserResource::getUrl('index'))->assertOk();
});

it('el listado muestra los usuarios visibles', function (): void {
    $admin = actingAsAdmin();
    $otro = User::factory()->create();

    Livewire::test(ListUsers::class)
        ->assertCanSeeTableRecords([$admin, $otro]);
});

it('carga el formulario de edicion', function (): void {
    $admin = actingAsAdmin();

    $this->get(UserResource::getUrl('edit', ['record' => $admin]))->assertOk();
});

it('guarda una edicion del usuario', function (): void {
    actingAsAdmin();
    $objetivo = User::factory()->create(['name' => 'Nombre Viejo']);

    Livewire::test(EditUser::class, ['record' => $objetivo->getKey()])
        ->fillForm(['name' => 'Rosa Elena Espana'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($objetivo->refresh()->name)->toBe('Rosa Elena Espana');
});

it('un panel_user no puede ver el listado de usuarios', function (): void {
    actingAsPanelUser();

    $this->get(UserResource::getUrl('index'))->assertForbidden();
});

it('nadie puede eliminarse a si mismo', function (): void {
    $admin = actingAsAdmin();

    expect(UserResource::canDelete($admin))->toBeFalse();
});

it('un super admin no puede ser eliminado ni por otro super admin', function (): void {
    actingAsAdmin();
    $otroAdmin = crearUsuarioConRol('super_admin');

    expect(UserResource::canDelete($otroAdmin))->toBeFalse();
});
