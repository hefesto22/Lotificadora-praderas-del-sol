<?php

declare(strict_types=1);

use App\Models\AutorizacionDeImpresion;
use App\Models\Facturacion;
use App\Models\Proyecto;
use Illuminate\Database\QueryException;

/*
|--------------------------------------------------------------------------
| Con qué papel cobra cada desarrollo
|--------------------------------------------------------------------------
| Pedido de Mauricio, 13-ago-2026: «cada proyecto debe tener facturación
| independiente, opción de factura con CAI o solo recibo como el del
| Corpus; y si son dos proyectos, que haya opción de que ambos compartan el
| mismo rango de facturación».
|
| 🔴 Compartir el rango es correcto SOLO cuando los dos emiten desde la
| misma oficina: el SAR autoriza por punto de emisión y el código del
| establecimiento va adentro del número (Acuerdo 481-2017, Arts. 10 y 59).
| El sistema lo permite porque el caso existe; que corresponda o no lo
| decide dónde se emite el papel, no dónde está el terreno.
|
| Sin factories a propósito: son dos modelos de configuración que se cargan
| una vez a mano, y una factory con datos falsos escondería que la mitad de
| las columnas solo son obligatorias en modo factura.
*/

beforeEach(function (): void {
    $this->conCai = static fn (string $nombre): Facturacion => Facturacion::query()->create([
        'nombre'                 => $nombre,
        'rtn'                    => '0801-1985-012345',
        'razon_social'           => 'INVERSIONES OLYMPO S. DE R.L.',
        'codigo_establecimiento' => '001',
        'codigo_punto_emision'   => '001',
        'codigo_documento'       => '01',
    ]);

    $this->autorizar = static fn (Facturacion $f, array $mas = []): AutorizacionDeImpresion => $f->autorizaciones()->create(array_merge([
        'cai'                  => 'ABC123-DEF456-GHI789-JKL012-MNO345-PQ',
        'correlativo_desde'    => 1,
        'correlativo_hasta'    => 500,
        'proximo_correlativo'  => 1,
        'autorizada_el'        => today()->subMonth(),
        'fecha_limite_emision' => today()->addMonths(10),
    ], $mas));
});

describe('Toda facturación es de CAI', function (): void {
    /*
    | El modo se fue el 14-ago-2026: una facturación SIEMPRE factura con
    | CAI, y el recibo interno es sencillamente no tener ninguna. Lo dice
    | el proyecto, no esta tabla.
    */
    test('sin autorización cargada todavía no puede emitir', function (): void {
        expect(($this->conCai)('EL BAMBU')->puedeEmitir())->toBeFalse();
    });

    /*
    | El CHECK de la base, no una regla del formulario: una factura sin RTN
    | ni establecimiento es un papel que no cumple el Art. 10.
    */
    test('sin RTN ni establecimiento no entra ni por tinker', function (): void {
        expect(fn (): Facturacion => Facturacion::query()->create([
            'nombre' => 'INCOMPLETA',
        ]))->toThrow(QueryException::class);
    });

    test('el RTN se guarda en dígitos limpios', function (): void {
        expect(($this->conCai)('EL BAMBU')->getAttribute('rtn'))->toBe('08011985012345');
    });
});

describe('El número de 16 dígitos', function (): void {
    /*
    | NNN-NNN-NN-NNNNNNNN (Art. 10, num. 7). Los ceros de adelante son
    | parte del número: el establecimiento 001 no es el 1.
    */
    test('se arma con sus cuatro segmentos y sus ceros', function (): void {
        expect(($this->conCai)('EL BAMBU')->numeroCompleto(7))->toBe('001-001-01-00000007');
    });

    test('el correlativo llena los ocho dígitos', function (): void {
        expect(($this->conCai)('EL BAMBU')->numeroCompleto(99999999))->toBe('001-001-01-99999999');
    });
});

