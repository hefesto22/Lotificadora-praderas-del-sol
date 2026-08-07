<?php

declare(strict_types=1);
use App\Domain\ValueObjects\Monto;
use App\Models\User;
use BezhanSalleh\FilamentShield\Support\Utils as Shield;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Pest\Expectation;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
| Feature usa el TestCase que corre con RefreshDatabase + Laravel app boot.
| Unit usa el base (sin DB) — más rápido, ideal para Value Objects.
|
| OJO: `in('Feature')` ya cubre a `Feature/Filament`. Antes había un segundo
| `pest()->extend(TestCase::class)->in('Feature/Filament')` SIN
| RefreshDatabase, que sobrescribía al de arriba para ese subdirectorio y
| dejaba esos tests corriendo contra una base sucia.
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
| Custom expectations específicas del dominio Olympo.
*/

expect()->extend('toBeMonto', function (string $valor, string $moneda = 'HNL'): Expectation {
    /** @var Monto $monto */
    $monto = $this->value;

    expect($monto)->toBeInstanceOf(Monto::class);
    // Se compara el redondeado, no el exacto: el valor interno lleva
    // escala 12 y compararlo obligaria a escribir los ceros en cada test.
    expect($monto->redondeado())->toBe($valor);
    expect($monto->moneda)->toBe($moneda);

    return $this;
});

expect()->extend('toBeValidRTN', function (): Expectation {
    expect((string) $this->value)->toMatch('/^\d{14}$/');

    return $this;
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
*/

/**
 * Crea (o recupera) un rol del guard web y limpia la cache de permisos.
 *
 * Sin el forgetCachedPermissions, spatie/laravel-permission arrastra entre
 * tests un set de permisos que ya no corresponde a la base recreada.
 */
function rol(string $nombre): Role
{
    resolve(PermissionRegistrar::class)->forgetCachedPermissions();

    /** @var Role $role */
    $role = Role::query()->firstOrCreate(['name' => $nombre], ['guard_name' => 'web']);

    return $role;
}

/**
 * Replica los permisos que `shield:generate` crea para los Resources del
 * panel, sin pagar sus ~15 segundos en cada test.
 *
 * El formato sale de config/filament-shield.php: separator ':' + case
 * 'pascal', de donde salen nombres como "ViewAny:User". Las acciones son
 * las de `policies.methods` en esa misma config.
 *
 * OJO: `config/filament-shield.php` tiene `define_via_gate => false`, así
 * que el rol super_admin NO bypassea el Gate. Necesita los permisos
 * asignados de verdad, igual que en producción.
 */
function sembrarPermisosDeShield(): void
{
    $acciones = [
        'ViewAny', 'View', 'Create', 'Update', 'Delete',
        'Restore', 'RestoreAny', 'ForceDelete', 'ForceDeleteAny',
        'Replicate', 'Reorder',
    ];

    /*
     * La lista NO se copia: sale del RoleSeeder, que es la matriz de verdad
     * del §9.E7. Cuando estaban duplicadas, agregar PlanDePago alla y no aca
     * dejo al super_admin sin el permiso en los tests — y el boton "Nuevo
     * plan" invisible, con la pantalla funcionando perfecto.
     *
     * User, Role y Activity se suman aparte: son del panel, no del negocio,
     * y por eso el seeder no los reparte.
     */
    $recursos = [...RoleSeeder::RECURSOS, 'User', 'Role', 'Activity'];

    foreach ($recursos as $recurso) {
        foreach ($acciones as $accion) {
            Permission::query()->firstOrCreate(['name' => "{$accion}:{$recurso}"], ['guard_name' => 'web']);
        }
    }

    /*
     * Los permisos que NO salen del cruce acciones x recursos, uno por uno
     * (§9.E3). Van aparte a proposito: meter 'Reprogramar' en $acciones
     * inventaria un `Reprogramar:Cliente` y un `Reprogramar:Lote` que no
     * existen en ninguna politica, y meter 'Reprogramacion' en $recursos le
     * daria al super_admin un `Delete:Reprogramacion` que la politica niega.
     *
     * `Reprogramar:Venta` es el del abono a capital (R21) y los dos de
     * `Reprogramacion` son la pestaña que explica por que el plan cambio.
     */
    foreach ([
        'Reprogramar:Venta',
        'ViewAny:Reprogramacion',
        'View:Reprogramacion',
        // R14: prorrogar y marcar la devolucion de la seña de un apartado.
        'Prorrogar:Compromiso',
        'DevolverSenia:Compromiso',
    ] as $permiso) {
        Permission::query()->firstOrCreate(['name' => $permiso], ['guard_name' => 'web']);
    }
}

/**
 * Usuario activo con el rol indicado.
 *
 * @param array<string, mixed> $atributos
 */
function crearUsuarioConRol(string $nombreRol, array $atributos = []): User
{
    $rolCreado = rol($nombreRol);

    /** @var User $user */
    $user = User::factory()->create(array_merge(['is_active' => true], $atributos));
    $user->assignRole($rolCreado);

    return $user;
}

/**
 * Super-admin autenticado, con el set completo de permisos de Shield.
 *
 * @param array<string, mixed> $atributos
 */
function actingAsAdmin(array $atributos = []): User
{
    $rolAdmin = rol(Shield::getSuperAdminName());

    sembrarPermisosDeShield();
    $rolAdmin->syncPermissions(Permission::all());

    /** @var User $user */
    $user = User::factory()->create(array_merge(['is_active' => true], $atributos));
    $user->assignRole($rolAdmin);

    resolve(PermissionRegistrar::class)->forgetCachedPermissions();

    test()->actingAs($user);

    return $user;
}

/**
 * Usuario con rol panel_user: entra al panel pero NO tiene permisos de
 * Resource. Es el rol con el que se prueban las restricciones — el §5 del
 * documento rector exige probar todo módulo con un rol NO admin.
 *
 * @param array<string, mixed> $atributos
 */
function actingAsPanelUser(array $atributos = []): User
{
    $user = crearUsuarioConRol(Shield::getPanelUserRoleName(), $atributos);

    test()->actingAs($user);

    return $user;
}
