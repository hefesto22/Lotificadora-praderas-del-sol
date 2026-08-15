<?php

declare(strict_types=1);

use App\Domain\Enums\ConceptoDeRecibo;
use App\Domain\Enums\FormaDePago;
use App\Domain\Enums\TipoDocumento;
use App\Domain\Exceptions\FacturacionInvalidaException;
use App\Domain\Facturacion\ConsumoDeFacturas;
use App\Domain\Facturacion\NumeroDeFactura;
use App\Domain\ValueObjects\Monto;
use App\Domain\Ventas\RegistroDeCompromisos;
use App\Domain\Ventas\RegistroDeVentas;
use App\Models\AutorizacionDeImpresion;
use App\Models\Bloque;
use App\Models\Cliente;
use App\Models\Facturacion;
use App\Models\Lote;
use App\Models\Proyecto;
use App\Models\Recibo;
use App\Models\Venta;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| La factura con CAI toma el rango — 14-ago-2026
|--------------------------------------------------------------------------
| Mauricio firmó una venta en El Bambú —vinculado a la facturación de la
| inmobiliaria— y el papel salió como RECIBO DE CUOTA N.º 000207 mientras
| la autorización seguía en 00000001 con 1000 facturas disponibles. Su
| mensaje: «no tomó el rango de facturas».
|
| Tenía razón. El 13-ago se construyó la CONFIGURACIÓN y la emisión quedó
| deliberadamente afuera. Esto es la emisión.
|
| 🔴 Lo que estos tests cuidan, en orden de importancia:
|
|  1. Que el número del SAR no se repita ni se saltee.
|  2. Que la CAI, el rango y la fecha límite queden CONGELADOS en el
|     recibo: una copia tiene que salir como salió el original.
|  3. Que el correlativo interno se consuma IGUAL. Una factura consume las
|     dos series: la del SAR y la de la caja (R12).
|  4. Que cuando no se pueda facturar, no salga un recibo interno en
|     silencio.
*/

beforeEach(function (): void {
    $this->ventas = app(RegistroDeVentas::class);
    $this->compromisos = app(RegistroDeCompromisos::class);

    $this->facturacion = Facturacion::query()->create([
        'nombre'                    => 'INMOBILIARIA MAYA',
        'rtn'                       => '0801-1985-012345',
        'razon_social'              => 'INMOBILIARIA MAYA S. DE R.L.',
        'nombre_comercial'          => 'URBANIZACION EL BAMBU',
        'direccion_establecimiento' => 'MEDIA CUADRA ARRIBA DE LA GASOLINERA',
        'codigo_establecimiento'    => '000',
        'codigo_punto_emision'      => '001',
        'codigo_documento'          => '01',
    ]);

    $this->autorizar = fn (array $mas = []): AutorizacionDeImpresion => $this->facturacion
        ->autorizaciones()
        ->create(array_merge([
            'cai'                  => 'A1B2C3-D4E5F6-A7B8C9-D0E1F2-A3B4C5-D6',
            'correlativo_desde'    => 1,
            'correlativo_hasta'    => 1000,
            'proximo_correlativo'  => 1,
            'autorizada_el'        => today()->subMonth(),
            'fecha_limite_emision' => today()->addMonths(11),
        ], $mas));

    $this->proyecto = Proyecto::factory()->create(['codigo' => 'REB']);
    $this->bloque = Bloque::factory()->create([
        'proyecto_id' => $this->proyecto->getKey(),
        'nombre'      => 'A',
    ]);
    $this->cliente = Cliente::factory()->create(['nombre' => 'LETICIA ROMERO']);

    // 250 vr² x L 1,400.00 = L 350,000.00.
    $this->lote = fn (string $numero): Lote => Lote::factory()->enBloque($this->bloque)
        ->conMedidas('250.0000', '1400.00')
        ->create(['numero' => $numero]);

    $this->conFacturacion = function (): void {
        $this->proyecto->update(['facturacion_id' => $this->facturacion->getKey()]);
        $this->proyecto->refresh();
    };

    $this->firmar = fn (Lote $lote, string $prima = '100000.00'): Venta => $this->ventas->activar(
        proyecto: $this->proyecto,
        lotes: [$lote],
        clientes: [$this->cliente],
        prima: new Monto($prima),
        plazoMeses: 12,
        diaPago: 5,
        formaPrima: FormaDePago::Efectivo,
    );

    $this->deConcepto = fn (ConceptoDeRecibo $concepto): ?Recibo => Recibo::query()
        ->where('concepto', $concepto)
        ->latest('id')
        ->first();
});

