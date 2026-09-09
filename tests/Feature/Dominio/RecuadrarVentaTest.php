<?php

declare(strict_types=1);

use App\Domain\Enums\FormaDePago;
use App\Domain\Enums\ModalidadDeReprogramacion;
use App\Domain\Pagos\RegistroDePagos;
use App\Domain\ValueObjects\Monto;
use App\Domain\Ventas\PrecioPactado;
use App\Domain\Ventas\RegistroDeVentas;
use App\Models\Bloque;
use App\Models\Cliente;
use App\Models\Cuota;
use App\Models\Lote;
use App\Models\Proyecto;
use App\Models\Reprogramacion;

/*
|--------------------------------------------------------------------------
| `olympo:recuadrar-venta` — el dinero bien cobrado y mal repartido
|--------------------------------------------------------------------------
| Salió del expediente 0085 de Praderas: cinco lotes en un contrato, los
| recibos cuadrando al centavo, la suma de los cinco saldos igual a la del
| cuaderno, y lote por lote los números corridos. Al RPS-W-005 le sobraban
| L 46,928.31 de saldo y a los otros cuatro les faltaba lo mismo en total.
|
| 🔴 ESTE TEST EXISTE PORQUE EL COMANDO ESCRIBE EN PRODUCCION. El camino que
| toca la cartera real no puede estrenarse ahí: se estrena acá.
|
| Dos lotes de 250 vr² a L 1,400.00 = L 350,000.00 cada uno, L 50,000.00 de
| prima. El primero a 12 meses da cuotas de L 25,000.00; el segundo a 24 da
| L 12,500.00. Financiado: L 300,000.00 cada uno.
*/

beforeEach(function (): void {
    actingAsAdmin();

    $proyecto = Proyecto::factory()->create(['codigo' => 'RPS']);
    $bloque = Bloque::factory()->create(['proyecto_id' => $proyecto->getKey(), 'nombre' => 'A']);

    $uno = Lote::factory()->enBloque($bloque)->conMedidas('250.0000', '1400.00')->create(['numero' => '1']);
    $dos = Lote::factory()->enBloque($bloque)->conMedidas('250.0000', '1400.00')->create(['numero' => '2']);

    $cliente = Cliente::factory()->create(['nombre' => 'MARIA EVELINA CABALLERO']);

    $condicion = static fn (Lote $lote, int $meses): PrecioPactado => new PrecioPactado(
        loteId: (int) $lote->getKey(),
        precioVara: new Monto('1400.00'),
        plazoMeses: $meses,
        prima: new Monto('50000.00'),
    );

    $this->venta = app(RegistroDeVentas::class)->activar(
        proyecto: $proyecto,
        lotes: [$uno, $dos],
        clientes: [$cliente],
        prima: new Monto('100000.00'),
        plazoMeses: 12,
        diaPago: 5,
        precios: [$condicion($uno, 12), $condicion($dos, 24)],
    );

    $this->primerLote = $this->venta->compromisos()->orderBy('lote_id')->firstOrFail();
    $this->segundoLote = $this->venta->compromisos()->orderByDesc('lote_id')->firstOrFail();

    $this->codigo = fn ($lote): string => (string) $lote->lote?->getAttribute('codigo');

    /*
    | El error que reproduce el caso: se cobra la cuota del PRIMER lote y los
    | L 100,000.00 de sobrante se abonan al SEGUNDO. El cuaderno decía que ese
    | abono era del primero.
    */
    $recibos = app(RegistroDePagos::class)->cobrarYAbonar(
        venta: $this->venta,
        cliente: $cliente,
        cuotas: [['lote' => $this->primerLote, 'monto' => new Monto('25000.00')]],
        abonos: [[
            'lote'      => $this->segundoLote,
            'monto'     => new Monto('100000.00'),
            'modalidad' => ModalidadDeReprogramacion::AcortarPlazo,
        ]],
        motivo: 'Abono a capital solicitado por el cliente',
        forma: FormaDePago::Efectivo,
    );

    $this->recibo = $recibos[0];

    $this->saldoDe = function ($lote): Monto {
        $saldo = Monto::cero();

        foreach (Cuota::query()->where('compromiso_id', $lote->getKey())->get() as $cuota) {
            $saldo = $saldo->sumar($cuota->saldo());
        }

        return $saldo;
    };

    // El objetivo del cuaderno: el abono era del primer lote.
    $this->recuadrar = fn (array $extra = []): array => array_merge([
        'venta'  => $this->venta->getKey(),
        '--lote' => [
            ($this->codigo)($this->primerLote).':175000.00',
            ($this->codigo)($this->segundoLote).':300000.00',
        ],
        '--abono' => [
            $this->recibo->folio().':'.($this->codigo)($this->primerLote).':100000.00',
        ],
        '--motivo' => 'Recuadre contra el cuaderno, expediente de prueba',
    ], $extra);
});

