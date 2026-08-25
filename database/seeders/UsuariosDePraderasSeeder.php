<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\Support\Roles;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * El equipo REAL de Praderas del Sol, para la instalación de producción.
 *
 *     CLAVE_INICIAL=loquesea php artisan db:seed --class=UsuariosDePraderasSeeder --force
 *
 * Pedido por Mauricio el 24-ago-2026, con producción ya arriba: «hagamos los
 * usuarios reales un seeder y subámoslos».
 *
 * ═══ EN QUÉ SE DIFERENCIA DE `UsuariosDePruebaSeeder` ═══
 *
 * Aquel es de mentira y **se planta en producción a propósito**. Este es el de
 * verdad y sí corre ahí, así que tiene dos cuidados que el otro no necesita:
 *
 * 1. **No le pisa la contraseña a quien ya existe.** Correrlo dos veces no le
 *    devuelve a Rosa Elena la clave del primer día después de que ella la
 *    cambió. Solo se escribe al CREAR; a los que ya están se les corrige el
 *    nombre y el rol, nada más.
 * 2. **Avisa cuál quedó con contraseña nueva y cuál no**, en la tabla del
 *    final. Sin eso, quien corre el comando no sabe qué decirle a cada uno.
 *
 * ═══ 🔴 LA CONTRASEÑA NO ESTÁ EN ESTE ARCHIVO, Y NO ES UN CAPRICHO ═══
 *
 * **El repositorio es PÚBLICO.** Una contraseña escrita acá queda publicada en
 * GitHub para siempre —el historial de git no se olvida— y estas cuentas abren
 * un panel que está en internet con la cartera de la contratante adentro:
 * nombres, DNI, teléfonos y saldos de 114 expedientes.
 *
 * Sale de `CLAVE_INICIAL`. Si no se la dan, genera una al azar y la imprime UNA
 * vez. Es la misma regla que ya seguía el seeder de pruebas.
 *
 * ⚠️ `olympo:verificar-produccion` revisa que nadie haya quedado con
 * «12345678» y **se niega a dar el servidor por bueno** si lo encuentra. No es
 * una opinión de nadie: es la puerta, y hay que pasarla antes de entregar.
 *
 * ═══ LOS CORREOS SON EL NOMBRE DE USUARIO ═══
 *
 * Con el correo entran al sistema, y el día que haya SMTP real es a donde va a
 * llegar «recuperar contraseña». Por eso tienen que ser los de VERDAD: si acá
 * queda `rosa@gmail.com` y esa cuenta es de otra persona, el día que alguien
 * pida recuperar su clave el enlace le llega a un desconocido.
 */
final class UsuariosDePraderasSeeder extends Seeder
{
    /**
     * ✏️ ACÁ SE EDITA EL EQUIPO. Es lo único que hay que tocar.
     *
     * Los roles salen de `RoleSeeder`, que es la matriz de verdad (§9.E7):
     *
     *  - `Roles::ADMINISTRADORA` — opera todo el negocio: vende, reprograma,
     *    da descuentos, ve la bitácora. NO administra usuarios ni roles.
     *  - `Roles::RECEPTOR` — cobra y registra el cobro; del resto solo mira.
     *    No firma ventas, no anula recibos, no ve gastos.
     *
     * @return list<array{nombre: string, email: string, rol: string}>
     */
    private function elEquipo(): array
    {
        return [
            ['nombre' => 'Rosa Elena', 'email' => 'rosa@gmail.com',  'rol' => Roles::ADMINISTRADORA],
            ['nombre' => 'Elder',      'email' => 'elder@gmail.com', 'rol' => Roles::RECEPTOR],
            ['nombre' => 'Edwin',      'email' => 'edwin@gmail.com', 'rol' => Roles::RECEPTOR],
        ];
    }

    public function run(): void
    {
        /*
         * Los roles primero. `RoleSeeder` es idempotente, así que llamarlo de
         * más no cuesta nada — y sin él, `syncRoles()` reventaría con un rol
         * que todavía no existe en la base.
         */
        $this->call(RoleSeeder::class);

        $clave = trim((string) (getenv('CLAVE_INICIAL') ?: ''));
        $generada = $clave === '';

        if ($generada) {
            // Sin símbolos: esta clave se teclea a mano en un login, y de
            // memoria, mientras alguien la dicta por teléfono.
            $clave = Str::random(12);
        }

        $filas = [];
        $nuevos = 0;

        foreach ($this->elEquipo() as $persona) {
            $usuario = User::query()->firstWhere('email', $persona['email']);
            $esNuevo = ! $usuario instanceof User;

            if ($esNuevo) {
                $usuario = new User;
                $usuario->setAttribute('email', $persona['email']);

                // 🔴 En limpio: `users.password` tiene el cast `hashed` y lo
                // hashea al asignarlo. Pasarlo por `Hash::make()` de nuevo
                // dependería de que el costo coincida para no hashear dos
                // veces — y si un día no coincide, nadie puede entrar.
                $usuario->setAttribute('password', $clave);
                $usuario->setAttribute('email_verified_at', now());

                $nuevos++;
            }

            $usuario->setAttribute('name', $persona['nombre']);
            $usuario->setAttribute('is_active', true);
            $usuario->save();

            /*
             * `syncRoles` y no `assignRole`: si alguien le movió el rol a mano
             * desde la pantalla, esto lo devuelve a lo que dice el contrato en
             * vez de sumarle otro encima.
             */
            $usuario->syncRoles([$persona['rol']]);

            $filas[] = [
                $persona['nombre'],
                $persona['email'],
                $persona['rol'],
                $esNuevo ? 'creado, con la contraseña nueva' : 'ya existía — NO se le tocó la contraseña',
            ];
        }

        $this->command?->newLine();
        $this->command?->table(['Nombre', 'Correo', 'Rol', 'Qué pasó'], $filas);

        if ($nuevos === 0) {
            $this->command?->info('✓ Los tres ya estaban. Solo se corrigieron nombre y rol.');

            return;
        }

        if ($generada) {
            $this->command?->warn("La contraseña de los {$nuevos} usuarios nuevos es:  {$clave}");
            $this->command?->line('Copiala AHORA: se generó al azar y no queda escrita en ningún lado.');
            $this->command?->line('Para elegirla vos:  CLAVE_INICIAL=loquesea php artisan db:seed --class=UsuariosDePraderasSeeder --force');

            return;
        }

        $this->command?->info("✓ {$nuevos} usuario(s) nuevo(s) con la contraseña de CLAVE_INICIAL.");
        $this->avisarSiEsDebil($clave);
    }

    /**
     * Si la clave elegida es de las que prueba cualquiera, se dice acá y no
     * dentro de dos semanas.
     *
     * La lista es corta a propósito: no es un validador de contraseñas, es el
     * puñado que un escáner tira primero contra un panel que encuentra abierto.
     * `olympo:verificar-produccion` revisa la primera de todas.
     */
    private function avisarSiEsDebil(string $clave): void
    {
        $comunes = ['12345678', '123456789', '1234567890', 'password', 'admin123', 'praderas'];

        if (! in_array(mb_strtolower($clave), $comunes, true)) {
            return;
        }

        $this->command?->newLine();
        $this->command?->error("⚠️  «{$clave}» es de las primeras que prueba cualquiera.");
        $this->command?->line('   Estas cuentas abren un panel público con la cartera de la contratante.');
        $this->command?->line('   `olympo:verificar-produccion` va a marcarlo en rojo hasta que se cambie.');
    }
}