describe('Sin facturación, todo sigue igual', function (): void {
    /*
    | El desarrollo que solo emite comprobante de caja no se entera de que
    | esto existe. Es la mitad del sistema que estaba en producción y la que
    | no se puede romper.
    */
    test('el proyecto sin facturación emite recibo interno', function (): void {
        ($this->firmar)(($this->lote)('1'));

        $recibo = ($this->deConcepto)(ConceptoDeRecibo::Prima);

        expect($recibo?->tipoDeDocumento())->toBe(TipoDocumento::ReciboInterno)
            ->and($recibo?->esFactura())->toBeFalse()
            ->and($recibo?->getAttribute('numero_factura'))->toBeNull()
            ->and($recibo?->getAttribute('cai'))->toBeNull()
            ->and($recibo?->numeroDelPapel())->toBe($recibo?->folio());
    });

    /*
    | `activa` es el interruptor de «esta facturación ya no emite». Apagarla
    | devuelve al desarrollo al recibo interno en vez de dejarlo sin poder
    | cobrar, que es lo que pide el texto de ayuda del toggle.
    */
    test('la facturación apagada devuelve al recibo interno', function (): void {
        ($this->autorizar)();
        ($this->conFacturacion)();
        $this->facturacion->update(['activa' => false]);

        ($this->firmar)(($this->lote)('1'));

        expect(($this->deConcepto)(ConceptoDeRecibo::Prima)?->esFactura())->toBeFalse();
    });
});

