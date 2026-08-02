<?php

declare(strict_types=1);

namespace Database\Seeders;

use BezhanSalleh\FilamentShield\Support\Utils;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Crea los roles base del panel. NO crea permisos.
 *
 * Los permisos los genera `shield:generate` desde AdminUserSeeder, que
 * corre justo después, leyendo los Resources y Pages reales del panel.
 *
 * Antes este seeder creaba a mano ~20 permisos con la convención vieja de
 * Shield (`view_any_user`, `create_role`, `page_MyProfilePage`), pero las
 * policies de este proyecto chequean la convención configurada en
 * config/filament-shield.php — separator ':' y case 'pascal' — o sea
 * `ViewAny:User`, `Create:Role`. Eran dos vocabularios distintos: ningún
 * permiso de los que sembraba este archivo lo leía nadie, y dos de ellos
 * apuntaban a páginas (MyProfilePage, ActivityLogPage) que ni siquiera
 * existen en app/Filament/Pages.
 *
 * Se dejaban 20 filas muertas en la tabla `permissions`. El día que
 * alguien creara el rol receptor y le asignara `view_any_venta` siguiendo
 * ese ejemplo, el permiso no habría hecho absolutamente nada y el bug
 * habría costado medio día encontrarlo.
 *
 * Regla: los permisos SIEMPRE los genera Shield. Este seeder solo define
 * qué roles existen.
 */
class RoleSeeder extends Seeder
{
    public function run(): void
    {
        resolve(PermissionRegistrar::class)->forgetCachedPermissions();

        // Acceso total. shield:super-admin le sincroniza todos los
        // permisos generados, desde AdminUserSeeder.
        Role::query()->firstOrCreate(['name' => Utils::getSuperAdminName()], ['guard_name' => 'web']);

        // Acceso al panel sin permisos de Resource. Es la base sobre la
        // que se construirán los roles del negocio (receptor,
        // administradora) cuando existan sus Resources y Shield haya
        // generado sus permisos.
        Role::query()->firstOrCreate(['name' => Utils::getPanelUserRoleName()], ['guard_name' => 'web']);

        $this->command?->info('✓ Roles base listos: '.Utils::getSuperAdminName().', '.Utils::getPanelUserRoleName());
    }
}