/*
| El punto de partida: el abono cayó en el lote equivocado.
*/
test('el escenario arranca mal repartido', function (): void {
    expect(($this->saldoDe)($this->primerLote))->toBeMonto('275000.00')
        ->and(($this->saldoDe)($this->segundoLote))->toBeMonto('200000.00');
});

test('el recuadre deja cada lote en su objetivo', function (): void {
    $this->artisan('olympo:recuadrar-venta', ($this->recuadrar)())->assertSuccessful();

    expect(($this->saldoDe)($this->primerLote))->toBeMonto('175000.00')
        ->and(($this->saldoDe)($this->segundoLote))->toBeMonto('300000.00')
        // 175,000 a cuotas de 25,000 son 7 exactas.
        ->and(Cuota::query()->where('compromiso_id', $this->primerLote->getKey())->count())->toBe(8);
});

/*
| 🔴 LO QUE NO SE TOCA. El papel que el cliente tiene en la mano dice «recibí
| L 125,000.00», y eso es verdad: lo que estaba mal era la imputación.
*/
test('el recibo no se toca, y sigue cuadrando', function (): void {
    $antes = $this->recibo->only(['numero', 'monto', 'fecha', 'concepto', 'recibido_por']);

    $this->artisan('olympo:recuadrar-venta', ($this->recuadrar)())->assertSuccessful();

    expect($this->recibo->fresh()?->only(['numero', 'monto', 'fecha', 'concepto', 'recibido_por']))
        ->toEqual($antes);

    $this->artisan('olympo:cuadrar-recibos')
        ->expectsOutputToContain('Todos los recibos cuadran')
        ->assertSuccessful();
});

/*
| La constancia se REUSA, no se borra: su `plan_anterior` y su fecha son la
| única historia de lo que pasó ese día.
*/
test('la constancia cambia de lote sin perder su fila', function (): void {
    $antes = Reprogramacion::query()->sole();

    $this->artisan('olympo:recuadrar-venta', ($this->recuadrar)())->assertSuccessful();

    $despues = Reprogramacion::query()->sole();

    expect($despues->getKey())->toBe($antes->getKey())
        ->and($despues->getAttribute('compromiso_id'))->toBe($this->primerLote->getKey())
        ->and($despues->montoAbonado())->toBeMonto('100000.00');
});

