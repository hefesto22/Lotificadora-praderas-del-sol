<?php

declare(strict_types=1);

use App\Domain\Enums\EstadoLote;
use App\Domain\Exceptions\GeneracionDeLotesException;
use App\Domain\Exceptions\ValueObjectInvalidoException;
use App\Domain\Plano\Dxf\ImportadorDeDxf;
use App\Domain\Plano\Dxf\OpcionesDeImportacion;
use App\Domain\Plano\Dxf\ResultadoDeImportacion;
use App\Domain\Plano\Dxf\UnidadDxf;
use App\Models\Bloque;
use App\Models\Calle;
use App\Models\Lote;
use App\Models\Proyecto;

beforeEach(function (): void {
    $this->proyecto = Proyecto::factory()->create(['codigo' => 'VV']);
    $this->bloque = Bloque::factory()->create([
        'proyecto_id' => $this->proyecto->getKey(),
        'nombre'      => 'A',
    ]);
    $this->dxf = (string) file_get_contents(base_path('tests/Fixtures/valle-verde.dxf'));
});

/**
 * Opciones de prueba para el plano de Valle Verde.
 *
 * Todo tipado y nada de `array $extra`: hacer spread de un array suelto
 * dentro de un constructor tipado es justo lo que PHPStan nivel 7 no puede
 * verificar, y estos tests tambien se analizan.
 */
function opcionesDeImportacion(
    string $capaDeLotes = 'LOTES',
    string $precioVara = '1200.00',
    ?string $capaDeRotulos = 'TEXTOS',
    ?string $capaDeCalles = 'CALLES',
    UnidadDxf $unidad = UnidadDxf::Metros,
    bool $dibujadoEnVaras = false,
    bool $bloquePorRotulo = false,
): OpcionesDeImportacion {
    return new OpcionesDeImportacion(
        capaDeLotes: $capaDeLotes,
        precioVara: $precioVara,
        capaDeRotulos: $capaDeRotulos,
        capaDeCalles: $capaDeCalles,
        unidad: $unidad,
        dibujadoEnVaras: $dibujadoEnVaras,
        bloquePorRotulo: $bloquePorRotulo,
    );
}

/**
 * Un plano chico con la manzana pegada al numero, como lo rotula el
 * topografo de EL BAMBU: A1, A2, B1, B2, C1.
 *
 * Adentro de cada lote va, ADEMAS del numero, el area rotulada — y va
 * EXACTAMENTE en el centro, que es donde estaba la trampa: gana el rotulo
 * mas cercano al centro, asi que con la lectura vieja "A=200.00m2" le
 * robaba el numero al lote y el lote terminaba llamandose "00".
 */
function dxfDeManzanas(): string
{
    /** @var list<array{int, string}> $tags */
    $tags = [
        [0, 'SECTION'], [2, 'HEADER'],
        [9, '$INSUNITS'], [70, '6'],
        [0, 'ENDSEC'],
        [0, 'SECTION'], [2, 'ENTITIES'],
    ];

    foreach (['A1', 'A2', 'B1', 'B2', 'C1'] as $i => $etiqueta) {
        $tags = [...$tags, ...tagsDeManzana($etiqueta, $i * 15.0)];
    }

    $tags[] = [0, 'ENDSEC'];
    $tags[] = [0, 'EOF'];

    $lineas = [];

    foreach ($tags as [$codigo, $valor]) {
        $lineas[] = str_pad((string) $codigo, 3, ' ', STR_PAD_LEFT);
        $lineas[] = $valor;
    }

    return implode("\r\n", $lineas)."\r\n";
}

/**
 * Un lote de 10 x 20 m con su area al centro y su numero mas arriba.
 *
 * @return list<array{int, string}>
 */
function tagsDeManzana(string $etiqueta, float $x): array
{
    /** @var list<array{int, string}> $tags */
    $tags = [
        [0, 'LWPOLYLINE'], [100, 'AcDbEntity'], [8, 'LOTES'], [100, 'AcDbPolyline'],
        [90, '4'], [70, '1'],
    ];

    foreach ([[$x, 0.0], [$x + 10.0, 0.0], [$x + 10.0, 20.0], [$x, 20.0]] as [$px, $py]) {
        $tags[] = [10, (string) $px];
        $tags[] = [20, (string) $py];
    }

    return [
        ...$tags,
        [0, 'TEXT'], [100, 'AcDbEntity'], [8, 'TEXTOS'], [100, 'AcDbText'],
        [10, (string) ($x + 5.0)], [20, '10.0'], [40, '1.0'], [1, 'A=200.00m2'],
        [0, 'MTEXT'], [100, 'AcDbEntity'], [8, 'TEXTOS'], [100, 'AcDbMText'],
        [10, (string) ($x + 5.0)], [20, '13.0'], [40, '1.0'], [1, $etiqueta],
    ];
}

