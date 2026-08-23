<?php

declare(strict_types=1);

use App\Domain\Enums\ConceptoDeRecibo;
use App\Domain\Enums\FormaDePago;
use App\Domain\ValueObjects\Monto;
use App\Domain\Ventas\RegistroDeVentas;
use App\Filament\Resources\Ventas\Pages\ViewVenta;
use App\Filament\Support\CobrarUnPago;
use App\Filament\Support\ModoDeCobro;
use App\Models\Bloque;
use App\Models\Cliente;
use App\Models\Cuota;
use App\Models\Lote;
use App\Models\Proyecto;
use App\Models\Recibo;
use App\Models\Venta;
use App\Support\Roles;
use Database\Seeders\RoleSeeder;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| El pronto pago desde la pantalla — 23-ago-2026
|--------------------------------------------------------------------------
| El dominio ya tiene los suyos en `Dominio/Pagos/ProntoPagoTest`. Estos son
| de la PANTALLA, que es otra cosa: un campo que Filament no deshidrata, un
| modo que se manda a mano en la petición.
|
| Dos lotes de 250 vr² a L 1,400.00, prima de L 100,000.00 a 12 meses: cada
| lote debe L 300,000.00 en doce cuotas de L 25,000.00.
|
| 🔴 EL TEST QUE JUSTIFICA EL ARCHIVO es el del permiso mandado a mano.
| `modo` es un campo del formulario, y un campo se falsifica: sin el chequeo
| del servidor, cualquiera con `Create:Recibo` perdonaría saldo.
*/

beforeEach(function (): void {
    actingAsAdmin();

    $proyecto = Proyecto::factory()->create(['codigo' => 'RPS']);
    $bloque = Bloque::factory()->create(['proyecto_id' => $proyecto->getKey(), 'nombre' => 'A']);

    $lote = static fn (string $numero): Lote => Lote::factory()->enBloque($bloque)
        ->conMedidas('250.0000', '1400.00')
        ->create(['numero' => $numero]);

    $this->cliente = Cliente::factory()->create(['nombre' => 'Leticia Romero']);

    $this->venta = app(RegistroDeVentas::class)->activar(
        proyecto: $proyecto,
        lotes: [$lote('1'), $lote('2')],
        clientes: [$this->cliente],
        prima: new Monto('100000.00'),
        plazoMeses: 12,
        diaPago: 5,
    );

    [$this->uno, $this->dos] = $this->venta->compromisos()->orderBy('id')->get()->all();

    $this->soloElDos = fn (string $descuento, array $mas = []): array => array_merge([
        'modo'                            => ModoDeCobro::ProntoPago->value,
        'saldar_'.$this->uno->getKey()    => false,
        'saldar_'.$this->dos->getKey()    => true,
        'descuento_'.$this->dos->getKey() => $descuento,
        'motivo'                          => 'El cliente cancela y pidió rebaja',
        'forma_pago'                      => FormaDePago::Efectivo->value,
        'fecha'                           => today()->toDateString(),
    ], $mas);

    $this->expediente = fn (): object => Livewire::test(
        ViewVenta::class,
        ['record' => $this->venta->getKey()],
    );
});

/**
 * El recibo del movimiento, salteando el de la prima que `activar()` ya emitió.
 */
function elReciboDelProntoPago(): ?Recibo
{
    return Recibo::query()
        ->where('concepto', '!=', ConceptoDeRecibo::Prima)
        ->latest('id')
        ->first();
}

test('el pronto pago entra por la pantalla y deja el lote en cero', function (): void {
    ($this->expediente)()
        ->callAction('cobrar', ($this->soloElDos)('50000.00'))
        ->assertHasNoActionErrors();

    $recibo = elReciboDelProntoPago();

    expect($recibo)->not->toBeNull()
        // 300,000 de saldo menos 50,000 de descuento.
        ->and($recibo?->montoTotal())->toBeMonto('250000.00')
        ->and($recibo?->capitalCondonado())->toBeMonto('50000.00');

    expect(Cuota::query()
        ->where('compromiso_id', $this->dos->getKey())
        ->whereColumn('monto_pagado', '<', 'monto')
        ->count())->toBe(0);
});

test('el lote que no se marcó sigue debiendo entero', function (): void {
    ($this->expediente)()
        ->callAction('cobrar', ($this->soloElDos)('50000.00'))
        ->assertHasNoActionErrors();

    expect(Cuota::query()
        ->where('compromiso_id', $this->uno->getKey())
        ->where('monto_pagado', '>', 0)
        ->count())->toBe(0);
});

