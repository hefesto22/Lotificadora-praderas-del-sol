<?php

declare(strict_types=1);

use App\Domain\Enums\FormaDePago;
use App\Domain\Enums\ModalidadDeReprogramacion;
use App\Domain\Exceptions\CorreccionDeValorInvalidaException;
use App\Domain\Pagos\RegistroDePagos;
use App\Domain\ValueObjects\Monto;
use App\Domain\Ventas\CorreccionDeValor;
use App\Domain\Ventas\PrecioPactado;
use App\Domain\Ventas\RegistroDeVentas;
use App\Models\AplicacionDePago;
use App\Models\Bloque;
use App\Models\Cliente;
use App\Models\Compromiso;
use App\Models\Cuota;
use App\Models\Lote;
use App\Models\Proyecto;
use App\Models\Recibo;
use App\Models\Reprogramacion;
use App\Models\Venta;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

/*
|--------------------------------------------------------------------------
| `olympo:corregir-valor` — los L 8.00 que el cliente no veía (20-sep-2026)
|--------------------------------------------------------------------------
| Salió del expediente 0028 de Praderas: tres lotes de 337.50 vr² a
| L 325,000.00 cada uno —L 975,000.00— con L 34,000.00 de prima y una cuota de
| L 19,604.00 a 48 meses. La cuota no cierra: 48 × 19,604 = 940,992, y lo
| financiado son 941,000. Al cargar la cartera se «respetó la cuota» bajándole
| el valor al contrato a L 974,992.00, y el cliente, con su papel en la mano,
| preguntó por los ocho lempiras.
|
| 🔴 ESTE TEST EXISTE PORQUE EL COMANDO ESCRIBE EN PRODUCCION, sobre un
| expediente con recibos emitidos. El camino que toca la cartera real no puede
| estrenarse ahí: se estrena acá, con los mismos números.
|
| El escenario es el de producción: los tres lotes cargados en 324,997.33 /
| .33 / .34, una cuota cobrada por lote, y un abono a capital de L 5,000.00 a
| cada uno —que deja una constancia con su plan viejo guardado—.
*/