describe('Importador — analisis previo', function (): void {
    /*
    | El analisis existe para que importar sea un paso con vista previa y
    | no una apuesta: el usuario ve que hay en cada capa ANTES de crear 78
    | lotes en su base de produccion.
    */
    test('detecta la unidad y cuenta lo que hay en cada capa', function (): void {
        $analisis = new ImportadorDeDxf()->analizar($this->dxf);

        expect($analisis->unidadDeclarada)->toBe(UnidadDxf::Metros)
            ->and($analisis->capas['LOTES']['contornos'])->toBe(78)
            ->and($analisis->capas['CALLES']['contornos'])->toBe(4)
            ->and($analisis->capas['TEXTOS']['rotulos'])->toBe(78)
            ->and($analisis->bloquesInsertados)->toBe(0);
    });

    test('adivina que capa es cual por su nombre', function (): void {
        $analisis = new ImportadorDeDxf()->analizar($this->dxf);

        expect($analisis->capaSugeridaDeLotes())->toBe('LOTES')
            ->and($analisis->capaSugeridaDeCalles())->toBe('CALLES')
            ->and($analisis->capaSugeridaDeRotulos())->toBe('TEXTOS');
    });
});

describe('Importador — creacion de lotes', function (): void {
    test('crea un lote por contorno, numerado por su rotulo', function (): void {
        $resultado = new ImportadorDeDxf()->importar($this->bloque, $this->dxf, opcionesDeImportacion());

        $numeros = Lote::query()->orderBy('codigo')->pluck('numero')->all();

        expect($resultado->lotesCreados)->toBe(78)
            ->and($resultado->sinRotulo)->toBe(0)
            ->and($numeros)->toBe(array_map(strval(...), range(1, 78)));
    });

    test('el codigo se deriva solo del proyecto y el bloque', function (): void {
        new ImportadorDeDxf()->importar($this->bloque, $this->dxf, opcionesDeImportacion());

        expect(Lote::query()->where('numero', '7')->firstOrFail()->getAttribute('codigo'))->toBe('VV-A-007');
    });

    /*
    | Un lote de 10 x 20 metros son 200 m². Con la vara castellana de
    | 0.8359 m, eso son 200 / 0.8359² = 286.23 varas². Si este test se
    | pone rojo, o cambio el factor de la vara o se rompio la conversion —
    | y de ese numero sale el valor del lote.
    */
    test('las areas quedan convertidas a varas cuadradas', function (): void {
        new ImportadorDeDxf()->importar($this->bloque, $this->dxf, opcionesDeImportacion());

        $area = (float) (string) Lote::query()->where('numero', '5')->firstOrFail()->getAttribute('area_varas');

        expect($area)->toBeGreaterThan(286.20)->toBeLessThan(286.30);
    });

    test('un plano dibujado en varas no se convierte', function (): void {
        new ImportadorDeDxf()->importar($this->bloque, $this->dxf, opcionesDeImportacion(dibujadoEnVaras: true));

        $area = (float) (string) Lote::query()->where('numero', '5')->firstOrFail()->getAttribute('area_varas');

        expect($area)->toBeGreaterThan(199.99)->toBeLessThan(200.01);
    });

    test('el valor sale del area importada por el precio elegido', function (): void {
        new ImportadorDeDxf()->importar($this->bloque, $this->dxf, opcionesDeImportacion(precioVara: '1000.00', dibujadoEnVaras: true));

        $lote = Lote::query()->where('numero', '5')->firstOrFail();

        expect($lote->getAttribute('valor'))->toBe('200000.00')
            ->and($lote->getAttribute('estado'))->toBe(EstadoLote::Disponible);
    });

    /*
    | En CAD la Y crece hacia el norte; en SVG crece hacia abajo. Sin
    | invertirla, el plano queda reflejado y el lote de la esquina noreste
    | aparece en la sureste.
    */
    test('el eje Y queda invertido y el dibujo arranca en el origen', function (): void {
        new ImportadorDeDxf()->importar($this->bloque, $this->dxf, opcionesDeImportacion());

        $todos = [];

        foreach (Lote::query()->get() as $lote) {
            foreach ($lote->verticesPoligono() as $vertice) {
                $todos[] = $vertice;
            }
        }

        /** @var non-empty-list<float> $xs */
        $xs = array_map(static fn (array $p): float => $p[0], $todos);
        /** @var non-empty-list<float> $ys */
        $ys = array_map(static fn (array $p): float => $p[1], $todos);

        expect(min($xs))->toBeGreaterThanOrEqual(0.0)
            ->and(min($ys))->toBeGreaterThanOrEqual(0.0);

        // El lote 1 esta al SUR del plano en CAD, asi que en el SVG tiene
        // que quedar ABAJO: con la mayor de las Y.
        $uno = Lote::query()->where('numero', '1')->firstOrFail();
        /** @var non-empty-list<float> $suyas */
        $suyas = array_map(static fn (array $p): float => $p[1], $uno->verticesPoligono());
        $suY = max($suyas);

        expect($suY)->toBeGreaterThan(max($ys) * 0.9);
    });

    test('ningun lote importado nace desalineado', function (): void {
        new ImportadorDeDxf()->importar($this->bloque, $this->dxf, opcionesDeImportacion());

        foreach (Lote::query()->get() as $lote) {
            expect($lote->poligonoDesalineado())->toBeFalse();
        }
    });
});