/*
| Sin descuento el modo sigue sirviendo: es saldar el lote de una vez, sin
| tener que teclear el saldo a mano en «Abono a capital».
*/
test('sin descuento salda igual', function (): void {
    ($this->expediente)()
        ->callAction('cobrar', ($this->soloElDos)('0'))
        ->assertHasNoActionErrors();

    expect(elReciboDelProntoPago()?->montoTotal())->toBeMonto('300000.00');
});

test('el papel que se lleva el cliente dice el descuento', function (): void {
    ($this->expediente)()
        ->callAction('cobrar', ($this->soloElDos)('50000.00'))
        ->assertHasNoActionErrors();

    $recibo = elReciboDelProntoPago();

    $this->get(route('documentos.recibo', $recibo))
        ->assertOk()
        // El total sigue siendo lo que entró en caja…
        ->assertSee('250,000.00')
        // …y el descuento se dice aparte, que es lo único que deja escrito
        // lo que se acordó de palabra.
        ->assertSee('por pronto pago');
});

describe('Quién puede descontar', function (): void {
    /*
    | Contra la matriz REAL del `RoleSeeder`, no contra un rol inventado a
    | mano: un rol armado en el test no tiene el permiso nuevo por
    | construcción, así que pasaría siempre.
    */
    test('la administradora sí; el receptor no', function (): void {
        $this->seed(RoleSeeder::class);

        $this->actingAs(crearUsuarioConRol(Roles::ADMINISTRADORA));
        expect(auth()->user()?->can('prontoPago', Venta::class))->toBeTrue();

        $this->actingAs(crearUsuarioConRol(Roles::RECEPTOR));
        expect(auth()->user()?->can('prontoPago', Venta::class))->toBeFalse();
    });

    /*
    | 🔴 EL BORDE DE VERDAD.
    |
    | El receptor SÍ puede abrir este modal y cobrar —es su trabajo— pero
    | `modo` es un campo, y un campo se falsifica. Acá se manda
    | `modo=pronto_pago` en la petición, como lo haría cualquiera con las
    | herramientas del navegador abiertas.
    |
    | ⚠️ Y NO se afirma «no hay recibo», aunque sea lo que uno escribiría
    | primero. Con una sola opción el toggle de modos NO SE DIBUJA, así que
    | Filament no lo deshidrata y `modoDe()` cae en «Cuota» — el fallback que
    | ese usuario sí puede—. Encima `callAction()` MERGEA sobre lo que dejó
    | `fillForm`, donde los dos lotes ya vienen marcados con su cuota del mes:
    | el movimiento entra igual, como cobro de cuotas. Y está bien que entre.
    |
    | Lo que se exige es lo único que importa: que no se haya perdonado un
    | centavo y que el lote NO haya quedado saldado.
    */
    test('mandar el modo a mano no perdona un centavo', function (): void {
        $this->seed(RoleSeeder::class);
        $this->actingAs(crearUsuarioConRol(Roles::RECEPTOR));

        ($this->expediente)()
            ->callAction('cobrar', ($this->soloElDos)('50000.00'))
            ->assertHasNoActionErrors();

        expect(Cuota::query()->where('capital_condonado', '>', 0)->count())->toBe(0);

        expect(Cuota::query()
            ->where('compromiso_id', $this->dos->getKey())
            ->whereColumn('monto_pagado', '<', 'monto')
            ->count())->toBeGreaterThan(0);
    });
});

/**
 * Un lote marcado, como se lo pasa el modal a la cuenta.
 *
 * @return array{codigo: string, saldo: Monto, descuento: Monto}
 */
function unLoteQueSeSalda(string $codigo, string $saldo, string $descuento): array
{
    return [
        'codigo'    => $codigo,
        'saldo'     => new Monto($saldo),
        'descuento' => new Monto($descuento),
    ];
}

