<?php

declare(strict_types=1);

use App\Domain\Enums\FormaDePago;
use App\Domain\Pagos\RegistroDePagos;
use App\Domain\ValueObjects\Monto;
use App\Domain\Ventas\EstadoDeCuenta;
use App\Domain\Ventas\PrecioPactado;
use App\Domain\Ventas\RegistroDeVentas;
use App\Domain\Ventas\TasaDeInteres;
use App\Models\AplicacionDePago;
use App\Models\Bloque;
use App\Models\Cliente;
use App\Models\Lote;
use App\Models\Proyecto;
use App\Models\Recibo;

/*
|--------------------------------------------------------------------------
| «¿Cuánto de lo que pagué bajó mi deuda?»
|--------------------------------------------------------------------------
|
| Es la primera pregunta que hace un cliente que firmó con interés, y hasta
| hoy no había dónde contestarla: el estado de cuenta mostraba la cuota
| entera y el recibo el total recibido. Los dos papeles llevan el sello de la
| lotificadora y ninguno decía en qué se convirtió el dinero.
|
| El dato existía desde el 8-ago —`cuotas.monto_interes`,
| `aplicaciones_de_pago.monto_interes`—; lo que faltaba era imprimirlo.
|
| Un lote de 250 vr² a L 1,400.00 = L 350,000.00, prima L 50,000.00, doce
| meses al 12 % anual sobre un saldo de L 300,000.00.
|
*/

beforeEach(function (): void {
    actingAsAdmin();

    $proyecto = Proyecto::factory()->create(['codigo' => 'RPS']);
    $bloque = Bloque::factory()->create(['proyecto_id' => $proyecto->getKey(), 'nombre' => 'A']);

    $this->cliente = Cliente::factory()->create(['nombre' => 'Leticia Romero']);

    $this->vender = function (?string $tasa) use ($bloque): void {
        $lote = Lote::factory()->enBloque($bloque)->conMedidas('250.0000', '1400.00')->create();

        $this->venta = app(RegistroDeVentas::class)->activar(
            proyecto: $lote->bloque->proyecto,
            lotes: [$lote],
            clientes: [$this->cliente],
            prima: new Monto('50000.00'),
            plazoMeses: 12,
            diaPago: 5,
            precios: [new PrecioPactado(
                loteId: (int) $lote->getKey(),
                precioVara: new Monto('1400.00'),
                plazoMeses: 12,
                prima: new Monto('50000.00'),
                // Subir la tasa no pide motivo: cobrar más caro no hay que
                // justificarlo. Bajarla sí (R4), y eso vive en otro test.
                tasa: $tasa === null ? null : new TasaDeInteres($tasa),
            )],
        );

        $this->compromiso = $this->venta->compromisos()->firstOrFail();
    };

    $this->cuenta = fn (): EstadoDeCuenta => EstadoDeCuenta::de($this->venta->fresh() ?? $this->venta);
});

describe('El desglose de la cuota', function (): void {
    /*
    | 🔴 EL TEST QUE SOSTIENE TODO LO DEMÁS.
    |
    | El papel saca el desglose de la CUOTA y no de `aplicaciones_de_pago`,
    | porque cargar todos los recibos de todos los lotes para imprimir una
    | hoja no se paga. Eso vale por una sola razón: un pago parcial cubre
    | interés antes que capital (§8.5, mora → interés → capital).
    |
    | Si algún día se cambia ese orden, la derivación queda muda y el papel
    | empieza a mentir sin que nada falle. Este test lo agarra ahí.
    */
    test('lo que la cuota dice haber cobrado de interés es lo que los recibos aplicaron', function (): void {
        ($this->vender)('12.000');

        // Un pago que NO cierra ninguna cuota: el caso donde la derivación
        // se puede equivocar. La cuota es de L 26,655.30 aprox.
        app(RegistroDePagos::class)->cobrarCuotas(
            venta: $this->venta,
            lote: $this->compromiso,
            cliente: $this->cliente,
            monto: new Monto('10000.00'),
            forma: FormaDePago::Efectivo,
        );

        $lote = ($this->cuenta)()->lotes[0];

        $ids = [];

        foreach ($lote->cuotas as $cuota) {
            $ids[] = $cuota->getKey();
        }

        $porRecibos = Monto::cero();

        foreach (AplicacionDePago::query()->whereIn('cuota_id', $ids)->get() as $aplicacion) {
            $porRecibos = $porRecibos->sumar($aplicacion->montoInteres());
        }

        expect($lote->interesPagado->redondeado())->toBe($porRecibos->redondeado())
            // Y no es cero: un desglose que compara dos ceros no prueba nada.
            ->and($lote->interesPagado->esCero())->toBeFalse()
            // Capital + interés cobrado tiene que ser lo entregado.
            ->and($lote->interesPagado->sumar($lote->capitalPagado)->redondeado())->toBe('10000.00');
    });

    test('el interés del plan y el capital suman lo que dicen las cuotas', function (): void {
        ($this->vender)('12.000');

        $lote = ($this->cuenta)()->lotes[0];

        $porCuotas = Monto::cero();

        foreach ($lote->cuotas as $cuota) {
            $porCuotas = $porCuotas->sumar($cuota->montoTotal());
        }

        // Capital financiado + interés = la escalera completa. Es la cuenta
        // que el pie de la tabla imprime, y la que un cliente rehace.
        expect($lote->valor->restar($lote->prima)->sumar($lote->interes)->redondeado())
            ->toBe($porCuotas->redondeado())
            ->and($lote->interes->esCero())->toBeFalse();
    });

    test('sin interés el capital pagado es todo lo pagado, como antes', function (): void {
        ($this->vender)(null);

        app(RegistroDePagos::class)->cobrarCuotas(
            venta: $this->venta,
            lote: $this->compromiso,
            cliente: $this->cliente,
            monto: new Monto('25000.00'),
            forma: FormaDePago::Efectivo,
        );

        $lote = ($this->cuenta)()->lotes[0];

        expect($lote->llevaInteres)->toBeFalse()
            ->and($lote->interes->esCero())->toBeTrue()
            ->and($lote->capitalPagado->redondeado())->toBe($lote->pagado->redondeado());
    });
});

