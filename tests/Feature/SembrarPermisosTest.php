<?php

declare(strict_types=1);

use App\Support\Roles;
use BezhanSalleh\FilamentShield\Support\Utils as Shield;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/*
|--------------------------------------------------------------------------
| Sembrar los permisos — 23-ago-2026
|--------------------------------------------------------------------------
| 🔴 DE DONDE SALIO: dos funciones entregadas y verdes que en la pantalla no
| existían. La cesión de derechos (22-ago) y el pronto pago (23-ago) llevaban
| horas invisibles en la máquina de Mauricio, porque el permiso nombrado vive
| en `RoleSeeder` y `composer ci` solo lo corre sobre la base de TESTS.
|
| Con `define_via_gate => false`, ni el super-admin hereda nada por serlo. Y
| una acción de Filament sin permiso NO FALLA: no se dibuja.
|
| Lo que estos tests cuidan es que el comando haga las DOS mitades. Sembrar
| sin sincronizarle al super-admin deja el permiso en la base y el botón
| igual de invisible — que es exactamente el bug, con un paso más.
*/

test('siembra los permisos que faltaban', function (): void {
    expect(Permission::query()->where('name', 'ProntoPago:Venta')->exists())->toBeFalse();

    $this->artisan('olympo:sembrar-permisos')->assertSuccessful();

    expect(Permission::query()->where('name', 'ProntoPago:Venta')->exists())->toBeTrue()
        ->and(Permission::query()->where('name', 'CambiarTitular:Venta')->exists())->toBeTrue();
});

/*
| 🔴 LA SEGUNDA MITAD, que es la que se olvidaba.
*/
test('y se los sincroniza al super-admin', function (): void {
    $this->artisan('olympo:sembrar-permisos')->assertSuccessful();

    $superAdmin = Role::query()->where('name', Shield::getSuperAdminName())->firstOrFail();

    expect($superAdmin->hasPermissionTo('ProntoPago:Venta'))->toBeTrue()
        ->and($superAdmin->hasPermissionTo('CambiarTitular:Venta'))->toBeTrue();
});

test('la administradora sí; el receptor no', function (): void {
    $this->artisan('olympo:sembrar-permisos')->assertSuccessful();

    expect(Role::query()->where('name', Roles::ADMINISTRADORA)->firstOrFail()->hasPermissionTo('ProntoPago:Venta'))
        ->toBeTrue()
        ->and(Role::query()->where('name', Roles::RECEPTOR)->firstOrFail()->hasPermissionTo('ProntoPago:Venta'))
        ->toBeFalse();
});

test('correrlo dos veces no rompe nada', function (): void {
    $this->artisan('olympo:sembrar-permisos')->assertSuccessful();

    $cuantos = Permission::query()->count();

    $this->artisan('olympo:sembrar-permisos')->assertSuccessful();

    expect(Permission::query()->count())->toBe($cuantos);
});

/*
| El ensayo mira y no escribe. Es para revisar un servidor sin tocarlo.
*/
test('el ensayo no siembra nada', function (): void {
    $this->artisan('olympo:sembrar-permisos', ['--ensayo' => true])->assertSuccessful();

    expect(Permission::query()->where('name', 'ProntoPago:Venta')->exists())->toBeFalse();
});
