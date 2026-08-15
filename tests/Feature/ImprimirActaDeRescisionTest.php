<?php

declare(strict_types=1);

use App\Domain\Enums\FormaDePago;
use App\Domain\ValueObjects\Monto;
use App\Domain\Ventas\PrecioPactado;
use App\Domain\Ventas\RegistroDeRescisiones;
use App\Domain\Ventas\RegistroDeVentas;
use App\Models\Bloque;
use App\Models\Cliente;
use App\Models\Facturacion;
use App\Models\Lote;
use App\Models\Proyecto;

/*
|--------------------------------------------------------------------------
| El acta de rescisión, y qué dice sobre lo fiscal — 14-ago-2026
|--------------------------------------------------------------------------
| «¿Y si factura pero no hacen notas de crédito?» — Mauricio.
|
| Tres respuestas, y ninguna traba la rescisión:
|
|   1. No factura (Praderas)              → el acta no dice nada de lo fiscal.
|   2. Factura y NO emite notas de crédito → aviso para el contador.
|   3. Factura y sí las emite              → aviso de que corresponde emitir.
|
| Y si no se devolvió nada, tampoco sale nada: no hubo qué acreditar.
|
| Los números de siempre: 250 vr² a L 1,400.00 son L 350,000.00; con
| L 50,000.00 de prima, a 12 meses, dan cuotas de L 25,000.00.
*/

beforeEach(function (): void {
    actingAsAdmin();

    $this->rescindir = function (?Facturacion $facturacion, string $devuelto, string $codigo) {
        $proyecto = Proyecto::factory()->create([
            'codigo'         => $codigo,
            'facturacion_id' => $facturacion?->getKey(),
        ]);

        $bloque = Bloque::factory()->create(['proyecto_id' => $proyecto->getKey(), 'nombre' => 'A']);
        $lote = Lote::factory()->enBloque($bloque)->conMedidas('250.0000', '1400.00')->create(['numero' => '1']);
        $cliente = Cliente::factory()->create(['nombre' => 'Leticia Romero']);

        $venta = app(RegistroDeVentas::class)->activar(
            proyecto: $proyecto,
            lotes: [$lote],
            clientes: [$cliente],
            prima: new Monto('50000.00'),
            plazoMeses: 12,
            diaPago: 5,
            precios: [new PrecioPactado(
                loteId: (int) $lote->getKey(),
                precioVara: new Monto('1400.00'),
                plazoMeses: 12,
                prima: new Monto('50000.00'),
            )],
        );

        return app(RegistroDeRescisiones::class)->rescindir(
            lote: $venta->compromisos()->firstOrFail(),
            devuelto: new Monto($devuelto),
            forma: FormaDePago::Efectivo,
            motivo: 'Ya no quiere el lote.',
        );
    };

    /*
    | ⚠️ La autorización NO es decorado del fixture. Una facturación ACTIVA sin
    | autorización vigente hace que `ConsumoDeFacturas` se plante al cobrar la
    | prima, así que la venta ni siquiera llega a existir y el test se cae en
    | el `beforeEach` con un `FacturacionInvalidaException` que no tiene nada
    | que ver con lo que se está probando. Y además es lo fiel al caso: el
    | lote se vendió CON factura, y por eso después hay algo que acreditar.
    */
    $this->conCai = static function (bool $emiteNotas): Facturacion {
        $facturacion = Facturacion::query()->create([
            'nombre'                 => 'INMOBILIARIA MAYA',
            'rtn'                    => '0801-1985-012345',
            'razon_social'           => 'INMOBILIARIA MAYA S. DE R.L.',
            'codigo_establecimiento' => '000',
            'codigo_punto_emision'   => '001',
            'codigo_documento'       => '01',
            'emite_notas_credito'    => $emiteNotas,
        ]);

        $facturacion->autorizaciones()->create([
            'cai'                  => 'A1B2C3-D4E5F6-A7B8C9-D0E1F2-A3B4C5-D6',
            'correlativo_desde'    => 1,
            'correlativo_hasta'    => 1000,
            'proximo_correlativo'  => 1,
            'autorizada_el'        => today()->subMonth(),
            'fecha_limite_emision' => today()->addMonths(11),
        ]);

        return $facturacion;
    };
});

test('el acta sale con los tres montos y el motivo', function (): void {
    $acta = ($this->rescindir)(null, '20000.00', 'RPS');

    $this->get(route('documentos.devolucion', $acta))
        ->assertOk()
        ->assertSee('ACTA DE RESCISIÓN Y LIQUIDACIÓN')
        ->assertSee('L. 50,000.00')   // entró
        ->assertSee('L. 20,000.00')   // se devolvió
        ->assertSee('L. 30,000.00')   // quedó retenido
        ->assertSee('Retenido por la lotificadora')
        ->assertSee('Ya no quiere el lote.');
});

/*
| Praderas del Sol: recibo interno, no está afiliada al SAR. Un recibo interno
| NO es un documento fiscal —lo dice el propio papel— así que no hay nada que
| acreditar y meter una nota sobre el SAR sería ruido.
*/
test('un desarrollo que no factura no dice nada de lo fiscal', function (): void {
    $acta = ($this->rescindir)(null, '20000.00', 'RPS');

    $this->get(route('documentos.devolucion', $acta))
        ->assertOk()
        ->assertDontSee('Para el contador')
        ->assertDontSee('nota de crédito');
});

// La pregunta de Mauricio: el caso NORMAL, y no traba nada.
test('factura pero no emite notas de crédito: el acta avisa al contador', function (): void {
    $acta = ($this->rescindir)(($this->conCai)(false), '20000.00', 'REB');

    $this->get(route('documentos.devolucion', $acta))
        ->assertOk()
        ->assertSee('Para el contador')
        ->assertSee('no tiene habilitadas las notas de')
        ->assertSee('Las facturas ya emitidas no se anulan.');
});

test('si emite notas de crédito, el acta dice que corresponde una', function (): void {
    $acta = ($this->rescindir)(($this->conCai)(true), '20000.00', 'REB');

    $this->get(route('documentos.devolucion', $acta))
        ->assertOk()
        ->assertSee('Para el contador')
        ->assertSee('corresponde emitir una nota de crédito');
});

/*
| Sin devolución no hay nada que acreditar, aunque el desarrollo facture. Es
| justo el caso de la rescisión por incumplimiento, donde se retiene todo.
*/
test('si no se devolvió nada, no hay aviso fiscal', function (): void {
    $acta = ($this->rescindir)(($this->conCai)(false), '0.00', 'REB');

    $this->get(route('documentos.devolucion', $acta))
        ->assertOk()
        ->assertSee('ACTA DE RESCISIÓN Y LIQUIDACIÓN')
        ->assertDontSee('Para el contador');
});
