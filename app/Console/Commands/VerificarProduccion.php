<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * ¿Este servidor está listo para que lo use un cliente?
 *
 * ═══ POR QUE EXISTE ═══
 *
 * La auditoría del 8-ago-2026 encontró una cadena de tres fallas que solas son
 * medias y juntas son graves: el respaldo no salía del servidor, el cron había
 * que instalarlo a mano y nadie avisaba si faltaba, y `MAIL_MAILER=log` hacía
 * que la alerta de «el respaldo falló» no llegara a nadie. Las tres juntas
 * significan que el sistema puede dejar de respaldar y llenarse el disco
 * **sin que nadie se entere hasta que sea tarde**.
 *
 * Lo peligroso de esas fallas no es que existan: es que se ven exactamente
 * igual que un servidor sano. Este comando las hace ruidosas.
 *
 * ═══ COMO SE USA ═══
 *
 *   php artisan olympo:verificar-produccion
 *
 * Se corre EN EL SERVIDOR, después de desplegar y antes de entregarle la
 * llave al cliente. Devuelve código distinto de cero si algo grave falta, así
 * que se puede encadenar: `... && echo "listo"`.
 *
 * En local va a fallar casi todo, y está bien: una máquina de desarrollo no
 * es un servidor de producción. **No se agrega a `composer ci`.**
 *
 * ═══ POR QUE `olympo:` Y NO `praderas:` ═══
 *
 * Esto es del producto, no del primer cliente. `praderas:exportar-todo` se
 * llama así por herencia y habrá que renombrarlo; lo nuevo ya no.
 */
#[Description('Revisa que el servidor esté listo para producción. Falla si algo puede romperse en silencio.')]
#[Signature('olympo:verificar-produccion {--estricto : Falla también con los avisos, no solo con lo grave}')]
final class VerificarProduccion extends Command
{
    /**
     * @var list<array{titulo: string, ok: bool, detalle: string, arreglo: string, grave: bool}>
     */
    private array $revisiones = [];

    public function handle(): int
    {
        $this->entorno();
        $this->cadenaDeAlertas();
        $this->respaldos();
        $this->credenciales();
        $this->operacion();

        return $this->informe();
    }

    // ─── Las revisiones ───────────────────────────────────────────────

    private function entorno(): void
    {
        $entorno = (string) config('app.env');

        $this->revisar(
            'El entorno dice production',
            $entorno === 'production',
            "APP_ENV={$entorno}",
            'APP_ENV=production. Varias protecciones del propio sistema —el freno del seeder de '
                .'admin, entre otras— solo se activan cuando el entorno dice production exactamente.',
        );

        $depuracion = config('app.debug');

        $this->revisar(
            'El modo depuración está apagado',
            $depuracion === false,
            $depuracion === false ? 'APP_DEBUG=false' : 'APP_DEBUG está encendido',
            'APP_DEBUG=false. Con esto encendido, un error cualquiera le muestra a quien esté '
                .'mirando la consulta SQL completa, con los datos del cliente adentro.',
        );

        $llave = (string) config('app.key');

        $this->revisar(
            'Hay llave de aplicación',
            $llave !== '',
            $llave === '' ? 'APP_KEY vacía' : 'APP_KEY presente',
            'php artisan key:generate. Sin llave no hay sesiones ni cifrado.',
        );

        $url = (string) config('app.url');

        $this->revisar(
            'La dirección del sistema es https',
            str_starts_with($url, 'https://'),
            "APP_URL={$url}",
            'APP_URL con https y el certificado instalado. Sin TLS, la contraseña del receptor '
                .'viaja en claro por la red del residencial.',
        );

        $this->revisar(
            'La cookie de sesión viaja solo por https',
            config('session.secure') === true,
            config('session.secure') === true ? 'SESSION_SECURE_COOKIE=true' : 'Sin definir o en false',
            'SESSION_SECURE_COOKIE=true. Si no, la sesión de la administradora se puede robar '
                .'desde cualquier red abierta.',
        );
    }