describe('Con CAI vigente, el papel es una factura', function (): void {
    test('la prima consume el primer número del rango', function (): void {
        $autorizacion = ($this->autorizar)();
        ($this->conFacturacion)();

        ($this->firmar)(($this->lote)('1'));

        $recibo = ($this->deConcepto)(ConceptoDeRecibo::Prima);

        expect($recibo?->esFactura())->toBeTrue()
            ->and($recibo?->getAttribute('numero_factura'))->toBe('000-001-01-00000001')
            ->and($recibo?->getAttribute('correlativo_factura'))->toBe(1)
            ->and($recibo?->numeroDelPapel())->toBe('000-001-01-00000001')
            ->and($recibo?->getAttribute('facturacion_id'))->toBe($this->facturacion->getKey())
            ->and($recibo?->getAttribute('autorizacion_id'))->toBe($autorizacion->getKey());

        expect($autorizacion->refresh()->getAttribute('proximo_correlativo'))->toBe(2)
            ->and($autorizacion->quedanDocumentos())->toBe(999);
    });

    /*
    | 🔴 LA PARTE QUE SE OLVIDA.
    |
    | Una factura consume las DOS series. El número interno es el que cuadra
    | la caja (R12) y saltearlo dejaría un hueco en la única serie que
    | promete no tenerlos.
    */
    test('la factura también se lleva su número interno', function (): void {
        ($this->autorizar)();
        ($this->conFacturacion)();

        ($this->firmar)(($this->lote)('1'));

        $recibo = ($this->deConcepto)(ConceptoDeRecibo::Prima);

        expect($recibo?->getAttribute('numero'))->toBe(1)
            ->and($recibo?->folio())->toBe('000001');
    });

    test('la CAI, el rango y la fecha límite quedan copiados en el papel', function (): void {
        $autorizacion = ($this->autorizar)();
        ($this->conFacturacion)();

        ($this->firmar)(($this->lote)('1'));

        $recibo = ($this->deConcepto)(ConceptoDeRecibo::Prima);

        expect($recibo?->getAttribute('cai'))->toBe('A1B2C3-D4E5F6-A7B8C9-D0E1F2-A3B4C5-D6')
            ->and($recibo?->rangoAutorizado())->toBe('00000001 al 00001000')
            ->and($recibo?->fecha_limite_emision?->toDateString())
            ->toBe($autorizacion->getAttribute('fecha_limite_emision')?->toDateString());
    });

    /*
    | Por esto se copian y no se leen: la copia de una factura de enero tiene
    | que salir con la CAI de enero, no con la de la autorización que esté
    | vigente el día que alguien la reimprima.
    */
    test('cambiar la autorización después no le mueve nada al papel', function (): void {
        $autorizacion = ($this->autorizar)();
        ($this->conFacturacion)();
        ($this->firmar)(($this->lote)('1'));

        $autorizacion->update(['cai' => 'ZZZZZZ-ZZZZZZ-ZZZZZZ-ZZZZZZ-ZZZZZZ-ZZ']);

        expect(($this->deConcepto)(ConceptoDeRecibo::Prima)?->getAttribute('cai'))
            ->toBe('A1B2C3-D4E5F6-A7B8C9-D0E1F2-A3B4C5-D6');
    });

    test('la seña del apartado también sale facturada', function (): void {
        ($this->autorizar)();
        ($this->conFacturacion)();

        $this->compromisos->apartar(
            lote: ($this->lote)('1'),
            cliente: $this->cliente,
            montoSenia: '5000.00',
            forma: FormaDePago::Efectivo,
        );

        expect(($this->deConcepto)(ConceptoDeRecibo::Senia)?->getAttribute('numero_factura'))
            ->toBe('000-001-01-00000001');
    });

    /*
    | Dos documentos seguidos toman números seguidos. Es la promesa entera
    | de una serie autorizada: sin huecos y sin repetidos.
    */
    test('dos cobros seguidos toman 1 y 2', function (): void {
        $autorizacion = ($this->autorizar)();
        ($this->conFacturacion)();

        $primero = ($this->lote)('1');

        $this->compromisos->apartar(
            lote: $primero,
            cliente: $this->cliente,
            montoSenia: '5000.00',
            forma: FormaDePago::Efectivo,
        );
        ($this->firmar)($primero->refresh());

        expect(Recibo::query()->orderBy('id')->pluck('numero_factura')->all())
            ->toBe(['000-001-01-00000001', '000-001-01-00000002'])
            ->and($autorizacion->refresh()->getAttribute('proximo_correlativo'))->toBe(3);
    });
});