beforeEach(function (): void {
    actingAsAdmin();

    $proyecto = Proyecto::factory()->create(['codigo' => 'RPS']);
    $bloque = Bloque::factory()->create(['proyecto_id' => $proyecto->getKey(), 'nombre' => 'H']);

    // Los precios que derivó la carga: 324,997.33 y 324,997.34 entre 337.50.
    $lotes = [
        Lote::factory()->enBloque($bloque)->conMedidas('337.5000', '962.955052')->create(['numero' => '9']),
        Lote::factory()->enBloque($bloque)->conMedidas('337.5000', '962.955052')->create(['numero' => '15']),
        Lote::factory()->enBloque($bloque)->conMedidas('337.5000', '962.955081')->create(['numero' => '16']),
    ];

    $this->cliente = Cliente::factory()->create(['nombre' => 'HUMBERTO ZELAYA PORTILLO']);

    $this->venta = app(RegistroDeVentas::class)->activar(
        proyecto: $proyecto,
        lotes: $lotes,
        clientes: [$this->cliente],
        prima: new Monto('34000.00'),
        plazoMeses: 48,
        diaPago: 20,
        precios: array_map(
            static fn (Lote $lote): PrecioPactado => new PrecioPactado(
                loteId: (int) $lote->getKey(),
                precioVara: new Monto((string) $lote->getAttribute('precio_vara')),
            ),
            $lotes,
        ),
    );

    /** @var list<Compromiso> $compromisos */
    $compromisos = $this->venta->compromisos()->with('lote')->orderBy('id')->get()->all();
    $this->lotes = $compromisos;

    $this->codigo = static fn (Compromiso $lote): string => (string) $lote->lote?->getAttribute('codigo');

    $pagos = app(RegistroDePagos::class);

    // Una cuota de cada lote: L 19,604.01 en un solo papel.
    $this->cobro = $pagos->cobrarVariosLotes(
        venta: $this->venta,
        cliente: $this->cliente,
        renglones: array_map(
            static fn (Compromiso $lote): array => ['lote' => $lote, 'monto' => new Monto('6534.67')],
            $compromisos,
        ),
        forma: FormaDePago::Efectivo,
    )[0];

    // Y un abono a capital de L 15,000.00, cinco mil a cada uno.
    $this->abono = $pagos->abonarAVariosLotes(
        venta: $this->venta,
        cliente: $this->cliente,
        renglones: array_map(
            static fn (Compromiso $lote): array => [
                'lote'      => $lote,
                'monto'     => new Monto('5000.00'),
                'modalidad' => ModalidadDeReprogramacion::AcortarPlazo,
            ],
            $compromisos,
        ),
        motivo: 'Abono a capital solicitado por el cliente',
        forma: FormaDePago::Efectivo,
    )[0];

    $this->corregir = fn (array $extra = []): array => array_merge([
        'venta'  => $this->venta->getKey(),
        '--lote' => array_map(
            fn (Compromiso $lote): string => ($this->codigo)($lote).':325000.00',
            $compromisos,
        ),
        '--motivo' => 'El contrato dice L 325,000.00 por lote; se había cargado respetando la cuota',
    ], $extra);

    /*
    | La foto de todo lo que la corrección puede tocar, para comparar antes y
    | después sin enumerar columnas en cada test.
    */
    $this->foto = fn (): array => [
        'venta'       => Venta::query()->whereKey($this->venta->getKey())->firstOrFail()->only(['valor_total', 'saldo_financiar', 'cuota_mensual', 'plazo_meses']),
        'compromisos' => Compromiso::query()->where('venta_id', $this->venta->getKey())->orderBy('id')->get(['id', 'valor', 'precio_vara', 'precio_vara_lista'])->toArray(),
        'cuotas'      => Cuota::query()->where('venta_id', $this->venta->getKey())->orderBy('compromiso_id')->orderBy('numero')->get(['compromiso_id', 'numero', 'monto', 'monto_capital', 'monto_pagado'])->toArray(),
        'constancias' => Reprogramacion::query()->where('venta_id', $this->venta->getKey())->orderBy('id')->get(['id', 'abono_capital', 'saldo_anterior', 'saldo_nuevo', 'plan_anterior'])->toArray(),
        'lotes'       => Lote::query()->whereIn('id', array_map(static fn (Compromiso $c): int => (int) $c->getAttribute('lote_id'), $compromisos))->orderBy('id')->get(['id', 'precio_vara', 'valor'])->toArray(),
    ];

    $this->ultimaDe = static fn (Compromiso $lote): Cuota => Cuota::query()
        ->where('compromiso_id', $lote->getKey())
        ->orderByDesc('numero')
        ->firstOrFail();
});

test('el escenario arranca con los ocho lempiras de menos', function (): void {
    expect($this->venta->montoValorTotal())->toBeMonto('974992.00')
        ->and($this->venta->montoSaldoFinanciar())->toBeMonto('940992.00')
        // 940,992.00 − 19,604.01 de cuotas − 15,000.00 de abono.
        ->and($this->venta->saldoPendiente())->toBeMonto('906387.99');
});

test('la corrección deja el contrato en lo que dice el papel', function (): void {
    $this->artisan('olympo:corregir-valor', ($this->corregir)())->assertSuccessful();

    $venta = $this->venta->fresh();

    expect($venta?->montoValorTotal())->toBeMonto('975000.00')
        ->and($venta?->montoSaldoFinanciar())->toBeMonto('941000.00')
        // Exactamente L 8.00 más que antes, ni un centavo de diferencia.
        ->and($venta?->saldoPendiente())->toBeMonto('906395.99');

    foreach ($this->lotes as $lote) {
        $fresco = $lote->fresh();

        expect($fresco?->montoValor())->toBeMonto('325000.00')
            // 325,000.00 ÷ 337.50, a seis decimales: lo que exige el CHECK
            // `compromisos_valor_es_area_por_precio_chk`.
            ->and($fresco?->getAttribute('precio_vara'))->toBe('962.962963')
            // No hubo descuento, y no tiene que aparecer uno por corregir.
            ->and($fresco?->getAttribute('precio_vara_lista'))->toBe('962.962963');
    }
});

