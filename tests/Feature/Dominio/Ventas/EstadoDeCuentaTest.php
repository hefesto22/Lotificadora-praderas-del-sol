<?php

declare(strict_types=1);

use App\Domain\Enums\FormaDePago;
use App\Domain\Pagos\RegistroDePagos;
use App\Domain\ValueObjects\Monto;
use App\Domain\Ventas\EstadoDeCuenta;
use App\Domain\Ventas\PrecioPactado;
use App\Domain\Ventas\RegistroDeVentas;
use App\Models\Bloque;
use App\Models\Cliente;
use App\Models\Cuota;
use App\Models\Lote;
use App\Models\Proyecto;

/*
|--------------------------------------------------------------------------
| Estado de cuenta — Cláusula Segunda
|--------------------------------------------------------------------------
| DOS lotes con plazos distintos, que es el caso que rompe cualquier cuenta
| hecha a ojo. Los dos de 250 vr² a L 1,400.00 = L 350,000.00 cada uno, con
| L 50,000.00 de prima cada uno:
|
|   Lote A → 300,000 a 12 meses = 25,000 exactos
|   Lote B → 300,000 a 24 meses = 12,500 exactos
|
| Contrato: valor 700,000, prima 100,000, saldo 600,000, 36 cuotas.
| Todos los números de abajo salen de ahí y se verifican sin calculadora.
*/

beforeEach(function (): void {
    actingAsAdmin();

    $proyecto = Proyecto::factory()->create(['codigo' => 'RPS']);
    $bloque = Bloque::factory()->create(['proyecto_id' => $proyecto->getKey(), 'nombre' => 'A']);

    $uno = Lote::factory()->enBloque($bloque)->conMedidas('250.0000', '1400.00')->create(['numero' => '1']);
    $dos = Lote::factory()->enBloque($bloque)->conMedidas('250.0000', '1400.00')->create(['numero' => '2']);

    $this->cliente = Cliente::factory()->create(['nombre' => 'Leticia Romero']);
    $this->socio = Cliente::factory()->create(['nombre' => 'Marcos Romero']);

    $condicion = static fn (Lote $lote, int $meses): PrecioPactado => new PrecioPactado(
        loteId: (int) $lote->getKey(),
        precioVara: new Monto('1400.00'),
        plazoMeses: $meses,
        prima: new Monto('50000.00'),
    );

    $this->venta = app(RegistroDeVentas::class)->activar(
        proyecto: $proyecto,
        lotes: [$uno, $dos],
        clientes: [$this->cliente, $this->socio],
        prima: new Monto('100000.00'),
        plazoMeses: 12,
        diaPago: 5,
        precios: [$condicion($uno, 12), $condicion($dos, 24)],
    );

    $this->loteA = $this->venta->compromisos()->orderBy('lote_id')->firstOrFail();

    $this->cuenta = fn (): EstadoDeCuenta => EstadoDeCuenta::de($this->venta->fresh() ?? $this->venta);

    $this->cobrar = fn (string $monto) => app(RegistroDePagos::class)->cobrarCuotas(
        venta: $this->venta,
        lote: $this->loteA,
        cliente: $this->cliente,
        monto: new Monto($monto),
        forma: FormaDePago::Efectivo,
    );

    $this->atrasar = function (array $numeros): void {
        Cuota::query()
            ->where('compromiso_id', $this->loteA->getKey())
            ->whereIn('numero', $numeros)
            ->update(['fecha_vencimiento' => today()->subMonths(2)->toDateString()]);
    };
});

describe('Recién firmado', function (): void {
    test('el resumen cuadra con el contrato', function (): void {
        $cuenta = ($this->cuenta)();

        expect($cuenta->valorTotal)->toBeMonto('700000.00')
            ->and($cuenta->prima)->toBeMonto('100000.00')
            ->and($cuenta->saldo)->toBeMonto('600000.00')
            ->and($cuenta->pagadoEnCuotas)->toBeMonto('0.00')
            // La prima entra en «total pagado»: el cliente la puso.
            ->and($cuenta->totalPagado())->toBeMonto('100000.00')
            ->and($cuenta->cuotasTotales)->toBe(36)
            ->and($cuenta->cuotasPagadas)->toBe(0)
            ->and($cuenta->estaAlDia())->toBeTrue()
            ->and($cuenta->estaCancelado())->toBeFalse();
    });

    /*
    | El número que el cliente pregunta parado en el mostrador. Con plazos
    | mezclados NO se puede leer de `ventas.cuota_mensual`: es la suma de las
    | cuotas vivas, y baja sola cuando un lote se termina.
    */
    test('la cuota del mes es la suma de las cuotas vivas', function (): void {
        expect(($this->cuenta)()->cuotaDelMes())->toBeMonto('37500.00');
    });

    test('se parte en una sección por lote', function (): void {
        $cuenta = ($this->cuenta)();

        expect($cuenta->lotes)->toHaveCount(2)
            ->and($cuenta->tieneVariosLotes())->toBeTrue()
            ->and($cuenta->lotes[0]->cuotasTotales())->toBe(12)
            ->and($cuenta->lotes[1]->cuotasTotales())->toBe(24)
            ->and($cuenta->lotes[0]->cuota)->toBeMonto('25000.00')
            ->and($cuenta->lotes[1]->cuota)->toBeMonto('12500.00');
    });

    /*
    | R8: el estado de cuenta sale a nombre del titular, con los demás
    | listados. El titular es el primero de la venta.
    */
    test('sale a nombre del titular, con los copropietarios aparte', function (): void {
        $cuenta = ($this->cuenta)();

        expect($cuenta->titular?->getKey())->toBe($this->cliente->getKey())
            ->and($cuenta->copropietarios)->toHaveCount(1)
            ->and($cuenta->copropietarios[0]->getKey())->toBe($this->socio->getKey());
    });
});