/*
| 🔴🔴 LO QUE TUMBO LA PRIMERA CORRIDA EN PRODUCCION (9-sep-2026).
|
| La primera versión del comando cambiaba `abono_capital` y dejaba
| `saldo_anterior` y `saldo_nuevo` como estaban. La base tiene
| `reprogramaciones_saldo_cuadra_chk` —«un centavo de diferencia tumba la
| transacción entera»— y la paró antes de escribir una sola fila.
|
| **La constancia no es un renglón suelto: es la aritmética de un momento.**
| Cambiar el abono obliga a recalcular el antes y el después replicando la
| historia del lote.
|
| Acá: el lote arranca en 300,000, el recibo le cobra 25,000 de cuota y recién
| entonces recibe el abono de 100,000.
*/
test('la constancia queda con los saldos recalculados, no con los viejos', function (): void {
    $this->artisan('olympo:recuadrar-venta', ($this->recuadrar)())->assertSuccessful();

    $constancia = Reprogramacion::query()->sole();

    /*
    | ⚠️ Los saldos NO tienen cast a `Monto` —Postgres entrega NUMERIC como
    | string, que es lo que consume bcmath (§8.3.1)—, así que se envuelven acá.
    | `montoAbonado()` sí es un accesor y por eso se usa tal cual.
    */
    $antes = new Monto((string) $constancia->getAttribute('saldo_anterior'));
    $nuevo = new Monto((string) $constancia->getAttribute('saldo_nuevo'));

    expect($antes)->toBeMonto('275000.00')
        ->and($nuevo)->toBeMonto('175000.00')
        // 275,000 y 175,000 a cuotas de 25,000.
        ->and($constancia->getAttribute('cuotas_antes'))->toBe(11)
        ->and($constancia->getAttribute('cuotas_despues'))->toBe(7)
        // Y la igualdad que impone el CHECK, dicha de nuevo acá: si alguien
        // cambia la réplica, este test se cae antes que Postgres.
        ->and($antes->restar($constancia->montoAbonado()))->toBeMonto($nuevo->redondeado());
});

test('con --ensayo no escribe nada', function (): void {
    $this->artisan('olympo:recuadrar-venta', ($this->recuadrar)(['--ensayo' => true]))
        ->assertSuccessful();

    expect(($this->saldoDe)($this->primerLote))->toBeMonto('275000.00')
        ->and(($this->saldoDe)($this->segundoLote))->toBeMonto('200000.00');
});

test('sin motivo no escribe nada', function (): void {
    $datos = ($this->recuadrar)();
    unset($datos['--motivo']);

    $this->artisan('olympo:recuadrar-venta', $datos)->assertFailed();

    expect(($this->saldoDe)($this->primerLote))->toBeMonto('275000.00');
});

/*
| 🔴 INVARIANTE 1 — un recuadre reparte; no perdona ni inventa deuda. Si la
| suma de los objetivos no es la de hoy, ni se intenta.
*/
test('si los objetivos no suman lo mismo que hoy, se niega', function (): void {
    $this->artisan('olympo:recuadrar-venta', ($this->recuadrar)([
        '--lote' => [
            ($this->codigo)($this->primerLote).':100000.00',
            ($this->codigo)($this->segundoLote).':300000.00',
        ],
    ]))->assertFailed();

    expect(($this->saldoDe)($this->primerLote))->toBeMonto('275000.00')
        ->and(($this->saldoDe)($this->segundoLote))->toBeMonto('200000.00');
});

/*
| 🔴 INVARIANTE 2 — el papel tiene que seguir diciendo la verdad.
*/
test('si los abonos no cierran el recibo, se niega', function (): void {
    $this->artisan('olympo:recuadrar-venta', ($this->recuadrar)([
        '--abono' => [
            $this->recibo->folio().':'.($this->codigo)($this->primerLote).':90000.00',
        ],
    ]))->assertFailed();

    expect(($this->saldoDe)($this->primerLote))->toBeMonto('275000.00');
});

/*
| 🔴 INVARIANTE 3 — los objetivos y los abonos se sostienen entre sí. Acá los
| dos cuadran por separado pero no entre ellos: el abono dice que va al
| segundo lote y los objetivos dicen que bajó el primero.
*/
test('si los objetivos y los abonos se contradicen, se niega', function (): void {
    $this->artisan('olympo:recuadrar-venta', ($this->recuadrar)([
        '--abono' => [
            $this->recibo->folio().':'.($this->codigo)($this->segundoLote).':100000.00',
        ],
    ]))->assertFailed();

    expect(($this->saldoDe)($this->primerLote))->toBeMonto('275000.00')
        ->and(($this->saldoDe)($this->segundoLote))->toBeMonto('200000.00');
});

test('un lote que falta en --lote se niega', function (): void {
    $this->artisan('olympo:recuadrar-venta', ($this->recuadrar)([
        '--lote' => [($this->codigo)($this->primerLote).':175000.00'],
    ]))->assertFailed();
});