/*
| 🔴 LO QUE EL CLIENTE PAGA CADA MES NO CAMBIA. El residuo va a la última
| cuota (R1), que es lo que el sistema hace desde siempre en una venta nueva.
*/
test('la cuota mensual no cambia: la diferencia entra en la última', function (): void {
    $antes = ($this->foto)()['cuotas'];

    $this->artisan('olympo:corregir-valor', ($this->corregir)())->assertSuccessful();

    $despues = ($this->foto)()['cuotas'];
    $cambiaron = [];

    foreach ($despues as $indice => $cuota) {
        if ($cuota !== $antes[$indice]) {
            $cambiaron[] = $cuota['numero'];
        }
    }

    // Una sola cuota por lote, y es la 48.
    expect($cambiaron)->toBe([48, 48, 48])
        ->and($this->venta->fresh()?->montoCuotaMensual())->toBeMonto('19604.01');

    // 1,534.51 era la cola después del abono; ahora lleva su parte de los ocho.
    $colas = array_map(
        fn (Compromiso $lote): string => ($this->ultimaDe)($lote)->montoTotal()->redondeado(),
        $this->lotes,
    );

    sort($colas);

    expect($colas)->toBe(['1537.17', '1537.18', '1537.18']);
});

/*
| 🔴 LO QUE NO SE TOCA. Los papeles que el cliente tiene en la mano dicen
| cuánto entregó, y eso era verdad antes y sigue siéndolo.
*/
test('ningún recibo se toca, y todos siguen cuadrando', function (): void {
    $campos = ['numero', 'monto', 'fecha', 'concepto', 'recibido_por', 'anulado_el'];

    $retratar = fn (): array => Recibo::query()
        ->where('venta_id', $this->venta->getKey())
        ->orderBy('id')
        ->get()
        ->map(static fn (Recibo $recibo): array => $recibo->only($campos))
        ->all();

    $recibos = $retratar();
    $aplicado = AplicacionDePago::query()->sum('monto');

    $this->artisan('olympo:corregir-valor', ($this->corregir)())->assertSuccessful();

    expect($retratar())
        ->toEqual($recibos)
        ->and(AplicacionDePago::query()->sum('monto'))->toEqual($aplicado);

    $this->artisan('olympo:cuadrar-recibos')
        ->expectsOutputToContain('Todos los recibos cuadran')
        ->assertSuccessful();
});

/*
| 🔴🔴 POR QUE LA CORRECCION TOCA LAS REPROGRAMACIONES.
|
| `RegistroDePagos::anular()` deshace un abono reescribiendo el `plan_anterior`
| de su constancia TAL CUAL. Si ese plan guardado no llevara la diferencia,
| anular el abono se la volvería a comer en silencio — el mismo error, por otra
| puerta. Este test es el que se pone rojo si alguien «simplifica» eso.
*/
test('anular el abono después de corregir no se vuelve a comer la diferencia', function (): void {
    $this->artisan('olympo:corregir-valor', ($this->corregir)())->assertSuccessful();

    app(RegistroDePagos::class)->anular($this->abono->fresh(), 'El cliente pidió que se le devuelva el abono');

    // 941,000.00 financiados − 19,604.01 de la cuota cobrada. Sin la
    // corrección de los planes guardados esto daría 921,387.99.
    expect($this->venta->fresh()?->saldoPendiente())->toBeMonto('921395.99');

    // Y el plan volvió a 48 cuotas, con la cola original más su diferencia.
    $colas = array_map(
        fn (Compromiso $lote): string => ($this->ultimaDe)($lote)->montoTotal()->redondeado(),
        $this->lotes,
    );

    sort($colas);

    expect($colas)->toBe(['6537.17', '6537.18', '6537.18']);
});