describe('Importador — un plano de varias manzanas, de una sola vez', function (): void {
    /*
    | Por que de una sola vez y no un archivo por manzana: la
    | transformacion al origen se calcula sobre los lotes de CADA
    | importacion, asi que seis importaciones dejarian las seis manzanas
    | apiladas en la esquina del plano. Ver el docblock de ImportadorDeDxf.
    */
    test('cada lote entra en el bloque que dice su rotulo', function (): void {
        $resultado = new ImportadorDeDxf()->importar(
            $this->bloque,
            dxfDeManzanas(),
            opcionesDeImportacion(capaDeCalles: null, bloquePorRotulo: true)
        );

        expect($resultado->lotesCreados)->toBe(5)
            ->and($resultado->sinRotulo)->toBe(0)
            ->and(Lote::query()->orderBy('codigo')->pluck('codigo')->all())
            ->toBe(['VV-A-001', 'VV-A-002', 'VV-B-001', 'VV-B-002', 'VV-C-001'])
            ->and($resultado->lotesPorBloque)->toBe(['A' => 2, 'B' => 2, 'C' => 1]);
    });

    /*
    | Los cinco lotes tienen "A=200.00m2" JUSTO en el centro y el numero
    | tres metros mas arriba. Con la lectura vieja el area ganaba por
    | cercania: el primer lote se llamaba "00" y los otros cuatro salian
    | renumerados. Que los codigos sean los de arriba lo prueba, pero este
    | test lo dice con todas las letras porque es el bug, no un detalle.
    */
    test('el area rotulada adentro del lote ya no le roba el numero', function (): void {
        new ImportadorDeDxf()->importar(
            $this->bloque,
            dxfDeManzanas(),
            opcionesDeImportacion(capaDeCalles: null, bloquePorRotulo: true)
        );

        expect(Lote::query()->pluck('numero')->all())->not->toContain('00')
            ->and(Lote::query()->pluck('numero')->all())->not->toContain('200');
    });

    test('el mismo numero en dos manzanas distintas ya no choca', function (): void {
        $resultado = new ImportadorDeDxf()->importar(
            $this->bloque,
            dxfDeManzanas(),
            opcionesDeImportacion(capaDeCalles: null, bloquePorRotulo: true)
        );

        expect(Lote::query()->where('numero', '1')->count())->toBe(3)
            ->and($resultado->advertencias)->toBeEmpty();
    });

    test('los bloques que faltaban nacen con lo que declara el plano', function (): void {
        $resultado = new ImportadorDeDxf()->importar(
            $this->bloque,
            dxfDeManzanas(),
            opcionesDeImportacion(capaDeCalles: null, bloquePorRotulo: true)
        );

        $b = Bloque::query()->where('proyecto_id', $this->proyecto->getKey())->where('nombre', 'B')->firstOrFail();

        expect($resultado->bloquesCreados)->toBe(['B', 'C'])
            ->and($b->getAttribute('lotes_planificados'))->toBe(2)
            ->and((int) $b->getAttribute('orden'))->toBeGreaterThan((int) $this->bloque->getAttribute('orden'));
    });

    test('sin la opcion prendida la letra se ignora y todo cae donde se eligio', function (): void {
        $resultado = new ImportadorDeDxf()->importar(
            $this->bloque,
            dxfDeManzanas(),
            opcionesDeImportacion(capaDeCalles: null)
        );

        expect(Bloque::query()->count())->toBe(1)
            ->and($resultado->lotesPorBloque)->toBe(['A' => 5])
            ->and(Lote::query()->orderBy('codigo')->pluck('numero')->all())->toBe(['1', '2', '3', '4', '5']);
    });

    /*
    | Prender la opcion con un plano que no rotula manzanas no es un error
    | —el plano entra igual— pero callarselo si: quien la prendio esperaba
    | ver bloques nuevos y no los va a ver.
    */
    test('prender la opcion con un plano sin letras avisa en vez de callarse', function (): void {
        $resultado = new ImportadorDeDxf()->importar($this->bloque, $this->dxf, opcionesDeImportacion(bloquePorRotulo: true));

        expect($resultado->lotesPorBloque)->toBe(['A' => 78])
            ->and($resultado->bloquesCreados)->toBe([])
            ->and(implode(' ', $resultado->advertencias))->toContain('Ningun rotulo traia la letra');
    });
});

