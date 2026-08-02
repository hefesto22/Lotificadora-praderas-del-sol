<?php

declare(strict_types=1);

use App\Models\User;
use BezhanSalleh\FilamentShield\Support\Utils as Shield;
use Filament\Facades\Filament;

/*
| Puerta de entrada al panel: User::canAccessPanel() exige que el usuario
| esté activo Y tenga rol super_admin o panel_user.
|
| Estos tests son el reemplazo automatizado de la verificación manual del
| §5 del DoD: cubren lo que Livewire 4 y serializable_classes podrían
| romper dentro de una sesión autenticada, que es donde los tests de
| dominio no llegan.
*/

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('deja entrar al panel a un super admin activo', function (): void {
    actingAsAdmin();

    $this->get('/')->assertOk();
});

it('deja entrar al panel a un usuario con rol panel_user', function (): void {
    actingAsPanelUser();

    $this->get('/')->assertOk();
});

it('bloquea el panel a un usuario sin ningun rol', function (): void {
    $user = User::factory()->create(['is_active' => true]);

    $this->actingAs($user)->get('/')->assertForbidden();
});

it('bloquea el panel a un super admin desactivado', function (): void {
    $user = crearUsuarioConRol(Shield::getSuperAdminName(), ['is_active' => false]);

    $this->actingAs($user)->get('/')->assertForbidden();
});

it('redirige al login a un visitante anonimo', function (): void {
    $this->get('/')->assertRedirect();
});
