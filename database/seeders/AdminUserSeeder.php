<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use BezhanSalleh\FilamentShield\Support\Utils;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Crea/actualiza el super-admin inicial del sistema y deja Shield
 * listo (genera permisos para todos los Resources, asigna super_admin).
 *
 * Las credenciales se leen de variables de entorno (§15.1) con
 * defaults clásicos memorables para desarrollo:
 *   ADMIN_EMAIL    (default: admin@gmail.com)
 *   ADMIN_PASSWORD (default en local: 12345678 — NUNCA en producción)
 *   ADMIN_NAME     (default: Administrador)
 *
 * En producción FALLA si ADMIN_PASSWORD está vacío. Esto fuerza al
 * deployer a setear una contraseña real antes del primer seed.
 */
class AdminUserSeeder extends Seeder
{
    /**
     * Credenciales por defecto SOLO para desarrollo local.
     * Memorables para acelerar el setup al clonar la plantilla.
     */
    private const DEFAULT_EMAIL = 'admin@gmail.com';

    private const DEFAULT_PASSWORD = '12345678';

    private const DEFAULT_NAME = 'Administrador';

    public function run(): void
    {
        $email = (string) env('ADMIN_EMAIL', self::DEFAULT_EMAIL);
        $password = (string) env('ADMIN_PASSWORD', '');
        $nombre = (string) env('ADMIN_NAME', self::DEFAULT_NAME);

        if ($password === '') {
            if (app()->environment('production')) {
                throw new RuntimeException(
                    'ADMIN_PASSWORD no está definido. Define las credenciales del super-admin en .env antes de ejecutar este seeder en producción.'
                );
            }

            $password = self::DEFAULT_PASSWORD;
            $this->command?->warn("⚠️  ADMIN_PASSWORD vacío. Usando default de desarrollo: {$password}");
            $this->command?->warn('   Define ADMIN_PASSWORD en tu .env antes de deployar a producción.');
        }

        $admin = User::updateOrCreate(
            ['email' => $email],
            [
                'name'              => $nombre,
                'password'          => Hash::make($password),
                'is_active'         => true,
                'email_verified_at' => now(),
            ]
        );

        // Asegura que el rol super_admin exista (lo crea RoleSeeder, pero
        // por si este seeder corre solo, hacemos un fallback defensivo).
        $admin->assignRole(Utils::getSuperAdminName());

        $this->command?->info("✓ Super-admin listo: {$email}");

        // ─── Filament Shield: generar permisos para todos los Resources ─────
        // Esto genera permisos como view_any_user, create_user, update_user,
        // etc. para CADA Resource detectado en app/Filament/Resources.
        // Sin esto, los Resources NO aparecen en el sidebar (Shield los oculta).
        $this->command?->info('Generando permisos de Shield para todos los Resources…');

        Artisan::call('shield:generate', [
            '--all'            => true,
            '--option'         => 'permissions',
            '--panel'          => 'admin',
            '--no-interaction' => true,
        ]);

        // ─── Sincroniza TODOS los permisos al rol super_admin ───────────────
        // shield:super-admin asigna el rol y sincroniza el set completo de
        // permisos generados, garantizando que el super-admin vea TODO.
        Artisan::call('shield:super-admin', [
            '--user' => $admin->id,
        ]);

        $this->command?->info('✓ Shield configurado. Super-admin tiene todos los permisos.');
    }
}
