<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\Support\Roles;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * El equipo de Praderas, para poder probar el sistema con sus roles.
 *
 * Pedido por Mauricio el 23-ago-2026: «un seeder para la administradora y
 * dos para los receptores». Hasta ahora el ambiente de pruebas solo tenía al
 * super-admin, que **ve todo** — y con un usuario que ve todo no se puede
 * comprobar lo único que importa de los permisos: qué NO ve cada quien.
 *
 *     php artisan db:seed --class=UsuariosDePruebaSeeder
 *
 * ═══ QUIÉNES SON ═══
 *
 * Los del contrato (§13.1 y el reparto de `RoleSeeder`):
 *
 *  - **Rosa Elena**, administradora: opera todo el negocio. No administra
 *    usuarios ni roles —eso es del super-admin— pero sí ve la bitácora.
 *  - **Elder** y **Edwin**, receptores: cobran y registran el cobro; del
 *    resto solo miran. No firman ventas, no anulan recibos, no ven gastos.
 *
 * Son dos receptores a propósito y no uno: el arqueo de caja es POR
 * receptor, y con uno solo no se puede ver que Elder no mira lo de Edwin.
 *
 * ═══ 🔴 LA CONTRASEÑA NO ESTÁ EN ESTE ARCHIVO ═══
 *
 * El repositorio es PÚBLICO. Una contraseña escrita acá queda publicada en
 * GitHub para siempre, y el historial de git no se olvida.
 *
 * Sale de `CLAVE_PRUEBAS`, y si no está se **genera una al azar y se imprime
 * una sola vez** al terminar. Copiala de ahí:
 *
 *     CLAVE_PRUEBAS=loquesea php artisan db:seed --class=UsuariosDePruebaSeeder
 *
 * ═══ Y NO CORRE EN PRODUCCIÓN ═══
 *
 * Tres usuarios con una contraseña compartida son exactamente lo que no debe
 * existir en la instalación real. El seeder se planta solo.
 */
final class UsuariosDePruebaSeeder extends Seeder
{
    /**
     * @return list<array{nombre: string, email: string, rol: string}>
     */
    private function elEquipo(): array
    {
        return [
            ['nombre' => 'Rosa Elena', 'email' => 'rosa@pruebas.test',  'rol' => Roles::ADMINISTRADORA],
            ['nombre' => 'Elder',      'email' => 'elder@pruebas.test', 'rol' => Roles::RECEPTOR],
            ['nombre' => 'Edwin',      'email' => 'edwin@pruebas.test', 'rol' => Roles::RECEPTOR],
        ];
    }

    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException(
                'UsuariosDePruebaSeeder no corre en producción: son usuarios de prueba '.
                'con una contraseña compartida. Los usuarios reales se crean desde la pantalla.',
            );
        }

        /*
         * Los roles primero. `RoleSeeder` es la matriz de verdad (§9.E7) y es
         * idempotente, así que llamarlo de más no cuesta nada — y sin él,
         * `syncRoles()` reventaría con un rol que no existe.
         */
        $this->call(RoleSeeder::class);

        $clave = (string) (getenv('CLAVE_PRUEBAS') ?: '');
        $generada = $clave === '';

        if ($generada) {
            // Sin símbolos: esta clave se teclea a mano en un login.
            $clave = Str::random(14);
        }

        $filas = [];

        foreach ($this->elEquipo() as $persona) {
            $usuario = User::query()->updateOrCreate(
                ['email' => $persona['email']],
                [
                    'name'              => $persona['nombre'],
                    'password'          => Hash::make($clave),
                    'is_active'         => true,
                    'email_verified_at' => now(),
                ],
            );

            /*
             * `syncRoles` y no `assignRole`: si el seeder se corre dos veces
             * y alguien le movió el rol a mano desde la pantalla, esto lo
             * devuelve a lo que dice el contrato en vez de sumarle otro.
             */
            $usuario->syncRoles([$persona['rol']]);

            $filas[] = [$persona['nombre'], $persona['email'], $persona['rol']];
        }

        $this->command?->newLine();
        $this->command?->table(['Nombre', 'Correo', 'Rol'], $filas);

        if ($generada) {
            $this->command?->warn("La contraseña de los tres es:  {$clave}");
            $this->command?->line('Copiala AHORA: se generó al azar y no queda escrita en ningún lado.');
            $this->command?->line('Para elegirla vos:  CLAVE_PRUEBAS=loquesea php artisan db:seed --class=UsuariosDePruebaSeeder');

            return;
        }

        $this->command?->info('✓ Los tres entran con la contraseña de CLAVE_PRUEBAS.');
    }
}