describe('El papel', function (): void {
    test('el estado de cuenta separa capital de interés cuando el plan cobra', function (): void {
        ($this->vender)('12.000');

        app(RegistroDePagos::class)->cobrarCuotas(
            venta: $this->venta,
            lote: $this->compromiso,
            cliente: $this->cliente,
            monto: new Monto('26655.30'),
            forma: FormaDePago::Efectivo,
        );

        $cuenta = ($this->cuenta)();

        $this->get(route('documentos.estado-de-cuenta', $this->venta))
            ->assertOk()
            ->assertSee('Capital', escape: false)
            ->assertSee('Interés', escape: false)
            // El párrafo que contesta la pregunta con palabras.
            ->assertSee('bajó el precio del terreno', escape: false)
            ->assertSee($cuenta->interesPagado->formateado(), escape: false);
    });

    /*
    | La misma decisión que en el cuadro de venta: una columna de ceros no es
    | información, es una casilla que hay que descartar con la vista.
    */
    test('y no las muestra cuando ningún plan cobra interés', function (): void {
        ($this->vender)(null);

        $this->get(route('documentos.estado-de-cuenta', $this->venta))
            ->assertOk()
            // El valor financiado sigue ahí: sin esto una página en blanco pasaría.
            ->assertSee('300,000.00', escape: false)
            ->assertDontSee('Interés', escape: false)
            ->assertDontSee('bajó el precio del terreno', escape: false);
    });

    test('el recibo dice en qué se convirtió el pago', function (): void {
        ($this->vender)('12.000');

        app(RegistroDePagos::class)->cobrarCuotas(
            venta: $this->venta,
            lote: $this->compromiso,
            cliente: $this->cliente,
            monto: new Monto('26655.30'),
            forma: FormaDePago::Efectivo,
        );

        /** @var Recibo $recibo */
        $recibo = Recibo::query()->latest('id')->firstOrFail();

        expect($recibo->interesDeCuotas()->esCero())->toBeFalse()
            ->and($recibo->interesDeCuotas()->sumar($recibo->capitalDeCuotas())->redondeado())
            ->toBe($recibo->montoAplicadoACuotas()->redondeado());

        $this->get(route('documentos.recibo', $recibo))
            ->assertOk()
            ->assertSee('De este pago:', escape: false)
            ->assertSee($recibo->interesDeCuotas()->formateado(), escape: false);
    });

    test('y no la dice cuando todo fue capital', function (): void {
        ($this->vender)(null);

        app(RegistroDePagos::class)->cobrarCuotas(
            venta: $this->venta,
            lote: $this->compromiso,
            cliente: $this->cliente,
            monto: new Monto('25000.00'),
            forma: FormaDePago::Efectivo,
        );

        /** @var Recibo $recibo */
        $recibo = Recibo::query()->latest('id')->firstOrFail();

        $this->get(route('documentos.recibo', $recibo))
            ->assertOk()
            ->assertSee('25,000.00', escape: false)
            ->assertDontSee('De este pago:', escape: false);
    });
});