    /**
     * La cadena de fallo silencioso. Es el motivo por el que existe el comando.
     */
    private function cadenaDeAlertas(): void
    {
        $correo = (string) config('mail.default');

        $this->revisar(
            'El correo sale de verdad',
            ! in_array($correo, ['log', 'array', 'null', ''], true),
            "MAIL_MAILER={$correo}",
            'Un SMTP real. Con «log», el aviso de que el respaldo falló se escribe en un archivo '
                .'que nadie abre nunca — que es exactamente igual a no avisar.',
        );

        $this->revisar(
            'Las alertas de salud están encendidas',
            config('health.notifications.enabled') === true,
            config('health.notifications.enabled') === true ? 'Encendidas' : 'Apagadas',
            'HEALTH_NOTIFICATIONS=true. Con esto apagado, /health puede estar en rojo por semanas '
                .'y nadie recibe nada.',
        );

        $destino = (string) config('health.notifications.mail.to');

        $this->revisar(
            'Las alertas van a un correo que alguien lee',
            $destino !== '' && $destino !== 'admin@grupoolympo.com',
            $destino === '' ? 'Sin destinatario' : $destino,
            'HEALTH_ALERT_EMAIL con un correo que se revise todos los días. El de la plantilla '
                .'no cuenta.',
            grave: false,
        );

        /*
         * Con try/catch porque el latido vive en la caché y la caché es
         * Redis: si Redis no está arriba, esto revienta. Y el momento en que
         * Redis no está arriba es exactamente el momento en que uno quiere
         * que este comando HABLE, no que tire un stack trace.
         */
        try {
            $latido = Cache::get('health:checks:schedule:latestHeartbeatAt');
            $legible = is_numeric($latido);
        } catch (Throwable $error) {
            $latido = null;
            $legible = false;
            $this->revisar(
                'La caché responde',
                false,
                mb_substr($error->getMessage(), 0, 120),
                'Redis arriba y con REDIS_PASSWORD correcto. Sin caché no hay sesiones ni latido.',
            );
        }

        $minutos = $legible ? (int) floor((time() - (int) $latido) / 60) : null;

        $this->revisar(
            'El cron del servidor está corriendo',
            $minutos !== null && $minutos <= 5,
            $minutos === null
                ? 'Nunca latió: schedule:run no corrió en este servidor'
                : "Último latido hace {$minutos} minuto(s)",
            'Agregar al crontab del servidor:'."\n".
            '        * * * * * cd '.base_path().' && php artisan schedule:run >> /dev/null 2>&1'."\n".
            '        Sin cron no hay respaldo, ni limpieza, ni monitoreo — y nada de eso avisa que falta.',
        );
    }

    private function respaldos(): void
    {
        $configurados = config('backup.backup.destination.disks');
        $discos = is_array($configurados)
            ? array_values(array_map(static fn (mixed $disco): string => (string) $disco, $configurados))
            : [];
        $afuera = array_values(array_filter($discos, static fn (string $disco): bool => $disco !== 'local'));

        $this->revisar(
            'El respaldo sale del servidor',
            $afuera !== [],
            $discos === [] ? 'Sin ningún destino' : 'Destinos: '.implode(', ', $discos),
            'BACKUP_DISKS=local,s3 con AWS_* configurado, o cualquier disco remoto. Si el respaldo '
                .'vive en el mismo disco que la base, el día que muera el disco se pierden los dos juntos.',
        );

        $clave = config('backup.backup.password');

        $this->revisar(
            'El respaldo va cifrado',
            is_string($clave) && $clave !== '',
            is_string($clave) && $clave !== '' ? 'Con contraseña' : 'Sin contraseña',
            'BACKUP_ARCHIVE_PASSWORD. Un zip sin clave es la base de datos completa legible por '
                .'cualquiera que tenga acceso al disco.',
            grave: false,
        );
    }

    private function credenciales(): void
    {
        $conexion = (string) config('database.default');
        $clave = config("database.connections.{$conexion}.password");

        $this->revisar(
            'La base de datos pide contraseña',
            is_string($clave) && $clave !== '',
            is_string($clave) && $clave !== '' ? 'Con contraseña' : 'Sin contraseña',
            'DB_PASSWORD. El VPS es compartido con otros proyectos: sin contraseña, el aislamiento '
                .'depende de que nadie se equivoque.',
        );

        $redis = config('database.redis.default.password');

        $this->revisar(
            'Redis pide contraseña',
            is_string($redis) && $redis !== '',
            is_string($redis) && $redis !== '' ? 'Con contraseña' : 'Sin contraseña',
            'REDIS_PASSWORD. Mismo motivo que la base: el servidor no es solo de este cliente.',
            grave: false,
        );

        // En una variable: son dos lecturas a la base y PHPStan tampoco
        // narrowea el resultado de un método llamado dos veces.
        $debil = $this->hayContrasenaDelEjemplo();

        $this->revisar(
            'Nadie quedó con la contraseña del ejemplo',
            $debil === false,
            match ($debil) {
                true    => 'Hay al menos un usuario con «12345678»',
                false   => 'Ninguno',
                default => 'No se pudo comprobar: la base no respondió',
            },
            'Cambiarla antes de entregar. Es la que trae .env.example y la que siembra el seeder '
                .'si nadie la tocó.',
        );
    }

