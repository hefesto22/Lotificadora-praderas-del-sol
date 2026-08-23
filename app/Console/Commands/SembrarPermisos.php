<?php

declare(strict_types=1);

namespace App\Console\Commands;

use BezhanSalleh\FilamentShield\Support\Utils as Shield;
use Database\Seeders\RoleSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Deja los permisos de la base al día con los del código — 23-ago-2026.
 *
 * ═══ 🔴 POR QUE EXISTE: UNA FUNCION SE ENTREGA Y NO APARECE ═══
 *
 * Un permiso con nombre propio —`CambiarTitular:Venta`, `ProntoPago:Venta`—
 * nace en `RoleSeeder`, que **`composer ci` corre solo sobre la base de
 * TESTS**. En la base real no existe hasta que alguien lo siembre, y como
 * `config/filament-shield.php` tiene `define_via_gate => false`, ni siquiera
 * el super-admin lo tiene: `can('ProntoPago:Venta')` devuelve false para
 * todos.
 *
 * Y una acción de Filament sin permiso **no falla: no se dibuja**. La función
 * se entrega, los 1,100 tests pasan, y en la pantalla no hay nada.
 *
 * Pasó dos veces seguidas. El 22-ago se entregó la cesión de derechos (R23) y
 * el 23 se descubrió, abriendo el navegador, que el botón «Cambiar titular»
 * llevaba un día entero invisible en la máquina de Mauricio. El mismo día le
 * pasó lo mismo a «Pronto pago». Los dos estaban perfectos en el código.
 *
 * ═══ POR QUE NO ALCANZABA CON LO QUE YA HABIA ═══
 *
 * El camino existente es `db:seed --class=RoleSeeder` y después
 * `AdminUserSeeder`, que es el que sincroniza el set completo al super-admin.
 * Pero `AdminUserSeeder` hace `User::updateOrCreate(...)` con la contraseña
 * de `.env`: **correrlo para arreglar un permiso le cambia la contraseña al
 * administrador**. Nadie va a correr eso dos veces por semana, y con razón.
 *
 * Esto hace las dos mitades que hacen falta y ninguna más: no toca usuarios,
 * no toca contraseñas, no toca la marca.
 *
 * ═══ COMO SE USA ═══
 *
 *   php artisan olympo:sembrar-permisos
 *
 * Cada vez que una entrega agrega un permiso nombrado, y en el servidor
 * después de desplegar. Es idempotente: correrlo de más no hace nada.
 */
#[Description('Siembra los permisos nuevos y se los sincroniza al super-admin. No toca usuarios ni contraseñas.')]
#[Signature('olympo:sembrar-permisos {--ensayo : Dice qué falta y no escribe nada}')]
final class SembrarPermisos extends Command
{
    public function handle(): int
    {
        $antes = $this->permisosQueHay();

        if ($this->option('ensayo')) {
            return $this->soloMirar($antes);
        }

        resolve(RoleSeeder::class)->run();

        /*
         * La otra mitad, y la que se olvidaba: con `define_via_gate => false`
         * el super-admin NO hereda nada por ser super-admin — necesita el
         * permiso asignado como cualquiera. Es exactamente lo que hace
         * `shield:super-admin`, pero sin arrastrar el resto de su seeder.
         */
        $rol = Role::query()->firstOrCreate(
            ['name' => Shield::getSuperAdminName()],
            ['guard_name' => 'web'],
        );

        $rol->syncPermissions(Permission::all());

        resolve(PermissionRegistrar::class)->forgetCachedPermissions();

        $nuevos = array_values(array_diff($this->permisosQueHay(), $antes));

        if ($nuevos === []) {
            $this->components->info('No había ningún permiso nuevo. El super-admin quedó sincronizado igual.');

            return self::SUCCESS;
        }

        $this->components->info(sprintf('%d permiso(s) nuevo(s):', count($nuevos)));
        $this->components->bulletList($nuevos);

        /*
         * ⚠️ El aviso importa tanto como el trabajo: `spatie/laravel-permission`
         * guarda los permisos en caché y quien ya tenía la sesión abierta puede
         * seguir sin ver el botón hasta recargar.
         */
        $this->components->warn('Si el panel estaba abierto, recargá la página: los permisos se cachean por sesión.');

        return self::SUCCESS;
    }

    /**
     * Los nombres de todos los permisos que hay en la base.
     *
     * ⚠️ EL `@var` NO ES DECORATIVO. `pluck()->all()` devuelve `array` a
     * secas: la forma `list<string>` se pierde ahí y el análisis rebota en
     * cuanto ese valor cruza un parámetro que sí la declara. Es el mismo
     * molde que `numeric-string` cruzando un `string` — **el tipo se repone
     * en el ORIGEN, no en el destino**. Por eso esto es un método y no una
     * línea repetida dos veces: la reposición vive en un solo lugar.
     *
     * @return list<string>
     */
    private function permisosQueHay(): array
    {
        /** @var list<string> $nombres */
        $nombres = Permission::query()->orderBy('name')->pluck('name')->all();

        return $nombres;
    }

    /**
     * Qué falta, sin escribir. Sirve para revisar un servidor sin tocarlo.
     *
     * @param list<string> $antes
     */
    private function soloMirar(array $antes): int
    {
        $delRol = Role::query()
            ->where('name', Shield::getSuperAdminName())
            ->first()
            ?->permissions
            ->pluck('name')
            ->all() ?? [];

        $sinAsignar = array_values(array_diff($antes, $delRol));

        if ($sinAsignar === []) {
            $this->components->info('El super-admin tiene todos los permisos que hay en la base.');
        } else {
            $this->components->warn(sprintf('%d permiso(s) que el super-admin NO tiene:', count($sinAsignar)));
            $this->components->bulletList($sinAsignar);
        }

        /*
         * Lo que este modo NO puede contestar: si al CODIGO le falta sembrar
         * un permiso que todavía no existe en la base. Eso solo se sabe
         * corriendo el seeder, y por eso el ensayo no reemplaza a la corrida.
         */
        $this->components->info('Ensayo: no se escribió nada. Corré el comando sin `--ensayo` para sembrar.');

        return self::SUCCESS;
    }
}
