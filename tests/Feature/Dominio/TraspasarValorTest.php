<?php

declare(strict_types=1);

use App\Domain\Enums\FormaDePago;
use App\Domain\Enums\ModalidadDeReprogramacion;
use App\Domain\Exceptions\TraspasoDeValorInvalidoException;
use App\Domain\Pagos\RegistroDePagos;
use App\Domain\ValueObjects\Monto;
use App\Domain\Ventas\PrecioPactado;
use App\Domain\Ventas\RegistroDeVentas;
use App\Domain\Ventas\TraspasoDeValor;
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
use Carbon\CarbonImmutable;
use Spatie\Activitylog\Models\Activity;

/*
|--------------------------------------------------------------------------
| `olympo:traspasar-valor` — el O-3 que ya estaba pagado (24-oct-2026)
|--------------------------------------------------------------------------
| Expediente 0070 de Praderas, cuaderno pág. 147. El cuaderno lleva el O-3
| por L 210,000.00 con L 200,000.00 de prima, y el O-4 con el N-8 por
| L 605,000.00 con L 20,000.00 de prima — y esos 605,000 son los 250,000 y
| 345,000 de cada uno MÁS los 10,000 que le faltaban al O-3. La carga dejó
| cada lote con su valor individual, y el O-3 quedó debiendo L 10,000.00 en
| 48 cuotas de L 208.33 que nadie pactó.
|
| 🔴 ESTE TEST EXISTE PORQUE EL COMANDO ESCRIBE EN PRODUCCION, sobre un
| expediente con un recibo de L 24,000.00 ya entregado. Se estrena acá, con
| los mismos números: tres lotes, la prima de cada uno, y el recibo RPS-5 que
| cobró la cuota 1 de los tres y mandó L 11,812.50 al N-8 —de los que
| L 6,979.17 pusieron al día su cuota 2 y L 4,833.33 bajaron capital—.
|
| Lo que tiene que quedar, al céntimo:
|
|   O-3 · L 200,000.00 · sin cuotas: pagado con su prima.
|   O-4 · L 255,000.00 · 48 de L 5,104.17 (la última L 5,104.01), la 1 pagada.
|   N-8 · L 350,000.00 · cuotas de L 7,083.33, la 1 y la 2 pagadas, y
|         L 4,729.17 a capital: la 48 queda en L 2,354.32.
|   Debe en total L 561,000.00 — exactamente lo mismo que antes.
*/

/**
 * @return array<string, string>
 */
function traspasoDel0070(): array
{
    return [
        'RPS-O-003' => '200000.00',
        'RPS-O-004' => '255000.00',
        'RPS-N-008' => '350000.00',
    ];
}

/**
 * @param array<string, string> $valores
 *
 * @return list<string>
 */
function lotesDelPedido(array $valores): array
{
    $pedido = [];

    foreach ($valores as $codigo => $valor) {
        $pedido[] = $codigo.':'.$valor;
    }

    return $pedido;
}

/**
 * @param array<string, string> $valores
 *
 * @return array<string, Monto>
 */
function valoresDelPedido(array $valores): array
{
    return array_map(static fn (string $valor): Monto => new Monto($valor), $valores);
}

