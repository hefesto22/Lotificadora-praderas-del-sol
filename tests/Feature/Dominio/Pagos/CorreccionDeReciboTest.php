<?php

declare(strict_types=1);

use App\Domain\Enums\FormaDePago;
use App\Domain\Exceptions\PagoInvalidoException;
use App\Domain\Pagos\CorreccionDeRecibo;
use App\Domain\Pagos\RegistroDePagos;
use App\Domain\ValueObjects\Monto;
use App\Domain\Ventas\RegistroDeVentas;
use App\Models\Bloque;
use App\Models\Cliente;
use App\Models\Lote;
use App\Models\Proyecto;
use App\Models\Recibo;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

/*
|--------------------------------------------------------------------------
| Corregir un recibo sin tocar el dinero — 4-sep-2026
|--------------------------------------------------------------------------
| Nació de un caso de producción: el RPS-00000022 salió sin «recibido_por»
| —era una PRIMA, y esa puerta no preguntaba hasta el 31-ago— y el corte de
| caja lo sumaba bajo «Sin usuario». Se arregló por SSH, a mano.
|
| Lo que estos tests cuidan NO es que la corrección funcione: es que siga
| siendo CHICA. El día que alguien agregue `monto` a `CorreccionDeRecibo::
| CAMPOS` «porque total ya está el modal», el segundo test se pone rojo.
|
| Un lote de 250 vr² a L 1,400.00 son L 350,000.00; con L 50,000.00 de prima
| quedan cuotas de L 25,000.00 a 12 meses.
*/

beforeEach(function (): void {
    actingAsAdmin();

    $proyecto = Proyecto::factory()->create(['codigo' => 'RPS']);
    $bloque = Bloque::factory()->create(['proyecto_id' => $proyecto->getKey(), 'nombre' => 'A']);
    $lote = Lote::factory()->enBloque($bloque)->conMedidas('250.0000', '1400.00')->create(['numero' => '1']);

    $this->cliente = Cliente::factory()->create(['nombre' => 'LETICIA ROMERO']);

    $this->venta = app(RegistroDeVentas::class)->activar(
        proyecto: $proyecto,
        lotes: [$lote],
        clientes: [$this->cliente],
        prima: new Monto('50000.00'),
        plazoMeses: 12,
        diaPago: 5,
    );

    $this->recibo = app(RegistroDePagos::class)->cobrarCuotas(
        venta: $this->venta,
        lote: $this->venta->compromisos()->firstOrFail(),
        cliente: $this->cliente,
        monto: new Monto('25000.00'),
        forma: FormaDePago::Efectivo,
    );

    $this->elder = User::factory()->create(['name' => 'ELDER MEJIA', 'is_active' => true]);

    $this->corregir = fn (array $datos, string $motivo = 'Se tecleó mal'): bool => app(CorreccionDeRecibo::class)
        ->corregir($this->recibo, $datos, $motivo);
});

test('cambia a nombre de quién quedó el cobro', function (): void {
    $cambio = ($this->corregir)(
        ['recibido_por' => $this->elder->getKey(), 'forma_pago' => FormaDePago::Efectivo->value],
        'El dinero lo recibió don Elder en la caseta',
    );

    expect($cambio)->toBeTrue()
        ->and($this->recibo->fresh()?->getAttribute('recibido_por'))->toBe($this->elder->getKey());
});

/*
| 🔴 EL TEST QUE JUSTIFICA QUE ESTA PUERTA EXISTA.
|
| Todo lo que no está en `CorreccionDeRecibo::CAMPOS` tiene que sobrevivir
| intacto aunque el formulario lo mande. Si el monto se pudiera cambiar acá,
| el papel que el cliente tiene en la mano diría una cosa y la base otra, y
| el plan de pagos quedaría descuadrado: es el desastre del 27-ago que
| `olympo:cuadrar-recibos` nació para encontrar.
*/
test('el monto, el concepto, la fecha y el número son intocables', function (): void {
    $antes = $this->recibo->only(['numero', 'monto', 'concepto', 'fecha', 'cliente_id', 'venta_id']);

    ($this->corregir)([
        'recibido_por' => $this->elder->getKey(),
        'forma_pago'   => FormaDePago::Efectivo->value,
        // Todo esto viene en el arreglo y NADA de esto debe entrar.
        'monto'      => '999999.00',
        'concepto'   => 'prima',
        'fecha'      => '2020-01-01',
        'numero'     => 1,
        'cliente_id' => 99,
        'venta_id'   => 99,
    ]);

    expect($this->recibo->fresh()?->only(['numero', 'monto', 'concepto', 'fecha', 'cliente_id', 'venta_id']))
        ->toEqual($antes);
});