describe('Con pagos encima', function (): void {
    test('lo pagado y el saldo se mueven juntos', function (): void {
        ($this->cobrar)('60000.00');

        $cuenta = ($this->cuenta)();

        // 25,000 + 25,000 + 10,000 = 60,000: dos cuotas saldadas y una a medias.
        expect($cuenta->pagadoEnCuotas)->toBeMonto('60000.00')
            ->and($cuenta->totalPagado())->toBeMonto('160000.00')
            ->and($cuenta->saldo)->toBeMonto('540000.00')
            ->and($cuenta->cuotasPagadas)->toBe(2);
    });

    /*
    | La cuota vigente es la de la primera PENDIENTE, no la del contrato: si un
    | abono a capital la bajó (R21), el papel tiene que decir la que el cliente
    | va a pagar el mes que viene.
    */
    test('la cuota a medias sigue siendo la vigente de ese lote', function (): void {
        ($this->cobrar)('60000.00');

        $lote = ($this->cuenta)()->lotes[0];

        expect($lote->cuota)->toBeMonto('25000.00')
            ->and($lote->proxima()?->getAttribute('numero'))->toBe(3)
            ->and($lote->pagado)->toBeMonto('60000.00')
            ->and($lote->saldo)->toBeMonto('240000.00');
    });
});

describe('Con atraso', function (): void {
    /*
    | R2: se cuenta el atraso, no se cobra. El cliente debe exactamente lo
    | mismo que debía el día del vencimiento.
    */
    test('cuenta las vencidas y lo que falta de ellas, sin recargo', function (): void {
        ($this->cobrar)('60000.00');
        ($this->atrasar)([1, 2, 3]);

        $cuenta = ($this->cuenta)();

        // La 1 y la 2 están pagadas: vencida solo la 3, con 15,000 de saldo.
        expect($cuenta->cuotasVencidas)->toBe(1)
            ->and($cuenta->vencido)->toBeMonto('15000.00')
            ->and($cuenta->estaAlDia())->toBeFalse()
            ->and($cuenta->diasDeAtraso())->toBeGreaterThan(0)
            // El saldo NO subió por el atraso.
            ->and($cuenta->saldo)->toBeMonto('540000.00');
    });

    test('una cuota pagada nunca está vencida, por vieja que sea', function (): void {
        ($this->cobrar)('50000.00');
        ($this->atrasar)([1, 2]);

        expect(($this->cuenta)()->cuotasVencidas)->toBe(0)
            ->and(($this->cuenta)()->estaAlDia())->toBeTrue();
    });
});

describe('Cuando ya está todo pagado', function (): void {
    test('el contrato queda cancelado y sin próxima cuota', function (): void {
        app(RegistroDePagos::class)->cobrarCuotas(
            venta: $this->venta,
            lote: $this->loteA,
            cliente: $this->cliente,
            monto: new Monto('300000.00'),
            forma: FormaDePago::Efectivo,
        );

        $cuenta = ($this->cuenta)();

        expect($cuenta->lotes[0]->estaCancelado())->toBeTrue()
            ->and($cuenta->lotes[0]->cuota)->toBeNull()
            ->and($cuenta->lotes[0]->proxima())->toBeNull()
            // El contrato NO: el lote B sigue debiendo.
            ->and($cuenta->estaCancelado())->toBeFalse()
            ->and($cuenta->saldo)->toBeMonto('300000.00')
            // Y la cuota del mes bajó sola: un lote menos.
            ->and($cuenta->cuotaDelMes())->toBeMonto('12500.00');
    });
});

/*
| El invariante del documento: lo que dice arriba tiene que ser la suma de lo
| que dice abajo. Es lo que un cliente revisa con una calculadora en la mano.
*/
test('los totales del contrato son la suma de las secciones', function (): void {
    ($this->cobrar)('60000.00');

    $cuenta = ($this->cuenta)();

    $pagado = Monto::cero();
    $saldo = Monto::cero();
    $cuotas = 0;

    foreach ($cuenta->lotes as $lote) {
        $pagado = $pagado->sumar($lote->pagado);
        $saldo = $saldo->sumar($lote->saldo);
        $cuotas += $lote->cuotasTotales();
    }

    expect($pagado->redondeado())->toBe($cuenta->pagadoEnCuotas->redondeado())
        ->and($saldo->redondeado())->toBe($cuenta->saldo->redondeado())
        ->and($cuotas)->toBe($cuenta->cuotasTotales)
        // Y contra la venta, que es la otra fuente.
        ->and($cuenta->saldo->redondeado())->toBe($this->venta->refresh()->saldoPendiente()->redondeado());
});
