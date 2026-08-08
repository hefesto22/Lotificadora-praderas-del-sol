<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Cache;

/*
|--------------------------------------------------------------------------
| La puerta del servidor
|--------------------------------------------------------------------------
| `olympo:verificar-produccion` existe para atrapar lo que falla EN SILENCIO:
| un cron que nunca se instaló, un respaldo que no sale del disco, un correo
| en `log` que se traga todas las alertas. Un comando así no sirve de nada si
| devuelve verde por defecto, así que cada test rompe UNA cosa y comprueba
| que el comando se planta.
|
| El `beforeEach` arma un servidor sano. Si algún día un test empieza a fallar
| sin que nadie tocara el comando, es que se agregó una revisión nueva y hay
| que darle su valor bueno acá — a propósito: obliga a mirarla.
*/

beforeEach(function (): void {
    config([
        'app.env'                             => 'production',
        'app.debug'                           => false,
        'app.key'                             => 'base64:'.base64_encode(str_repeat('x', 32)),
        'app.url'                             => 'https://praderasdelsol.hn',
        'session.secure'                      => true,
        'mail.default'                        => 'smtp',
        'health.notifications.enabled'        => true,
        'health.notifications.mail.to'        => 'rosa@praderasdelsol.hn',
        'backup.backup.destination.disks'     => ['local', 's3'],
        'backup.backup.password'              => 'una-contrasena-larga',
        'database.connections.pgsql.password' => 'secreta',
        'database.redis.default.password'     => 'secreta',
    ]);

    // El cron acaba de latir.
    Cache::put('health:checks:schedule:latestHeartbeatAt', time());
});

test('un servidor bien configurado pasa la revisión', function (): void {
    $this->artisan('olympo:verificar-produccion')->assertExitCode(0);
});

/*
| Con debug encendido, un error cualquiera le muestra a quien esté mirando la
| consulta SQL completa, con los datos del cliente adentro.
*/
test('no deja pasar el modo depuración encendido', function (): void {
    config(['app.debug' => true]);

    $this->artisan('olympo:verificar-produccion')->assertExitCode(1);
});

/*
| El hallazgo que originó todo esto: un respaldo que vive en el mismo disco
| que la base no es un respaldo. El día que muera el disco se pierden juntos.
*/
test('no deja pasar un respaldo que se queda en el servidor', function (): void {
    config(['backup.backup.destination.disks' => ['local']]);

    $this->artisan('olympo:verificar-produccion')->assertExitCode(1);
});

/*
| Con `MAIL_MAILER=log` el sistema funciona igual y TODAS las alertas se
| pierden: el aviso de que el respaldo falló se escribe en un archivo que
| nadie abre.
*/
test('no deja pasar el correo en log', function (): void {
    config(['mail.default' => 'log']);

    $this->artisan('olympo:verificar-produccion')->assertExitCode(1);
});

/*
| Sin cron no hay respaldo, ni limpieza, ni monitoreo. Y hasta el 8-ago-2026
| el latido se escribía y nadie lo miraba.
*/
test('se da cuenta de que el cron nunca corrió', function (): void {
    Cache::forget('health:checks:schedule:latestHeartbeatAt');

    $this->artisan('olympo:verificar-produccion')->assertExitCode(1);
});

test('se da cuenta de que el cron se murió hace rato', function (): void {
    Cache::put('health:checks:schedule:latestHeartbeatAt', time() - 3600);

    $this->artisan('olympo:verificar-produccion')->assertExitCode(1);
});

/*
| Se comprueba contra el HASH y no contra el .env: con la configuración
| cacheada —que es como va a estar un servidor real— `env()` devuelve null.
| Y lo que importa no es qué dice el archivo sino si alguien PUEDE entrar.
*/
test('detecta a quien quedó con la contraseña del ejemplo', function (): void {
    User::factory()->create(['password' => '12345678']);

    $this->artisan('olympo:verificar-produccion')->assertExitCode(1);
});

test('un usuario con otra contraseña no levanta la alarma', function (): void {
    User::factory()->create(['password' => 'una-contrasena-que-nadie-adivina']);

    $this->artisan('olympo:verificar-produccion')->assertExitCode(0);
});

/*
| Los avisos no frenan una entrega —config sin cachear no rompe nada— pero
| con `--estricto` sí, para poder encadenarlo en un script de despliegue.
*/
test('con --estricto los avisos también frenan', function (): void {
    $this->artisan('olympo:verificar-produccion --estricto')->assertExitCode(1);
});