    private function operacion(): void
    {
        $this->revisar(
            'La configuración está cacheada',
            app()->configurationIsCached(),
            app()->configurationIsCached() ? 'Sí' : 'No',
            'php artisan config:cache. Sin esto el servidor lee y parsea el .env en cada petición.',
            grave: false,
        );

        $this->revisar(
            'Las rutas están cacheadas',
            app()->routesAreCached(),
            app()->routesAreCached() ? 'Sí' : 'No',
            'php artisan route:cache',
            grave: false,
        );

        $enlace = public_path('storage');

        $this->revisar(
            'El enlace de storage existe',
            is_link($enlace) || is_dir($enlace),
            is_link($enlace) || is_dir($enlace) ? 'Sí' : 'No',
            'php artisan storage:link. Sin él, el logo del recibo no carga.',
            grave: false,
        );
    }

    // ─── Interno ──────────────────────────────────────────────────────

    /**
     * ¿Quedó alguien con la contraseña de la plantilla?
     *
     * Se comprueba contra el hash y no contra el `.env`: `env()` devuelve null
     * cuando la configuración está cacheada, que es justamente el estado en el
     * que va a estar un servidor de producción. Lo que importa además no es
     * qué dice el archivo, sino si alguien PUEDE entrar con esa clave.
     *
     * Devuelve `null` cuando no se pudo mirar —la base no respondió—, y eso
     * cuenta como fallo: un servidor cuya base no se puede leer no se
     * certifica «listo».
     */
    private function hayContrasenaDelEjemplo(): ?bool
    {
        try {
            if (! Schema::hasTable('users')) {
                return false;
            }

            foreach (User::query()->limit(50)->pluck('password') as $hash) {
                if (is_string($hash) && $hash !== '' && Hash::check('12345678', $hash)) {
                    return true;
                }
            }

            return false;
        } catch (Throwable) {
            return null;
        }
    }

    private function revisar(string $titulo, bool $ok, string $detalle, string $arreglo, bool $grave = true): void
    {
        $this->revisiones[] = [
            'titulo'  => $titulo,
            'ok'      => $ok,
            'detalle' => $detalle,
            'arreglo' => $arreglo,
            'grave'   => $grave,
        ];
    }

    private function informe(): int
    {
        $fallos = 0;
        $avisos = 0;

        $nombre = (string) config('app.name');

        $this->newLine();
        $this->components->info('Revisión del servidor — '.$nombre);

        foreach ($this->revisiones as $revision) {
            if ($revision['ok']) {
                $this->components->twoColumnDetail(
                    '  <fg=green>OK</>  '.$revision['titulo'],
                    "<fg=gray>{$revision['detalle']}</>",
                );

                continue;
            }

            if ($revision['grave']) {
                $fallos++;
                $this->components->twoColumnDetail(
                    '  <fg=red;options=bold>FALTA</>  '.$revision['titulo'],
                    "<fg=red>{$revision['detalle']}</>",
                );
            } else {
                $avisos++;
                $this->components->twoColumnDetail(
                    '  <fg=yellow>AVISO</>  '.$revision['titulo'],
                    "<fg=yellow>{$revision['detalle']}</>",
                );
            }

            $this->line('        → '.$revision['arreglo']);
        }

        $this->newLine();

        if ($fallos > 0) {
            $this->components->error(
                $fallos === 1
                    ? 'Hay 1 cosa que puede romperse sin que nadie se entere. No lo entregues así.'
                    : "Hay {$fallos} cosas que pueden romperse sin que nadie se entere. No lo entregues así."
            );

            return self::FAILURE;
        }

        if ($avisos > 0) {
            $mensaje = "Lo grave está cubierto. Quedan {$avisos} aviso(s) para anotar.";

            if ($this->option('estricto') === true) {
                $this->components->error($mensaje.' Se pidió --estricto.');

                return self::FAILURE;
            }

            $this->components->warn($mensaje);

            return self::SUCCESS;
        }

        $this->components->info('El servidor está listo para entregarlo.');

        return self::SUCCESS;
    }
}
