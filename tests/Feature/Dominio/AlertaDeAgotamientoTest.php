<?php

declare(strict_types=1);

use App\Domain\Facturacion\EstadoDelTalonario;
use App\Models\AutorizacionDeImpresion;
use App\Models\Facturacion;

/*
|--------------------------------------------------------------------------
| La alerta de agotamiento del talonario — 14-ago-2026
|--------------------------------------------------------------------------
| El contrato la pide POR NOMBRE: Cláusula Segunda, módulo g-ii, «control de
| talonario manual y alertas de agotamiento».
|
| Los dos umbrales no son inventados. Los 60 días son la ventana en la que el
| reglamento deja pedir la siguiente autorización —«dentro de los dos (2)
| meses previos a la fecha límite de emisión», Acuerdo 481-2017, Art. 59— y
| los 50 documentos son la holgura para no quedarse sin números mientras el
| trámite corre.
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
});

describe('Cuándo no se dice nada', function (): void {
    /*
    | Un aviso que aparece siempre se deja de leer, y el día que cambie de
    | color nadie lo va a notar.
    */
    test('un talonario con tiempo y números no avisa', function (): void {
        ($this->autorizar)();

        expect(EstadoDelTalonario::de($this->facturacion)->hayQueAvisar())->toBeFalse()
            ->and(EstadoDelTalonario::lasQueAvisan())->toBe([]);
    });

    // Apagada quiere decir «esta ya no emite»: su talonario no le importa a nadie.
    test('una facturación apagada no entra en la lista', function (): void {
        ($this->autorizar)(['correlativo_hasta' => 10, 'proximo_correlativo' => 10]);
        $this->facturacion->update(['activa' => false]);

        expect(EstadoDelTalonario::lasQueAvisan())->toBe([]);
    });
});

describe('Cuándo conviene renovar', function (): void {
    test('avisa cuando quedan pocos números', function (): void {
        // Del 1 al 1000, con el próximo en 990: quedan 11.
        ($this->autorizar)(['proximo_correlativo' => 990]);

        $estado = EstadoDelTalonario::de($this->facturacion);

        expect($estado->hayQueAvisar())->toBeTrue()
            ->and($estado->esUnParo())->toBeFalse()
            ->and($estado->documentos)->toBe(11)
            ->and($estado->titular())->toBe('Quedan 11 facturas')
            ->and($estado->color())->toBe('warning');
    });

    test('avisa cuando la CAI está por vencerse', function (): void {
        ($this->autorizar)(['fecha_limite_emision' => today()->addDays(30)]);

        $estado = EstadoDelTalonario::de($this->facturacion);

        expect($estado->hayQueAvisar())->toBeTrue()
            ->and($estado->esUnParo())->toBeFalse()
            ->and($estado->titular())->toBe('La CAI vence en 30 días');
    });

    /*
    | Entre el tiempo y los números manda el que llegue antes: es el que va a
    | cortar la emisión. «Vence en 45 días» con tres facturas encima sería
    | exacto e inútil.
    */
    test('manda lo que se acabe primero', function (): void {
        ($this->autorizar)([
            'proximo_correlativo'  => 998,
            'fecha_limite_emision' => today()->addDays(45),
        ]);

        expect(EstadoDelTalonario::de($this->facturacion)->titular())->toBe('Quedan 3 facturas');
    });
});

describe('Cuándo ya no es un aviso, es un paro', function (): void {
    test('sin números, la próxima venta se planta', function (): void {
        ($this->autorizar)(['correlativo_hasta' => 100, 'proximo_correlativo' => 101]);

        $estado = EstadoDelTalonario::de($this->facturacion);

        expect($estado->esUnParo())->toBeTrue()
            ->and($estado->titular())->toBe('No se puede facturar')
            ->and($estado->color())->toBe('danger')
            ->and($estado->detalle())->toContain('otra autorización al SAR');
    });

    test('con la CAI vencida, tampoco', function (): void {
        ($this->autorizar)([
            'autorizada_el'        => today()->subYear(),
            'fecha_limite_emision' => today()->subDay(),
        ]);

        expect(EstadoDelTalonario::de($this->facturacion)->esUnParo())->toBeTrue();
    });

    // Una facturación sin ninguna autorización cargada no puede emitir nada.
    test('sin ninguna autorización cargada, avisa igual', function (): void {
        $estado = EstadoDelTalonario::de($this->facturacion);

        expect($estado->esUnParo())->toBeTrue()
            ->and(EstadoDelTalonario::lasQueAvisan())->toHaveCount(1);
    });
});

/*
| Se usa la más vieja antes de estrenar la nueva —los correlativos que sobran
| al vencerse se pierden— así que el aviso habla de ESA, no de la que está
| guardada para el año que viene.
*/
test('con dos autorizaciones, el aviso habla de la que se está gastando', function (): void {
    ($this->autorizar)([
        'proximo_correlativo'  => 995,
        'fecha_limite_emision' => today()->addDays(20),
    ]);

    ($this->autorizar)([
        'cai'                  => 'B2C3D4-E5F6A7-B8C9D0-E1F2A3-B4C5D6-E7',
        'correlativo_desde'    => 1001,
        'correlativo_hasta'    => 2000,
        'proximo_correlativo'  => 1001,
        'fecha_limite_emision' => today()->addMonths(14),
    ]);

    expect(EstadoDelTalonario::de($this->facturacion)->titular())->toBe('Quedan 6 facturas');
});