test('la constancia se corre entera y sigue cuadrando', function (): void {
    // Con su lote YA cargado: la relación se lee perezosa, y pedirla después
    // de corregir traería el valor nuevo en vez del viejo.
    $antes = Reprogramacion::query()->with('compromiso')->orderBy('id')->get();

    $this->artisan('olympo:corregir-valor', ($this->corregir)())->assertSuccessful();

    foreach ($antes as $vieja) {
        $nueva = $vieja->fresh();
        $lote = Compromiso::query()->whereKey($vieja->getAttribute('compromiso_id'))->firstOrFail();

        // Lo que subió ESE lote: 2.67 o 2.66.
        $diferencia = $lote->montoValor()->restar(new Monto((string) $vieja->compromiso?->getAttribute('valor')));

        expect($nueva?->montoAbonado())->toBeMonto($vieja->montoAbonado()->redondeado())
            ->and($nueva?->montoSaldoAnterior())->toBeMonto($vieja->montoSaldoAnterior()->sumar($diferencia)->redondeado())
            ->and($nueva?->montoSaldoNuevo())->toBeMonto($vieja->montoSaldoNuevo()->sumar($diferencia)->redondeado())
            // El plan guardado sigue teniendo las mismas cuotas…
            ->and(count($nueva?->planAnterior() ?? []))->toBe(count($vieja->planAnterior()));

        // …y sumando lo que dice el saldo anterior: es lo que reemplazó.
        $suma = Monto::cero();

        foreach ($nueva?->planAnterior() ?? [] as $cuota) {
            $suma = $suma->sumar(new Monto($cuota['monto']));
        }

        expect($suma)->toBeMonto((string) $nueva?->montoSaldoAnterior()->redondeado());
    }
});

/*
| 🔴 LA FICHA DEL LOTE NO SE TOCA, Y NO ES UN OLVIDO.
|
| La primera versión la llevaba al día por el query builder, para esquivar
| `LoteInmutableException`. La base tiene un trigger —`lotes_proteger_vendido`—
| que rechaza cambiarle el precio a un lote vendido venga de donde venga, y
| tumbó la transacción entera: once tests en rojo con el mismo «exit code 1».
| Lo que vale para una venta es lo congelado en `compromisos` (§8.2).
*/
test('la ficha del lote no se toca: la base protege al lote vendido', function (): void {
    $antes = ($this->foto)()['lotes'];

    $this->artisan('olympo:corregir-valor', ($this->corregir)())->assertSuccessful();

    expect(($this->foto)()['lotes'])->toEqual($antes);
});

/*
| UN asiento, no tres. `Venta` y `Compromiso` se registran solos, y sin apagar
| eso una corrección dejaría cuatro filas con nombres de columna y ninguna con
| el porqué.
*/
test('deja un solo asiento en el expediente, con el motivo', function (): void {
    $antes = Activity::query()->count();

    $this->artisan('olympo:corregir-valor', ($this->corregir)())->assertSuccessful();

    expect(Activity::query()->count())->toBe($antes + 1);

    $asiento = Activity::query()->where('event', 'correccion')->latest('id')->firstOrFail();
    $cambios = $asiento->attribute_changes?->get('attributes');

    expect($asiento->getAttribute('subject_type'))->toBe(Venta::class)
        ->and($asiento->getAttribute('subject_id'))->toBe($this->venta->getKey())
        ->and($asiento->properties->get('motivo'))->toContain('325,000.00 por lote')
        ->and($cambios)->toBeArray()
        ->and($cambios['saldo de los lotes corregidos'] ?? null)->toBe('L. 906,395.99');
});

/*
| El número de contrato es el mismo en local, en pruebas y en producción; el
| id no. El comando que se ensayó en un ambiente se pega tal cual en el otro.
*/
test('se puede pedir por número de contrato, que es igual en todos los ambientes', function (): void {
    $this->artisan('olympo:corregir-valor', ($this->corregir)([
        'venta' => (string) $this->venta->getAttribute('numero_contrato'),
    ]))->assertSuccessful();

    expect($this->venta->fresh()?->montoValorTotal())->toBeMonto('975000.00');
});