beforeEach(function (): void {
    actingAsAdmin();

    $this->travelTo(CarbonImmutable::parse('2026-06-20 10:00:00'));

    $proyecto = Proyecto::factory()->create(['codigo' => 'RPS']);
    $o = Bloque::factory()->create(['proyecto_id' => $proyecto->getKey(), 'nombre' => 'O']);
    $n = Bloque::factory()->create(['proyecto_id' => $proyecto->getKey(), 'nombre' => 'N']);

    $lotes = [
        Lote::factory()->enBloque($o)->conMedidas('250.0000', '840.000000')->create(['numero' => '3']),
        Lote::factory()->enBloque($o)->conMedidas('250.0000', '1000.000000')->create(['numero' => '4']),
        Lote::factory()->enBloque($n)->conMedidas('345.0900', '999.739198')->create(['numero' => '8']),
    ];

    $primas = ['200000.00', '10000.00', '10000.00'];

    $this->cliente = Cliente::factory()->create(['nombre' => 'CLIENTE DEL CUADERNO 147']);

    $this->venta = app(RegistroDeVentas::class)->activar(
        proyecto: $proyecto,
        lotes: $lotes,
        clientes: [$this->cliente],
        prima: new Monto('220000.00'),
        plazoMeses: 48,
        diaPago: 20,
        fechaContrato: CarbonImmutable::parse('2026-06-20'),
        precios: array_map(
            static fn (Lote $lote, string $prima): PrecioPactado => new PrecioPactado(
                loteId: (int) $lote->getKey(),
                precioVara: new Monto((string) $lote->getAttribute('precio_vara')),
                prima: new Monto($prima),
            ),
            $lotes,
            $primas,
        ),
    );

    /** @var array<string, Compromiso> $porCodigo */
    $porCodigo = [];

    foreach ($this->venta->compromisos()->with('lote')->orderBy('id')->get() as $compromiso) {
        $porCodigo[(string) $compromiso->lote?->getAttribute('codigo')] = $compromiso;
    }

    $this->lote = $porCodigo;

    // El recibo RPS-5: la cuota 1 de los tres y el resto al N-8.
    $this->travelTo(CarbonImmutable::parse('2026-08-26 09:00:00'));

    $this->recibo = app(RegistroDePagos::class)->cobrarYAbonar(
        venta: $this->venta,
        cliente: $this->cliente,
        cuotas: [
            ['lote' => $porCodigo['RPS-O-003'], 'monto' => new Monto('208.33')],
            ['lote' => $porCodigo['RPS-O-004'], 'monto' => new Monto('5000.00')],
            ['lote' => $porCodigo['RPS-N-008'], 'monto' => new Monto('6979.17')],
        ],
        abonos: [
            ['lote' => $porCodigo['RPS-N-008'], 'monto' => new Monto('11812.50'), 'modalidad' => ModalidadDeReprogramacion::AcortarPlazo],
        ],
        motivo: 'Abono a capital solicitado por el cliente',
        forma: FormaDePago::Efectivo,
    )[0];

    $this->travelTo(CarbonImmutable::parse('2026-10-24 11:00:00'));

    $this->pedido = fn (array $extra = []): array => array_merge([
        'venta'    => $this->venta->getKey(),
        '--lote'   => lotesDelPedido(traspasoDel0070()),
        '--motivo' => 'Cuaderno pág. 147: el O-3 queda pagado con su prima; los 10,000 van al O-4 y al N-8',
    ], $extra);

    $this->cuotasDe = fn (string $codigo): array => Cuota::query()
        ->where('compromiso_id', $this->lote[$codigo]->getKey())
        ->orderBy('numero')
        ->get()
        ->all();

    $this->foto = fn (): array => [
        'venta'        => Venta::query()->whereKey($this->venta->getKey())->firstOrFail()->only(['valor_total', 'saldo_financiar', 'cuota_mensual', 'plazo_meses']),
        'compromisos'  => Compromiso::query()->where('venta_id', $this->venta->getKey())->orderBy('id')->get(['id', 'valor', 'precio_vara', 'precio_vara_lista', 'plazo_meses', 'prima'])->toArray(),
        'cuotas'       => Cuota::query()->where('venta_id', $this->venta->getKey())->orderBy('compromiso_id')->orderBy('numero')->get(['compromiso_id', 'numero', 'fecha_vencimiento', 'monto', 'monto_pagado'])->toArray(),
        'aplicaciones' => AplicacionDePago::query()->where('recibo_id', $this->recibo->getKey())->count(),
        'constancias'  => Reprogramacion::query()->where('venta_id', $this->venta->getKey())->get(['compromiso_id', 'abono_capital'])->toArray(),
        'recibos'      => Recibo::query()->where('venta_id', $this->venta->getKey())->orderBy('id')->get(['id', 'numero', 'monto', 'fecha', 'concepto', 'recibido_por'])->toArray(),
    ];
});

test('el escenario arranca como lo dejó la carga: el O-3 debiendo cuotas que nadie pactó', function (): void {
    expect($this->venta->fresh()?->saldoPendiente())->toBeMonto('561000.00')
        ->and(($this->cuotasDe)('RPS-O-003'))->toHaveCount(48)
        ->and($this->recibo->montoTotal())->toBeMonto('24000.00')
        ->and($this->recibo->cuadra())->toBeTrue();

    // La cuota 2 del N-8 la puso al día el abono: el resto bajó capital.
    $constancia = Reprogramacion::query()->where('recibo_id', $this->recibo->getKey())->firstOrFail();

    expect($constancia->montoAbonado())->toBeMonto('4833.33');
});