/*
|--------------------------------------------------------------------------
| El renglón que se dice en voz alta — 23-ago-2026
|--------------------------------------------------------------------------
| 🔴 DE DONDE SALIO: de abrir el navegador, no de correr los tests. Con los
| dos lotes marcados y el descuento del primero pasado del saldo, el modal
| decía en negrita «El cliente entrega L 307,000.00» —el saldo del OTRO
| lote— y avisaba en letra chica que el pago se iba a rechazar. Los dos
| renglones eran ciertos por separado y juntos mentían: el Service rechaza
| el movimiento ENTERO, así que esa plata no la iba a cobrar nadie.
|
| ⚠️ 🔴 Y NO SE PRUEBA MONTANDO EL MODAL. El primer intento fue
| `mountAction()` + `assertSee()` sobre el contenido del modal: los tres
| tests fallaron, los tres en su primera aserción, porque el `html()` del
| test no trae nada de ese modal.
|
| 🔴🔴 Y peor: `assertDontSee()` ahí da VERDE EN FALSO por el mismo motivo.
| Un test que no puede fallar es peor que no tenerlo.
|
| (Dos candidatos verificados en el vendor, sin llegar a aislar cuál manda:
| `mountAction()` —a diferencia de `callAction()`— NO comprueba que la
| acción se haya montado, y `SubsequentRender.php:65` reusa el HTML
| ANTERIOR cuando un render no devuelve `effects['html']`, que es justo lo
| que deja el `skipRender()` de `fillFormDataForTesting`.)
|
| Por eso la cuenta salió del HTML: `CobrarUnPago::resumenDeProntoPago()` es
| pública, estática y no toca ni la base ni Filament. Se prueba llamándola,
| corre en milisegundos y sobrevive al próximo upgrade del panel. Ver
| [[el-resumen-en-vivo-del-modal]].
*/
describe('Cuánto tiene que entregar', function (): void {
    test('el total sale con el descuento ya restado', function (): void {
        $resumen = CobrarUnPago::resumenDeProntoPago([
            unLoteQueSeSalda('RPS-A-001', '300000.00', '50000.00'),
            unLoteQueSeSalda('RPS-A-002', '300000.00', '0.00'),
        ]);

        expect($resumen['avisos'])->toBe([])
            ->and($resumen['renglones'][0]['entrega'])->toBeMonto('250000.00')
            ->and($resumen['renglones'][1]['entrega'])->toBeMonto('300000.00')
            ->and($resumen['total'])->toBeMonto('550000.00');
    });

    test('sin descuento el total es el saldo', function (): void {
        $resumen = CobrarUnPago::resumenDeProntoPago([
            unLoteQueSeSalda('RPS-A-001', '300000.00', '0.00'),
        ]);

        expect($resumen['total'])->toBeMonto('300000.00');
    });

    /*
    | 🔴 EL QUE JUSTIFICA EL BLOQUE.
    |
    | El lote sano sigue apareciendo —quien atiende tiene que ver cuál está
    | bien y cuál no— pero el TOTAL se va: es el único renglón que se dice en
    | voz alta, y con el movimiento rechazado entero no hay nada que decir.
    */
    test('si un descuento se pasa del saldo no hay total', function (): void {
        $resumen = CobrarUnPago::resumenDeProntoPago([
            unLoteQueSeSalda('RPS-A-001', '300000.00', '400000.00'),
            unLoteQueSeSalda('RPS-A-002', '300000.00', '50000.00'),
        ]);

        expect($resumen['total'])->toBeNull()
            ->and($resumen['avisos'])->toHaveCount(1)
            ->and($resumen['avisos'][0])->toContain('RPS-A-001')
            // El aviso dice CUANTO debe: sin eso no se sabe qué corregir.
            ->and($resumen['avisos'][0])->toContain('300,000.00')
            ->and($resumen['renglones'])->toHaveCount(1)
            ->and($resumen['renglones'][0]['codigo'])->toBe('RPS-A-002');
    });

    /*
    | Un descuento IGUAL al saldo no es un error: es perdonarle el lote
    | entero. Lo rechaza el Service por otro motivo —una donación disfrazada—
    | y no le toca a este resumen decirlo.
    */
    test('un descuento igual al saldo no es un aviso', function (): void {
        $resumen = CobrarUnPago::resumenDeProntoPago([
            unLoteQueSeSalda('RPS-A-001', '300000.00', '300000.00'),
        ]);

        expect($resumen['avisos'])->toBe([])
            ->and($resumen['total'])->toBeMonto('0.00');
    });

    test('sin lotes marcados no hay renglones ni avisos', function (): void {
        $resumen = CobrarUnPago::resumenDeProntoPago([]);

        expect($resumen['renglones'])->toBe([])
            ->and($resumen['avisos'])->toBe([]);
    });
});
