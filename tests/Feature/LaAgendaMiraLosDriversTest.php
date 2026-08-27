<?php

declare(strict_types=1);

use App\Providers\HealthServiceProvider;
use App\Support\Infraestructura;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Checks\RedisCheck;
use Spatie\Health\Facades\Health;

/*
|--------------------------------------------------------------------------
| La agenda mira los drivers antes de agendarse — 27-ago-2026
|--------------------------------------------------------------------------
| 🔴 Praderas del Sol corre A PROPÓSITO sin Redis y sin Horizon: caché en
| archivo, sesión en archivo, cola en base de datos. Pero `health:check`
| (cada minuto) y `horizon:snapshot` (cada cinco) estaban agendados igual, y
| los dos reventaban siempre con `Class "Redis" not found`.
|
| Lo caro no fue el CPU: fue que escribieron **8 MB de log en un día**, y el
| 26-ago un 500 de verdad —el cliente sin poder entrar al sistema— quedó
| sepultado ahí adentro. Un `tail -80` solo alcanzaba para ver dos minutos de
| basura.
|
| Estos tests fijan la regla: **un aviso que grita siempre es un aviso que
| nadie mira.**
*/

test('la instalación de pruebas no usa Redis ni Horizon', function (): void {
    // Es la premisa de todo lo demás: si esto cambiara, los otros tests
    // estarían midiendo otra cosa sin decirlo.
    expect(Infraestructura::usaRedis())->toBeFalse()
        ->and(Infraestructura::laColaVaPorRedis())->toBeFalse()
        ->and(Infraestructura::usaHorizon())->toBeFalse();
});

test('sin Redis, el check de Redis no se registra', function (): void {
    expect(Health::registeredChecks()->contains(
        static fn (Check $check): bool => $check instanceof RedisCheck,
    ))->toBeFalse();
});

test('con la cola en Redis, el check vuelve solo', function (): void {
    config()->set('queue.default', 'redis');

    expect(Infraestructura::usaRedis())->toBeTrue()
        ->and(Infraestructura::usaHorizon())->toBeTrue();

    /*
     * ⚠️ `Health::checks()` ACUMULA — hace `array_merge`, no reemplaza. Sin
     * este `clearChecks()`, bootear el provider por segunda vez registra los
     * mismos nueve checks otra vez y el paquete tira `DuplicateCheckNamesFound`.
     *
     * Lo dice el CUERPO del método en el vendor; su firma —`checks(array)`—
     * sugiere lo contrario. Es la Regla 1-septies, y me la volvió a cobrar.
     */
    Health::clearChecks();

    new HealthServiceProvider(app())->boot();

    expect(Health::registeredChecks()->contains(
        static fn (Check $check): bool => $check instanceof RedisCheck,
    ))->toBeTrue();
});

test('sin Horizon, `horizon:snapshot` no está agendado — y el resto de la agenda sí', function (): void {
    $agendados = collect(app(Schedule::class)->events())
        ->map(static fn (Event $evento): string => (string) $evento->description)
        ->all();

    // Lo que se apagó...
    expect($agendados)->not->toContain('horizon-snapshot')
        // ...y lo que NO: apagar el ruido no puede apagar la agenda.
        ->and($agendados)->toContain('health-check')
        ->and($agendados)->toContain('backup-cleanup');
});