describe('Cuando no hay con qué numerar, no sale el papel equivocado', function (): void {
    /*
    | 🔴 LA DECISIÓN MÁS DISCUTIBLE DE TODO ESTO, Y ESTÁ TOMADA A PROPÓSITO.
    |
    | El desarrollo tiene la facturación ENCENDIDA: alguien decidió que acá
    | se factura con CAI. Entregar un comprobante de caja porque el sistema
    | no encontró números es emitir el papel equivocado en silencio, y eso no
    | se descubre hasta que lo descubre el SAR.
    |
    | Plantarse duele treinta segundos y se arregla en la pantalla de
    | Facturación — y el aviso no llega de sorpresa: la autorización avisa
    | con dos meses y con cincuenta documentos de anticipación.
    */
    test('sin autorización cargada, el cobro no pasa', function (): void {
        ($this->conFacturacion)();

        expect(fn (): Venta => ($this->firmar)(($this->lote)('1')))
            ->toThrow(FacturacionInvalidaException::class);
    });

    test('con la CAI vencida tampoco', function (): void {
        ($this->autorizar)([
            'autorizada_el'        => today()->subYears(2),
            'fecha_limite_emision' => today()->subDay(),
        ]);
        ($this->conFacturacion)();

        expect(fn (): Venta => ($this->firmar)(($this->lote)('1')))
            ->toThrow(FacturacionInvalidaException::class);
    });

    test('con el rango agotado tampoco', function (): void {
        ($this->autorizar)(['proximo_correlativo' => 1001]);
        ($this->conFacturacion)();

        expect(fn (): Venta => ($this->firmar)(($this->lote)('1')))
            ->toThrow(FacturacionInvalidaException::class);
    });

    /*
    | Y no queda nada a medias: la venta entera se va con la excepción. Un
    | expediente creado sin su recibo sería peor que no poder cobrar.
    */
    test('la venta se cae entera y no queda a medias', function (): void {
        ($this->conFacturacion)();
        $lote = ($this->lote)('1');

        try {
            ($this->firmar)($lote);
        } catch (FacturacionInvalidaException) {
            // Es lo que se espera; lo que importa es lo de abajo.
        }

        expect(Venta::query()->count())->toBe(0)
            ->and(Recibo::query()->count())->toBe(0);
    });
});

describe('La que vence primero se gasta primero', function (): void {
    /*
    | Los correlativos que sobran al vencerse se pierden, así que se usa la
    | vieja antes de estrenar la nueva. Es lo mismo que hace
    | Facturacion::autorizacionVigente(), pero verificado desde adentro del
    | bloqueo de fila, que es donde de verdad se decide.
    */
    test('se numera con la autorización más próxima a vencer', function (): void {
        $nueva = ($this->autorizar)([
            'correlativo_desde'    => 2001,
            'correlativo_hasta'    => 3000,
            'proximo_correlativo'  => 2001,
            'fecha_limite_emision' => today()->addMonths(11),
        ]);
        $vieja = ($this->autorizar)([
            'correlativo_desde'    => 1001,
            'correlativo_hasta'    => 2000,
            'proximo_correlativo'  => 1001,
            'fecha_limite_emision' => today()->addMonth(),
        ]);
        ($this->conFacturacion)();

        ($this->firmar)(($this->lote)('1'));

        expect(($this->deConcepto)(ConceptoDeRecibo::Prima)?->getAttribute('correlativo_factura'))
            ->toBe(1001)
            ->and($vieja->refresh()->getAttribute('proximo_correlativo'))->toBe(1002)
            ->and($nueva->refresh()->getAttribute('proximo_correlativo'))->toBe(2001);
    });
});

describe('El guard de la transacción', function (): void {
    /*
    | Igual que ConsumoDeCorrelativos, y por lo mismo: `lockForUpdate()`
    | fuera de una transacción no bloquea nada —Postgres suelta el lock al
    | terminar la sentencia— y dos cobros simultáneos se llevarían el mismo
    | número de factura. El Service se planta en vez de confiar.
    */
    test('numerar fuera de una transacción está prohibido', function (): void {
        /*
         * `RefreshDatabase` deja cada test adentro de una transacción, así que
         * hay que salirse a mano para llegar al nivel 0 — mismo truco que en
         * ConsumoDeCorrelativosTest. Después de esto la base está vacía, y por
         * eso los dos modelos se arman EN MEMORIA: el guard corta antes de
         * tocar la base, así que no hace falta que existan.
         */
        DB::rollBack();

        expect(DB::transactionLevel())->toBe(0);

        $proyecto = Proyecto::factory()->make(['codigo' => 'REB']);
        $proyecto->setRelation('facturacion', new Facturacion(['nombre' => 'INMOBILIARIA MAYA']));

        expect(fn (): ?NumeroDeFactura => app(ConsumoDeFacturas::class)->paraElProyecto($proyecto))
            ->toThrow(FacturacionInvalidaException::class, 'fuera de una transaccion');
    });
});