test('el traspaso deja los tres lotes como dice el cuaderno, al céntimo', function (): void {
    $this->artisan('olympo:traspasar-valor', ($this->pedido)())->assertSuccessful();

    // O-3: pagado con su prima. Sin cuotas, de contado.
    $o3 = $this->lote['RPS-O-003']->fresh();

    expect($o3?->montoValor())->toBeMonto('200000.00')
        ->and($o3?->getAttribute('plazo_meses'))->toBe(0)
        ->and(($this->cuotasDe)('RPS-O-003'))->toBe([]);

    // O-4: 48 de 5,104.17, la última 5,104.01, la 1 pagada.
    $o4 = ($this->cuotasDe)('RPS-O-004');

    expect($this->lote['RPS-O-004']->fresh()?->montoValor())->toBeMonto('255000.00')
        ->and($o4)->toHaveCount(48)
        ->and($o4[0]->montoTotal())->toBeMonto('5104.17')
        ->and($o4[0]->estaPagada())->toBeTrue()
        ->and($o4[1]->montoPagado())->toBeMonto('0.00')
        ->and($o4[47]->montoTotal())->toBeMonto('5104.01');

    // N-8: la 1 y la 2 pagadas a 7,083.33; el resto a capital acortó el plazo.
    $n8 = ($this->cuotasDe)('RPS-N-008');
    $constancia = Reprogramacion::query()->where('recibo_id', $this->recibo->getKey())->firstOrFail();

    expect($this->lote['RPS-N-008']->fresh()?->montoValor())->toBeMonto('350000.00')
        ->and($n8[0]->montoPagado())->toBeMonto('7083.33')
        ->and($n8[1]->montoPagado())->toBeMonto('7083.33')
        ->and($n8[2]->montoTotal())->toBeMonto('7083.33')
        ->and($n8[2]->montoPagado())->toBeMonto('0.00')
        ->and($n8)->toHaveCount(48)
        ->and($n8[47]->montoTotal())->toBeMonto('2354.32')
        ->and((int) $constancia->getAttribute('compromiso_id'))->toBe((int) $this->lote['RPS-N-008']->getKey())
        ->and($constancia->montoAbonado())->toBeMonto('4729.17')
        ->and($constancia->modalidadElegida())->toBe(ModalidadDeReprogramacion::AcortarPlazo);

    // El expediente debe exactamente lo mismo.
    expect($this->venta->fresh()?->saldoPendiente())->toBeMonto('561000.00')
        ->and($this->venta->fresh()?->montoValorTotal())->toBeMonto('805000.00');
});

test('el recibo del cliente no cambia: mismo número, monto, fecha y receptor, y sigue cuadrando', function (): void {
    $antes = ($this->foto)()['recibos'];

    $this->artisan('olympo:traspasar-valor', ($this->pedido)())->assertSuccessful();

    $recibo = $this->recibo->fresh();

    expect(($this->foto)()['recibos'])->toBe($antes)
        ->and($recibo?->cuadra())->toBeTrue()
        ->and($recibo?->loQueAplico())->toBeMonto('24000.00')
        // Ya no le toca nada al O-3: no le queda cuota que pagar.
        ->and($recibo?->aplicaciones()->whereIn('cuota_id', Cuota::query()->select('id')->where('compromiso_id', $this->lote['RPS-O-003']->getKey()))->exists())->toBeFalse();
});

test('el primer vencimiento de cada lote que sigue debiendo no se mueve', function (): void {
    $primera = fn (string $codigo): string => (string) Cuota::query()
        ->where('compromiso_id', $this->lote[$codigo]->getKey())
        ->where('numero', 1)
        ->value('fecha_vencimiento');

    $o4 = $primera('RPS-O-004');
    $n8 = $primera('RPS-N-008');

    $this->artisan('olympo:traspasar-valor', ($this->pedido)())->assertSuccessful();

    expect($primera('RPS-O-004'))->toBe($o4)
        ->and($primera('RPS-N-008'))->toBe($n8);
});

test('el ensayo imprime lo que va a quedar y no escribe nada', function (): void {
    $antes = ($this->foto)();
    $bitacora = Activity::query()->count();

    $this->artisan('olympo:traspasar-valor', ($this->pedido)(['--ensayo' => true]))
        ->expectsOutputToContain('pagado')
        ->expectsOutputToContain('L. 561,000.00')
        ->assertSuccessful();

    expect(($this->foto)())->toBe($antes)
        ->and(Activity::query()->count())->toBe($bitacora);
});

test('queda asentado en la bitácora del expediente, con el motivo y el reparto del recibo', function (): void {
    $this->artisan('olympo:traspasar-valor', ($this->pedido)())->assertSuccessful();

    $asiento = Activity::query()->where('event', 'traspaso')->latest('id')->firstOrFail();
    $viejos = $asiento->attribute_changes?->get('old');
    $nuevos = $asiento->attribute_changes?->get('attributes');
    $folio = $this->recibo->folio();

    expect($asiento->getAttribute('subject_type'))->toBe(Venta::class)
        ->and($asiento->getAttribute('subject_id'))->toBe($this->venta->getKey())
        ->and($asiento->properties->get('motivo'))->toContain('Cuaderno pág. 147')
        ->and($viejos['valor del lote RPS-O-003'] ?? null)->toBe('L. 210,000.00')
        ->and($nuevos['valor del lote RPS-O-003'] ?? null)->toBe('L. 200,000.00')
        ->and($nuevos['cuota del lote RPS-O-003'] ?? null)->toBe('sin cuotas')
        ->and($nuevos['reparto del recibo '.$folio] ?? null)->toBe('RPS-N-008 L. 18,895.83 · RPS-O-004 L. 5,104.17');
});

