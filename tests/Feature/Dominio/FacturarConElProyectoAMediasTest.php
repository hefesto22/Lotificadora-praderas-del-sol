<?php

declare(strict_types=1);

use App\Domain\Facturacion\ConsumoDeFacturas;
use App\Domain\Facturacion\NumeroDeFactura;
use App\Models\Facturacion;
use App\Models\Proyecto;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| 🔴 El proyecto que llega a medias — 14-ago-2026
|--------------------------------------------------------------------------
| Encontrado en un ENSAYO EN PANTALLA, no por un test. Se cobró una cuota de
| El Bambú desde el listado de Ventas y el papel salió como recibo interno,
| sin factura y sin un solo mensaje. El correlativo del SAR no se consumió ni
| se salteó: la emisión simplemente no ocurrió.
|
| La causa: `VentasTable` carga el proyecto con `'proyecto:id,nombre,codigo'`
| —tres columnas, porque la tabla no necesita más— y sin `facturacion_id` el
| `belongsTo` de la facturación buscaba por una llave que no estaba en
| memoria. Devolvía null, y null quiere decir «este desarrollo no factura».
|
| Ningún test lo agarraba porque en un test la venta se carga entera. Estos
| tests cargan el proyecto A PROPÓSITO como lo carga una pantalla.
*/

beforeEach(function (): void {
    $this->facturacion = Facturacion::query()->create([
        'nombre'                 => 'INMOBILIARIA MAYA',
        'rtn'                    => '0801-1985-012345',
        'razon_social'           => 'INMOBILIARIA MAYA S. DE R.L.',
        'codigo_establecimiento' => '000',
        'codigo_punto_emision'   => '001',
        'codigo_documento'       => '01',
    ]);

    $this->facturacion->autorizaciones()->create([
        'cai'                  => 'A1B2C3-D4E5F6-A7B8C9-D0E1F2-A3B4C5-D6',
        'correlativo_desde'    => 1,
        'correlativo_hasta'    => 1000,
        'proximo_correlativo'  => 1,
        'autorizada_el'        => today()->subMonth(),
        'fecha_limite_emision' => today()->addMonths(11),
    ]);

    $this->proyecto = Proyecto::factory()->create([
        'codigo'         => 'REB',
        'facturacion_id' => $this->facturacion->getKey(),
    ]);

    $this->consumo = app(ConsumoDeFacturas::class);
});

/*
| Las columnas son EXACTAMENTE las que pide `VentasTable`. Si alguien las
| cambia y este test sigue verde, es porque el dominio dejó de depender de
| ellas — que es justo lo que se quiso.
*/
test('factura igual cuando la pantalla trajo el proyecto sin facturacion_id', function (): void {
    $aMedias = Proyecto::query()
        ->select(['id', 'nombre', 'codigo'])
        ->findOrFail($this->proyecto->getKey());

    expect($aMedias->getAttribute('facturacion_id'))->toBeNull();

    $numero = DB::transaction(fn (): ?NumeroDeFactura => $this->consumo->paraElProyecto($aMedias));

    expect($numero)->toBeInstanceOf(NumeroDeFactura::class)
        ->and($numero?->numero)->toBe('000-001-01-00000001');
});

test('la previsualización ve lo mismo que va a ver la emisión', function (): void {
    $aMedias = Proyecto::query()
        ->select(['id', 'nombre'])
        ->findOrFail($this->proyecto->getKey());

    // Es lo que consulta el aviso «Papel que sale» del modal de cobro.
    expect($this->consumo->facturacionDe($aMedias))->toBeInstanceOf(Facturacion::class);
});

// Preguntar NO puede quemar un número: el modal se abre y se cierra a diario.
test('previsualizar no consume correlativo', function (): void {
    $aMedias = Proyecto::query()->select(['id'])->findOrFail($this->proyecto->getKey());

    $this->consumo->facturacionDe($aMedias);
    $this->consumo->facturacionDe($aMedias);

    expect((int) $this->facturacion->autorizacionVigente()?->getAttribute('proximo_correlativo'))->toBe(1);
});

describe('Lo que sigue devolviendo null, y está bien', function (): void {
    test('un desarrollo sin facturación elegida no factura', function (): void {
        $praderas = Proyecto::factory()->create(['codigo' => 'RPS']);

        expect($this->consumo->facturacionDe($praderas))->toBeNull();
    });

    test('una facturación apagada no factura', function (): void {
        $this->facturacion->update(['activa' => false]);

        $aMedias = Proyecto::query()->select(['id'])->findOrFail($this->proyecto->getKey());

        expect($this->consumo->facturacionDe($aMedias))->toBeNull();
    });

    test('sin proyecto no hay nada que preguntar', function (): void {
        expect($this->consumo->facturacionDe(null))->toBeNull();
    });
});