test('con --ensayo no escribe nada', function (): void {
    $antes = ($this->foto)();

    $this->artisan('olympo:corregir-valor', ($this->corregir)(['--ensayo' => true]))
        ->expectsOutputToContain('Ensayo: no se escribió nada')
        ->assertSuccessful();

    expect(($this->foto)())->toEqual($antes);
});

test('sin motivo no escribe nada', function (): void {
    $antes = ($this->foto)();
    $datos = ($this->corregir)();
    unset($datos['--motivo']);

    $this->artisan('olympo:corregir-valor', $datos)->assertFailed();

    expect(($this->foto)())->toEqual($antes);
});

test('correrlo dos veces no cambia nada ni ensucia la bitácora', function (): void {
    $this->artisan('olympo:corregir-valor', ($this->corregir)())->assertSuccessful();

    $foto = ($this->foto)();
    $asientos = Activity::query()->count();

    $this->artisan('olympo:corregir-valor', ($this->corregir)())
        ->expectsOutputToContain('Ya está corregido')
        ->assertSuccessful();

    expect(($this->foto)())->toEqual($foto)
        ->and(Activity::query()->count())->toBe($asientos);
});

/*
| Bajar también es corregir: si alguien cargó de más, la misma puerta lo
| devuelve. Y es la prueba más dura de que todo se mueve atado — subir y volver
| a bajar tiene que dejar cada fila exactamente como estaba.
*/
test('lo que sube, baja: deshacer la corrección deja todo como estaba', function (): void {
    $antes = ($this->foto)();
    $valores = array_map(static fn (array $c): string => (string) $c['valor'], $antes['compromisos']);

    $this->artisan('olympo:corregir-valor', ($this->corregir)())->assertSuccessful();

    $this->artisan('olympo:corregir-valor', ($this->corregir)([
        '--lote' => array_map(
            fn (Compromiso $lote, string $valor): string => ($this->codigo)($lote).':'.$valor,
            $this->lotes,
            $valores,
        ),
        '--motivo' => 'Se deshace la corrección para la prueba',
    ]))->assertSuccessful();

    expect(($this->foto)())->toEqual($antes);
});

/*
| 🔴 LA RED CONTRA EL DEDO MAL PUESTO. Un cero de más —3250000.00— no puede
| terminar en una última cuota de tres millones.
*/
test('una diferencia más grande que una cuota se niega', function (): void {
    $antes = ($this->foto)();

    $this->artisan('olympo:corregir-valor', ($this->corregir)([
        '--lote' => [($this->codigo)($this->lotes[0]).':335000.00'],
    ]))->assertFailed();

    expect(($this->foto)())->toEqual($antes);
});

test('un lote que no es del expediente se niega', function (): void {
    $this->artisan('olympo:corregir-valor', ($this->corregir)([
        '--lote' => ['RPS-Z-099:325000.00'],
    ]))->assertFailed();
});

test('un valor mal escrito se niega antes de mirar la base', function (): void {
    $this->artisan('olympo:corregir-valor', ($this->corregir)([
        '--lote' => [($this->codigo)($this->lotes[0]).':325,000.00'],
    ]))->assertFailed();
});

test('un expediente que ya no está vigente se niega', function (): void {
    // Crudo, como pide §9.C4: es el estado de la fila lo que se prueba, no el
    // trámite que la cierra.
    DB::table('ventas')->where('id', $this->venta->getKey())->update([
        'estado'     => 'liquidada',
        'cerrada_el' => today()->toDateString(),
    ]);

    app(CorreccionDeValor::class)->corregir(
        $this->venta->fresh(),
        [($this->codigo)($this->lotes[0]) => new Monto('325000.00')],
        'No debería pasar',
    );
})->throws(CorreccionDeValorInvalidaException::class, 'liquidada');

test('un lote vendido con interés se niega: eso es reamortizar', function (): void {
    DB::table('compromisos')->where('id', $this->lotes[0]->getKey())->update(['tasa_interes_anual' => '12.000']);

    app(CorreccionDeValor::class)->corregir(
        $this->venta,
        [($this->codigo)($this->lotes[0]) => new Monto('325000.00')],
        'No debería pasar',
    );
})->throws(CorreccionDeValorInvalidaException::class, 'interés');