/*
| Deshacer después de traspasar: `anular()` usa el plan viejo que guardó la
| constancia. Si el traspaso dejara una constancia mentirosa, anular el
| recibo dejaría al cliente debiendo un número inventado.
*/
test('anular el recibo después del traspaso devuelve exacto lo financiado de los planes nuevos', function (): void {
    $this->artisan('olympo:traspasar-valor', ($this->pedido)())->assertSuccessful();

    app(RegistroDePagos::class)->anular($this->recibo->fresh() ?? $this->recibo, 'Prueba: se cobró al expediente equivocado');

    $n8 = ($this->cuotasDe)('RPS-N-008');

    // 0 del O-3 + 245,000 del O-4 + 340,000 del N-8.
    expect($this->venta->fresh()?->saldoPendiente())->toBeMonto('585000.00')
        ->and($n8)->toHaveCount(48)
        ->and($n8[47]->montoTotal())->toBeMonto('7083.49');
});

test('se puede pedir por número de contrato, que es igual en todos los ambientes', function (): void {
    $this->artisan('olympo:traspasar-valor', ($this->pedido)([
        'venta' => (string) $this->venta->getAttribute('numero_contrato'),
    ]))->assertSuccessful();

    expect($this->lote['RPS-O-003']->fresh()?->montoValor())->toBeMonto('200000.00');
});

// ─── Lo que se niega a hacer ─────────────────────────────────────────

test('se niega si los valores no suman lo mismo que hoy: eso es corregir-valor', function (): void {
    $antes = ($this->foto)();

    $pedido = traspasoDel0070();
    $pedido['RPS-N-008'] = '351000.00';

    $this->artisan('olympo:traspasar-valor', ($this->pedido)(['--lote' => lotesDelPedido($pedido)]))
        ->expectsOutputToContain('olympo:corregir-valor')
        ->assertFailed();

    expect(($this->foto)())->toBe($antes);
});

test('se niega si falta un lote del contrato', function (): void {
    $pedido = traspasoDel0070();
    unset($pedido['RPS-N-008']);

    $this->artisan('olympo:traspasar-valor', ($this->pedido)(['--lote' => lotesDelPedido($pedido)]))
        ->assertFailed();

    expect($this->lote['RPS-O-003']->fresh()?->montoValor())->toBeMonto('210000.00');
});

test('se niega si nombra un lote que no es del contrato', function (): void {
    $pedido = traspasoDel0070();
    $pedido['RPS-Z-999'] = '0.01';

    expect(fn () => app(TraspasoDeValor::class)->ensayar($this->venta, valoresDelPedido($pedido)))
        ->toThrow(TraspasoDeValorInvalidoException::class);
});

test('se niega a dejar un lote valiendo menos que su prima', function (): void {
    expect(fn () => app(TraspasoDeValor::class)->ensayar($this->venta, valoresDelPedido([
        'RPS-O-003' => '190000.00',
        'RPS-O-004' => '260000.00',
        'RPS-N-008' => '355000.00',
    ])))->toThrow(TraspasoDeValorInvalidoException::class);
});

test('sin motivo no escribe, y lo dice', function (): void {
    $antes = ($this->foto)();

    $this->artisan('olympo:traspasar-valor', ($this->pedido)(['--motivo' => '  ']))
        ->expectsOutputToContain('No se escribió NADA')
        ->assertFailed();

    expect(($this->foto)())->toBe($antes);
});

test('correrlo dos veces no hace daño: la segunda no tiene nada que mover', function (): void {
    $this->artisan('olympo:traspasar-valor', ($this->pedido)())->assertSuccessful();

    $despues = ($this->foto)();

    $this->artisan('olympo:traspasar-valor', ($this->pedido)())->assertFailed();

    expect(($this->foto)())->toBe($despues);
});

/*
| Un recibo anulado no borra sus aplicaciones: quedan como rastro, colgadas de
| las cuotas (`restrictOnDelete`). Rehacer esas cuotas las dejaría huérfanas,
| así que con un rastro adentro se niega y nombra el papel.
*/
test('un recibo anulado deja rastro en las cuotas, y con eso adentro se niega', function (): void {
    app(RegistroDePagos::class)->anular($this->recibo, 'Prueba: se cobró al expediente equivocado');

    $antes = ($this->foto)();

    $this->artisan('olympo:traspasar-valor', ($this->pedido)())
        ->expectsOutputToContain($this->recibo->folio())
        ->assertFailed();

    expect(($this->foto)())->toBe($antes);
});