describe('Las autorizaciones del SAR', function (): void {
    test('la vigente es la que sirve hoy', function (): void {
        $facturacion = ($this->conCai)('EL BAMBU');
        ($this->autorizar)($facturacion);

        $vigente = $facturacion->autorizacionVigente();

        expect($vigente)->not->toBeNull()
            ->and($vigente?->quedanDocumentos())->toBe(500)
            ->and($vigente?->sirveHoy())->toBeTrue()
            ->and($facturacion->puedeEmitir())->toBeTrue();
    });

    test('una vencida no sirve aunque le sobren números', function (): void {
        $facturacion = ($this->conCai)('EL BAMBU');
        $vieja = ($this->autorizar)($facturacion, [
            'autorizada_el'        => today()->subYears(2),
            'fecha_limite_emision' => today()->subDay(),
        ]);

        expect($vieja->estaVencida())->toBeTrue()
            ->and($vieja->quedanDocumentos())->toBe(500)
            ->and($vieja->sirveHoy())->toBeFalse()
            ->and($facturacion->autorizacionVigente())->toBeNull()
            ->and($facturacion->puedeEmitir())->toBeFalse();
    });

    test('una agotada no sirve aunque le sobre tiempo', function (): void {
        $facturacion = ($this->conCai)('EL BAMBU');
        $llena = ($this->autorizar)($facturacion, ['proximo_correlativo' => 501]);

        expect($llena->estaVencida())->toBeFalse()
            ->and($llena->quedanDocumentos())->toBe(0)
            ->and($llena->sirveHoy())->toBeFalse()
            ->and($facturacion->puedeEmitir())->toBeFalse();
    });

    /*
    | Dos meses de aviso, que es la ventana en la que el reglamento deja
    | pedir la siguiente (Art. 59).
    */
    test('avisa cuando conviene renovar, por tiempo o por números', function (): void {
        $facturacion = ($this->conCai)('EL BAMBU');

        $porTiempo = ($this->autorizar)($facturacion, ['fecha_limite_emision' => today()->addDays(30)]);
        $porNumeros = ($this->autorizar)($facturacion, ['proximo_correlativo' => 480]);
        $tranquila = ($this->autorizar)($facturacion);

        expect($porTiempo->convieneRenovar())->toBeTrue()
            ->and($porNumeros->convieneRenovar())->toBeTrue()
            ->and($tranquila->convieneRenovar())->toBeFalse();
    });

    /*
    | El rango de la base: `hasta` no puede ser menor que `desde`, y el
    | próximo tiene que caer adentro (o justo uno más, que es «agotada»).
    */
    test('un rango al revés no entra', function (): void {
        $facturacion = ($this->conCai)('EL BAMBU');

        expect(fn (): AutorizacionDeImpresion => ($this->autorizar)($facturacion, [
            'correlativo_desde' => 900,
            'correlativo_hasta' => 100,
        ]))->toThrow(QueryException::class);
    });
});

describe('Compartir el rango, o no', function (): void {
    /*
    | Lo que pidió Mauricio: dos desarrollos apuntando a la MISMA
    | facturación. Es correcto cuando los dos emiten desde la misma
    | oficina.
    */
    test('dos proyectos pueden apuntar a la misma facturación', function (): void {
        $facturacion = ($this->conCai)('OFICINA CENTRAL');

        $uno = Proyecto::factory()->create(['codigo' => 'BAM', 'facturacion_id' => $facturacion->getKey()]);
        $dos = Proyecto::factory()->create(['codigo' => 'ALT', 'facturacion_id' => $facturacion->getKey()]);

        expect($uno->facturacion?->getKey())->toBe($facturacion->getKey())
            ->and($dos->facturacion?->getKey())->toBe($facturacion->getKey())
            ->and($facturacion->proyectos()->count())->toBe(2)
            // Y por eso comparten el número: es el mismo punto de emisión.
            ->and($uno->facturacion?->numeroCompleto(1))->toBe($dos->facturacion?->numeroCompleto(1));
    });

    /*
    | Y el caso de Mauricio: El Bambú y Altamira emiten cada uno en su
    | localidad, así que van con una facturación cada uno y sus
    | establecimientos son distintos.
    */
    test('cada uno con la suya lleva establecimientos distintos', function (): void {
        $bambu = ($this->conCai)('EL BAMBU — TALANGA');
        $altamira = ($this->conCai)('ALTAMIRA');
        $altamira->update(['codigo_establecimiento' => '002']);

        expect($bambu->numeroCompleto(1))->toBe('001-001-01-00000001')
            ->and($altamira->refresh()->numeroCompleto(1))->toBe('002-001-01-00000001');
    });

    /*
    | Un proyecto sin facturación elegida sigue como hasta hoy. Es el
    | estado en que quedaron los que ya existían.
    */
    test('un proyecto sin facturación no se rompe', function (): void {
        expect(Proyecto::factory()->create(['codigo' => 'SIN'])->facturacion)->toBeNull();
    });

    /*
    | Borrar una facturación que alguien está usando no puede pasar
    | callado: la FK es restrictOnDelete como todas las de este repo.
    */
    test('no se puede borrar una facturación que un proyecto está usando', function (): void {
        $facturacion = ($this->conCai)('EN USO');
        Proyecto::factory()->create(['codigo' => 'USA', 'facturacion_id' => $facturacion->getKey()]);

        expect(fn (): ?bool => $facturacion->delete())
            ->toThrow(QueryException::class);
    });
});