test('teclea la referencia que el día del cobro todavía no se tenía', function (): void {
    ($this->corregir)([
        'recibido_por' => $this->elder->getKey(),
        'forma_pago'   => FormaDePago::Transferencia->value,
        'referencia'   => 'FT26243CJP4P',
    ]);

    $vivo = $this->recibo->fresh();

    expect($vivo?->getAttribute('forma_pago'))->toBe(FormaDePago::Transferencia)
        ->and($vivo?->getAttribute('referencia'))->toBe('FT26243CJP4P');
});

/*
| No es un capricho del formulario: el Service es el que manda. Un recibo
| corregido sin motivo es un papel que dejó de coincidir con la base sin que
| nadie tenga que explicarlo.
*/
test('sin motivo no se corrige', function (): void {
    expect(fn (): bool => ($this->corregir)(['recibido_por' => $this->elder->getKey()], '   '))
        ->toThrow(PagoInvalidoException::class);
});

test('un recibo anulado ya no se corrige', function (): void {
    app(RegistroDePagos::class)->anular($this->recibo, 'Se cobró de más');

    expect(fn (): bool => ($this->corregir)(['recibido_por' => $this->elder->getKey()]))
        ->toThrow(PagoInvalidoException::class);
});

test('elegir a alguien que ya no existe no revienta con un error de Postgres', function (): void {
    expect(fn (): bool => ($this->corregir)(['recibido_por' => 999999]))
        ->toThrow(PagoInvalidoException::class);
});

/*
| Guardar sin cambiar nada es lo que pasa cuando alguien abre el modal, mira
| y cierra con Enter. Un asiento por eso ensucia la bitácora justo donde hay
| que buscar el cambio de verdad.
*/
test('si no cambió nada no ensucia la bitácora', function (): void {
    $antes = Activity::query()->count();

    $cambio = ($this->corregir)([
        'recibido_por' => $this->recibo->getAttribute('recibido_por'),
        'forma_pago'   => FormaDePago::Efectivo->value,
    ]);

    expect($cambio)->toBeFalse()
        ->and(Activity::query()->count())->toBe($antes);
});

/*
| UN asiento, no dos. `Recibo` se registra solo con `LogsActivity`, así que
| sin el `disableLogging()` del Service quedarían dos filas para un mismo
| cambio: la automática con nombres de columna y sin el porqué, y la de acá.
| Dos asientos para un cambio son peor que ninguno.
*/
test('deja un solo asiento, con el antes, el después y el motivo', function (): void {
    $antes = Activity::query()->count();

    ($this->corregir)(
        ['recibido_por' => $this->elder->getKey(), 'forma_pago' => FormaDePago::Efectivo->value],
        'El dinero lo recibió don Elder en la caseta',
    );

    expect(Activity::query()->count())->toBe($antes + 1);

    $asiento = Activity::query()->where('event', 'correccion')->latest('id')->firstOrFail();

    expect($asiento->getAttribute('subject_id'))->toBe($this->recibo->getKey())
        ->and($asiento->getAttribute('subject_type'))->toBe(Recibo::class)
        ->and($asiento->properties->get('motivo'))->toBe('El dinero lo recibió don Elder en la caseta')
        // 🔴 En `attribute_changes` y no en `properties`: es lo que pinta la
        // pantalla de Registros de actividad.
        ->and($asiento->attribute_changes?->get('attributes'))
        ->toBe(['quién recibió el dinero' => 'ELDER MEJIA'])
        ->and($asiento->getAttribute('causer_id'))->not->toBeNull();
});
