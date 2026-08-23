<?php

declare(strict_types=1);

use App\Domain\Enums\EstadoCompromiso;
use App\Domain\Enums\EstadoVenta;
use App\Domain\Enums\TipoCompromiso;
use App\Domain\Exceptions\VentaInvalidaException;
use App\Domain\Ventas\CambioDeTitular;
use App\Domain\Ventas\EstadoDeCuenta;
use App\Models\Bloque;
use App\Models\Cliente;
use App\Models\Compromiso;
use App\Models\Lote;
use App\Models\Recibo;
use App\Models\User;
use App\Models\Venta;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

/*
| Cesión de derechos: el expediente pasa a otra persona (22-ago-2026).
|
| Mauricio lo pidió así: «el registro de los pagos queda y solo se cambia el
| nombre del cliente». Toda la utilidad de este Service depende de que eso
| sea literalmente cierto, así que la mitad de estos tests no prueban lo que
| el cambio HACE — prueban lo que NO toca.
*/

/**
 * Un expediente vigente con su titular y una copropietaria.
 *
 * ⚠️ El numero de expediente sube en cada llamada: `vigente()` arma el
 * numero de contrato con el, y dos expedientes con el mismo numero chocan
 * contra el unique de `ventas`. Un test que arma dos revienta sin esto.
 *
 * @return array{Venta, Cliente, Cliente}
 */
function expedienteConDosDuenos(): array
{
    static $expediente = 0;
    $expediente++;

    $titular = Cliente::factory()->create(['nombre' => 'JUAN PEREZ']);
    $socia = Cliente::factory()->create(['nombre' => 'MARIA LOPEZ']);

    $venta = Venta::factory()->vigente($expediente)->create();
    $venta->clientes()->attach([
        (int) $titular->getKey() => ['titular' => true, 'orden' => 1],
        (int) $socia->getKey()   => ['titular' => false, 'orden' => 2],
    ]);

    return [$venta, $titular, $socia];
}

function cederExpediente(Venta $venta, Cliente $nuevo, ?string $motivo = null): ?Cliente
{
    return app(CambioDeTitular::class)->cambiar($venta, $nuevo, $motivo);
}

/**
 * Un lote del MISMO proyecto de la venta.
 *
 * `compromisos_lote_del_mismo_proyecto_fk` ata el compromiso al proyecto de
 * su lote: un lote de otro proyecto no entra.
 */
function loteDelProyectoDe(Venta $venta, string $manzana): Lote
{
    $bloque = Bloque::factory()->create([
        'proyecto_id' => $venta->getAttribute('proyecto_id'),
        'nombre'      => $manzana,
    ]);

    return Lote::factory()->enBloque($bloque)->conMedidas('250.0000', '1400.00')->create();
}