describe('Importador — calles y estado del plano', function (): void {
    test('las calles se importan como area, no como eje', function (): void {
        $resultado = new ImportadorDeDxf()->importar($this->bloque, $this->dxf, opcionesDeImportacion());

        expect($resultado->callesCreadas)->toBe(4);

        $calle = Calle::query()->firstOrFail();

        expect($calle->esArea())->toBeTrue()
            ->and($calle->getAttribute('trazo'))->toBeNull()
            ->and(count($calle->verticesDelArea()))->toBeGreaterThanOrEqual(4);
    });

    test('sin capa de calles no se crea ninguna', function (): void {
        $resultado = new ImportadorDeDxf()->importar($this->bloque, $this->dxf, opcionesDeImportacion(capaDeCalles: null));

        expect($resultado->callesCreadas)->toBe(0)
            ->and(Calle::query()->count())->toBe(0);
    });

    /*
    | Importar un DXF es traer el plano del topografo. Es la unica
    | operacion que APAGA la marca de esquematico — el acomodador solo la
    | enciende.
    */
    test('un plano importado deja de ser esquematico', function (): void {
        $this->proyecto->update(['plano_esquematico' => true]);

        new ImportadorDeDxf()->importar($this->bloque, $this->dxf, opcionesDeImportacion());

        expect($this->proyecto->refresh()->getAttribute('plano_esquematico'))->toBeFalse();
    });
});

describe('Importador — se niega a hacer un desastre', function (): void {
    test('una capa sin contornos falla antes de crear nada', function (): void {
        expect(fn (): ResultadoDeImportacion => new ImportadorDeDxf()->importar($this->bloque, $this->dxf, opcionesDeImportacion(capaDeLotes: 'NO_EXISTE')))->toThrow(GeneracionDeLotesException::class);

        expect(Lote::query()->count())->toBe(0);
    });

    test('importar dos veces renumera en vez de romper el bloque', function (): void {
        new ImportadorDeDxf()->importar($this->bloque, $this->dxf, opcionesDeImportacion());
        $segundo = new ImportadorDeDxf()->importar($this->bloque, $this->dxf, opcionesDeImportacion());

        expect(Lote::query()->count())->toBe(156)
            ->and($segundo->advertencias)->not->toBeEmpty();
    });

    test('un archivo que no declara unidades exige elegirla', function (): void {
        $sinUnidad = str_replace("\$INSUNITS\r\n 70\r\n6", "\$INSUNITS\r\n 70\r\n0", $this->dxf);

        expect(fn (): ResultadoDeImportacion => new ImportadorDeDxf()->importar($this->bloque, $sinUnidad, opcionesDeImportacion(unidad: UnidadDxf::SinUnidad)))->toThrow(ValueObjectInvalidoException::class);
    });
});