describe('CambioDeTitular', function (): void {
    test('la titularidad pasa, y el que sale queda listado con la fecha', function (): void {
        [$venta, $juan] = expedienteConDosDuenos();
        $nueva = Cliente::factory()->create(['nombre' => 'ROSA ELENA MEJIA']);

        $anterior = cederExpediente($venta, $nueva);

        expect($anterior?->getKey())->toBe($juan->getKey())
            ->and($venta->refresh()->titular()?->getKey())->toBe($nueva->getKey());

        // No se le borró la fila: sigue siendo dueño listado del expediente.
        $anteriores = $venta->titularesAnteriores();

        $hasta = $anteriores->first()?->getAttribute('pivot')?->getAttribute('titular_hasta');

        /*
        | `instanceof CarbonInterface` y no `not->toBeNull()`: sin el pivot
        | propio con casts esto llega como STRING, todo `instanceof` da false
        | y la fecha nunca se imprime —en silencio—. Ver DuenoDelExpediente.
        */
        expect($anteriores)->toHaveCount(1)
            ->and($anteriores->first()?->getKey())->toBe($juan->getKey())
            ->and($hasta)->toBeInstanceOf(CarbonInterface::class)
            ->and($hasta?->isToday())->toBeTrue();
    });

    test('🔴 los recibos ya emitidos NO cambian de dueño', function (): void {
        [$venta, $juan] = expedienteConDosDuenos();

        $recibo = Recibo::factory()->create([
            'venta_id'   => $venta->getKey(),
            'cliente_id' => $juan->getKey(),
            'monto'      => '25000.00',
        ]);

        cederExpediente($venta, Cliente::factory()->create(['nombre' => 'ROSA ELENA MEJIA']));

        /*
        | El corazón del asunto. Ese recibo dice quién puso la plata ESE día,
        | y eso ya pasó. Si un día empieza a apuntar al titular nuevo, la
        | caja de aquel día deja de cuadrar contra los depósitos.
        */
        expect($recibo->refresh()->getAttribute('cliente_id'))->toBe($juan->getKey())
            ->and($recibo->nombreDelPapel())->toBe('JUAN PEREZ');
    });

    test('la copropietaria que ya estaba pasa a titular sin duplicarse', function (): void {
        [$venta, $juan, $maria] = expedienteConDosDuenos();

        cederExpediente($venta, $maria);

        // Es el caso más común de todos: del marido a la esposa que ya
        // firmaba al lado. No entra nadie nuevo al expediente.
        expect($venta->refresh()->clientes)->toHaveCount(2)
            ->and($venta->titular()?->getKey())->toBe($maria->getKey())
            ->and($venta->titularesAnteriores()->pluck('id')->all())->toBe([$juan->getKey()]);
    });

    test('nunca quedan dos titulares', function (): void {
        [$venta] = expedienteConDosDuenos();

        cederExpediente($venta, Cliente::factory()->create());
        cederExpediente($venta->refresh(), Cliente::factory()->create());

        /*
        | `venta_cliente_un_titular_uq` es un índice único PARCIAL y Postgres
        | lo valida fila por fila: prender la marca nueva antes de apagar la
        | vieja revienta con un 23505. Este test es el que se pone rojo si
        | alguien invierte ese orden en el Service.
        */
        $marcados = $venta->refresh()->clientes
            ->filter(static fn (Cliente $c): bool => $c->getAttribute('pivot')?->getAttribute('titular') === true);

        expect($marcados)->toHaveCount(1);
    });

    test('quien vuelve a ser titular deja de figurar como anterior', function (): void {
        [$venta, $juan] = expedienteConDosDuenos();
        $otra = Cliente::factory()->create(['nombre' => 'ROSA ELENA MEJIA']);

        cederExpediente($venta, $otra);
        cederExpediente($venta->refresh(), $juan);

        expect($venta->refresh()->titular()?->getKey())->toBe($juan->getKey())
            ->and($venta->titularesAnteriores()->pluck('id')->all())->toBe([$otra->getKey()]);
    });

    test('queda el asiento en la bitácora con el usuario y la fecha', function (): void {
        [$venta] = expedienteConDosDuenos();

        $usuario = User::factory()->create();
        test()->actingAs($usuario);

        cederExpediente($venta, Cliente::factory()->create(['nombre' => 'ROSA ELENA MEJIA']), 'CESION FIRMADA');

        /** @var Activity $asiento */
        $asiento = Activity::query()
            ->where('subject_type', $venta->getMorphClass())
            ->where('subject_id', $venta->getKey())
            ->where('event', 'titular')
            ->latest('id')
            ->firstOrFail();

        /*
        | Sin este asiento el cambio sería invisible: `LogsActivity` de Venta
        | mira columnas de la tabla `ventas`, y el titular vive en el pivot.
        |
        | 🔴 El diff va en `attribute_changes`, que es lo que lee la pantalla
        | de Registros de actividad. En `properties` se vería «Sin datos
        | anteriores / Sin datos nuevos»: guardado donde nadie lo pinta.
        */
        /** @var Collection<string, mixed> $cambios */
        $cambios = $asiento->getAttribute('attribute_changes');
        /** @var Collection<string, mixed> $propiedades */
        $propiedades = $asiento->getAttribute('properties');

        expect($asiento->getAttribute('causer_id'))->toBe($usuario->getKey())
            ->and($cambios->get('old'))->toBe(['titular' => 'JUAN PEREZ'])
            ->and($cambios->get('attributes'))->toBe(['titular' => 'ROSA ELENA MEJIA'])
            ->and($propiedades->get('motivo'))->toBe('CESION FIRMADA')
            ->and($asiento->getAttribute('created_at'))->not->toBeNull();
    });

    test('no deja elegir a quien ya es titular', function (): void {
        [$venta, $juan] = expedienteConDosDuenos();

        expect(fn (): ?Cliente => cederExpediente($venta, $juan))
            ->toThrow(VentaInvalidaException::class, 'ya es el titular');
    });

    test('un expediente rescindido o anulado no cambia de titular', function (): void {
        foreach ([EstadoVenta::Rescindida, EstadoVenta::Anulada] as $estado) {
            [$venta] = expedienteConDosDuenos();
            // El CHECK `ventas_cierre_segun_estado_chk` exige la fecha.
            $venta->forceFill(['estado' => $estado, 'cerrada_el' => today()])->saveQuietly();

            expect(fn (): ?Cliente => cederExpediente($venta->refresh(), Cliente::factory()->create()))
                ->toThrow(VentaInvalidaException::class, 'no hay titularidad que ceder');
        }
    });

    test('un expediente liquidado SI puede ceder: pagó todo y aún no escritura', function (): void {
        [$venta] = expedienteConDosDuenos();
        $venta->forceFill(['estado' => EstadoVenta::Liquidada, 'cerrada_el' => today()])->saveQuietly();

        $nueva = Cliente::factory()->create(['nombre' => 'ROSA ELENA MEJIA']);

        cederExpediente($venta->refresh(), $nueva);

        expect($venta->refresh()->titular()?->getKey())->toBe($nueva->getKey());
    });

    test('los lotes vivos pasan al titular nuevo; los rescindidos no', function (): void {
        [$venta, $juan] = expedienteConDosDuenos();

        // El CHECK `compromisos_venta_solo_en_tipo_venta_chk` exige el tipo;
        // `cerrado()` pone la fecha que pide el CHECK de cierre.
        $vivo = Compromiso::factory()
            ->paraLote(loteDelProyectoDe($venta, 'A'))
            ->deTipo(TipoCompromiso::Venta)
            ->create(['venta_id' => $venta->getKey(), 'cliente_id' => $juan->getKey()]);

        $caido = Compromiso::factory()
            ->paraLote(loteDelProyectoDe($venta, 'B'))
            ->deTipo(TipoCompromiso::Venta)
            ->cerrado(EstadoCompromiso::Rescindido)
            ->create(['venta_id' => $venta->getKey(), 'cliente_id' => $juan->getKey()]);

        $nueva = Cliente::factory()->create(['nombre' => 'ROSA ELENA MEJIA']);

        cederExpediente($venta, $nueva);

        /*
        | `compromisos.cliente_id` es lo que rotula el plano. Si no se movía,
        | el mapa —la pantalla donde más se pregunta «¿de quién es este
        | lote?»— seguía diciendo el nombre viejo para siempre.
        |
        | El rescindido NO se toca: ese lote fue de quien lo tuvo.
        */
        expect($vivo->refresh()->getAttribute('cliente_id'))->toBe($nueva->getKey())
            ->and($caido->refresh()->getAttribute('cliente_id'))->toBe($juan->getKey());
    });

    test('el ex titular deja de figurar como copropietario', function (): void {
        [$venta, $juan] = expedienteConDosDuenos();

        cederExpediente($venta, Cliente::factory()->create(['nombre' => 'ROSA ELENA MEJIA']));

        /*
        | Su fila sigue ahí a propósito, así que cualquiera que recorra
        | `$venta->clientes` lo agarra. El estado de cuenta IMPRESO lo
        | listaba bajo «Copropietarios»: el papel diciendo algo que la
        | pantalla no dice.
        */
        $cuenta = EstadoDeCuenta::de($venta->refresh());

        expect(array_map(static fn (Cliente $c): int => (int) $c->getKey(), $cuenta->copropietarios))
            ->not->toContain($juan->getKey());
    });

    test('no toca el dinero del expediente', function (): void {
        [$venta] = expedienteConDosDuenos();

        $antes = $venta->only(['valor_total', 'prima', 'saldo_financiar', 'cuota_mensual', 'plazo_meses']);

        cederExpediente($venta, Cliente::factory()->create());

        // Lo que se cede es el contrato tal como está: quien entra recibe la
        // deuda que hay, no una nueva.
        expect($venta->refresh()->only(array_keys($antes)))->toBe($antes);
    });
});
