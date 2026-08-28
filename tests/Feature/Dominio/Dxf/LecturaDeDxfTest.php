<?php

declare(strict_types=1);

use App\Domain\Plano\Dxf\ArchivoDxf;
use App\Domain\Plano\Dxf\ArchivoDxfInvalidoException;
use App\Domain\Plano\Dxf\ExtractorDeGeometria;
use App\Domain\Plano\Dxf\LectorDxf;
use App\Domain\Plano\Dxf\PoligonoDxf;
use App\Domain\Plano\Dxf\RotuloDxf;
use App\Domain\Plano\Dxf\UnidadDxf;

/**
 * Arma un DXF con el mismo formato que escribe AutoCAD: el codigo
 * justificado a la derecha en tres caracteres y saltos CRLF. Los tests
 * corren contra el formato real, no contra uno comodo.
 *
 * @param list<array{int, string}> $tags
 */
function dxfCrudo(array $tags): string
{
    $lineas = [];

    foreach ($tags as [$codigo, $valor]) {
        $lineas[] = str_pad((string) $codigo, 3, ' ', STR_PAD_LEFT);
        $lineas[] = $valor;
    }

    return implode("\r\n", $lineas)."\r\n";
}

/**
 * @param list<array{int, string}> $tags
 */
function dxfConEntidades(array $tags, int $insunits = 6): string
{
    return dxfCrudo([
        [0, 'SECTION'], [2, 'HEADER'],
        [9, '$INSUNITS'], [70, (string) $insunits],
        [0, 'ENDSEC'],
        [0, 'SECTION'], [2, 'ENTITIES'],
        ...$tags,
        [0, 'ENDSEC'], [0, 'EOF'],
    ]);
}

/**
 * @param list<array{float, float}> $puntos
 * @param array<int, float> $bulges
 *
 * @return list<array{int, string}>
 */
function dxfPoligono(string $capa, array $puntos, bool $cerrado = true, array $bulges = []): array
{
    $tags = [
        [0, 'LWPOLYLINE'], [100, 'AcDbEntity'], [8, $capa], [100, 'AcDbPolyline'],
        [90, (string) count($puntos)], [70, $cerrado ? '1' : '0'],
    ];

    foreach ($puntos as $i => [$x, $y]) {
        $tags[] = [10, (string) $x];
        $tags[] = [20, (string) $y];

        // El 42 solo se escribe cuando no es cero, igual que AutoCAD.
        if (isset($bulges[$i])) {
            $tags[] = [42, (string) $bulges[$i]];
        }
    }

    return $tags;
}

function fixtureValleVerde(): string
{
    return (string) file_get_contents(base_path('tests/Fixtures/valle-verde.dxf'));
}

describe('Lector — el formato real', function (): void {
    test('lee codigos rellenados a tres caracteres y saltos CRLF', function (): void {
        $archivo = new LectorDxf()->leer(dxfConEntidades(
            dxfPoligono('LOTES', [[0.0, 0.0], [10.0, 0.0], [10.0, 10.0], [0.0, 10.0]])
        ));

        expect($archivo->conteoPorTipo())->toBe(['LWPOLYLINE' => 1])
            ->and($archivo->capasUsadas())->toBe(['LOTES' => 1]);
    });

    /*
    | La trampa que rompe a los parsers ingenuos. Hay VALORES que son la
    | cadena "0" —el color, las banderas, una coordenada en el origen— y un
    | escaner que busque lineas iguales a "0" toma la linea siguiente como
    | nombre de entidad e inventa entidades que no existen.
    */
    test('un valor que vale "0" no inventa una entidad fantasma', function (): void {
        $archivo = new LectorDxf()->leer(dxfConEntidades([
            [0, 'LWPOLYLINE'], [8, 'LOTES'], [62, '0'], [90, '4'], [70, '1'],
            [10, '0'], [20, '0'], [10, '10'], [20, '0'],
            [10, '10'], [20, '10'], [10, '0'], [20, '10'],
        ]));

        expect($archivo->conteoPorTipo())->toBe(['LWPOLYLINE' => 1]);
    });

    test('lee la unidad declarada en el HEADER', function (): void {
        expect(new LectorDxf()->leer(dxfConEntidades([], 6))->unidades())->toBe(UnidadDxf::Metros)
            ->and(new LectorDxf()->leer(dxfConEntidades([], 4))->unidades())->toBe(UnidadDxf::Milimetros)
            ->and(new LectorDxf()->leer(dxfConEntidades([], 0))->unidades())->toBe(UnidadDxf::SinUnidad);
    });

    test('sin unidad declarada no hay factor que inventar', function (): void {
        expect(UnidadDxf::SinUnidad->enMetros())->toBeNull()
            ->and(UnidadDxf::Metros->enMetros())->toBe(1.0);
    });

    /*
    | Las capas que vienen de una referencia externa se guardan como
    | "planta$0$LOTES". Sin limpiar el prefijo, ninguna capa de xref
    | coincide jamas con lo que el usuario eligio.
    */
    test('limpia el prefijo de referencia externa de las capas', function (): void {
        $archivo = new LectorDxf()->leer(dxfConEntidades(
            dxfPoligono('plano-topografico$0$LOTES', [[0.0, 0.0], [1.0, 0.0], [1.0, 1.0]])
        ));

        expect($archivo->capasUsadas())->toBe(['LOTES' => 1]);
    });

    test('un archivo que no es DXF se rechaza con un mensaje util', function (): void {
        expect(fn (): ArchivoDxf => new LectorDxf()->leer("esto no es un DXF\nen absoluto\n"))
            ->toThrow(ArchivoDxfInvalidoException::class);
    });

    test('un archivo vacio se rechaza', function (): void {
        expect(fn (): ArchivoDxf => new LectorDxf()->leer(''))->toThrow(ArchivoDxfInvalidoException::class);
    });
});

describe('Extractor — contornos', function (): void {
    test('una polilinea cerrada es un contorno', function (): void {
        $archivo = new LectorDxf()->leer(dxfConEntidades(
            dxfPoligono('LOTES', [[0.0, 0.0], [10.0, 0.0], [10.0, 20.0], [0.0, 20.0]])
        ));

        $poligonos = new ExtractorDeGeometria()->poligonos($archivo);

        expect($poligonos)->toHaveCount(1)
            ->and($poligonos[0]->area())->toBe(200.0)
            ->and($poligonos[0]->capa)->toBe('LOTES');
    });

    test('una polilinea abierta no es un lote y se descarta', function (): void {
        $archivo = new LectorDxf()->leer(dxfConEntidades(
            dxfPoligono('LOTES', [[0.0, 0.0], [10.0, 0.0], [10.0, 20.0]], cerrado: false)
        ));

        expect(new ExtractorDeGeometria()->poligonos($archivo))->toBe([]);
    });

    /*
    | Hay exportadores que no encienden la bandera de cerrada y en su lugar
    | repiten el primer vertice al final. Se acepta, y el duplicado se
    | descarta para no dejar un lado de largo cero.
    */
    test('una polilinea que repite el primer vertice se toma como cerrada', function (): void {
        $archivo = new LectorDxf()->leer(dxfConEntidades(
            dxfPoligono('LOTES', [[0.0, 0.0], [10.0, 0.0], [10.0, 20.0], [0.0, 20.0], [0.0, 0.0]], cerrado: false)
        ));

        $poligonos = new ExtractorDeGeometria()->poligonos($archivo);

        expect($poligonos)->toHaveCount(1)
            ->and($poligonos[0]->vertices())->toBe(4)
            ->and($poligonos[0]->area())->toBe(200.0);
    });

    /*
    | El bulge pertenece al vertice leido ANTES y solo aparece cuando no es
    | cero. Indexarlo en paralelo pondria el arco en el lado equivocado.
    */
    test('el bulge curva el lado del vertice al que sigue', function (): void {
        $archivo = new LectorDxf()->leer(dxfConEntidades(
            dxfPoligono('LOTES', [[0.0, 0.0], [10.0, 0.0], [10.0, 10.0], [0.0, 10.0]], bulges: [2 => 0.5])
        ));

        $poligonos = new ExtractorDeGeometria()->poligonos($archivo);

        expect($poligonos[0]->vertices())->toBeGreaterThan(4)
            ->and($poligonos[0]->area())->toBeGreaterThan(100.0);
    });

    test('lee el formato viejo POLYLINE / VERTEX / SEQEND', function (): void {
        $archivo = new LectorDxf()->leer(dxfConEntidades([
            [0, 'POLYLINE'], [8, 'LOTES'], [66, '1'], [10, '0.0'], [20, '0.0'], [30, '0.0'], [70, '1'],
            [0, 'VERTEX'], [8, 'LOTES'], [10, '0'], [20, '0'],
            [0, 'VERTEX'], [8, 'LOTES'], [10, '10'], [20, '0'],
            [0, 'VERTEX'], [8, 'LOTES'], [10, '10'], [20, '20'],
            [0, 'VERTEX'], [8, 'LOTES'], [10, '0'], [20, '20'],
            [0, 'SEQEND'], [8, 'LOTES'],
        ]));

        $poligonos = new ExtractorDeGeometria()->poligonos($archivo);

        expect($poligonos)->toHaveCount(1)
            ->and($poligonos[0]->origen)->toBe('POLYLINE')
            ->and($poligonos[0]->area())->toBe(200.0);
    });

    test('el punto ficticio de la POLYLINE no entra como vertice', function (): void {
        // Los 10/20 de la POLYLINE valen siempre cero. Si se colaran, el
        // area saldria distinta de 200.
        $archivo = new LectorDxf()->leer(dxfConEntidades([
            [0, 'POLYLINE'], [8, 'LOTES'], [10, '0.0'], [20, '0.0'], [70, '1'],
            [0, 'VERTEX'], [8, 'LOTES'], [10, '100'], [20, '100'],
            [0, 'VERTEX'], [8, 'LOTES'], [10, '110'], [20, '100'],
            [0, 'VERTEX'], [8, 'LOTES'], [10, '110'], [20, '120'],
            [0, 'VERTEX'], [8, 'LOTES'], [10, '100'], [20, '120'],
            [0, 'SEQEND'], [8, 'LOTES'],
        ]));

        expect(new ExtractorDeGeometria()->poligonos($archivo)[0]->area())->toBe(200.0);
    });

    test('una polilinea 3D no es un lote', function (): void {
        $archivo = new LectorDxf()->leer(dxfConEntidades([
            [0, 'POLYLINE'], [8, 'CURVAS'], [70, '9'],
            [0, 'VERTEX'], [8, 'CURVAS'], [10, '0'], [20, '0'],
            [0, 'VERTEX'], [8, 'CURVAS'], [10, '10'], [20, '0'],
            [0, 'VERTEX'], [8, 'CURVAS'], [10, '10'], [20, '10'],
            [0, 'SEQEND'], [8, 'CURVAS'],
        ]));

        expect(new ExtractorDeGeometria()->poligonos($archivo))->toBe([]);
    });
});

describe('Extractor — rotulos', function (): void {
    /*
    | Los numeros de lote se rotulan centrados, y cuando hay justificacion
    | la posicion real es el 11/21. Leer siempre el 10/20 ubicaria mal casi
    | todas las etiquetas del plano y los numeros caerian en el lote vecino.
    */
    test('un texto justificado se ubica por el segundo punto', function (): void {
        $archivo = new LectorDxf()->leer(dxfConEntidades([
            [0, 'TEXT'], [8, 'TEXTOS'], [10, '1'], [20, '1'], [40, '2'], [1, '12'],
            [72, '1'], [73, '2'], [11, '50'], [21, '60'],
        ]));

        $rotulos = new ExtractorDeGeometria()->rotulos($archivo);

        expect($rotulos[0]->x)->toBe(50.0)
            ->and($rotulos[0]->y)->toBe(60.0)
            ->and($rotulos[0]->numeroDeLote())->toBe('12');
    });

    test('un texto sin justificacion se ubica por el primer punto', function (): void {
        $archivo = new LectorDxf()->leer(dxfConEntidades([
            [0, 'TEXT'], [8, 'TEXTOS'], [10, '7'], [20, '9'], [40, '2'], [1, '3'],
        ]));

        $rotulos = new ExtractorDeGeometria()->rotulos($archivo);

        expect($rotulos[0]->x)->toBe(7.0)->and($rotulos[0]->y)->toBe(9.0);
    });

    /*
    | En MTEXT los trozos del codigo 3 se emiten ANTES del codigo 1, que
    | trae el final. Quedarse solo con el 1 devolveria el ultimo pedazo.
    */
    test('un MTEXT largo se rearma en orden', function (): void {
        $largo = str_repeat('A', 250);

        $archivo = new LectorDxf()->leer(dxfConEntidades([
            [0, 'MTEXT'], [8, 'TEXTOS'], [10, '0'], [20, '0'], [40, '2'],
            [3, $largo], [1, 'FIN'],
        ]));

        expect(new ExtractorDeGeometria()->rotulos($archivo)[0]->texto)->toBe($largo.'FIN');
    });

    test('un MTEXT con codigos de formato se limpia', function (): void {
        $archivo = new LectorDxf()->leer(dxfConEntidades([
            [0, 'MTEXT'], [8, 'TEXTOS'], [10, '0'], [20, '0'], [40, '2'],
            [1, '{\\H1.2x;\\C1;LOTE 42}\\P250.00 m2'],
        ]));

        $rotulo = new ExtractorDeGeometria()->rotulos($archivo)[0];

        expect($rotulo->texto)->toBe("LOTE 42\n250.00 m2")
            ->and($rotulo->numeroDeLote())->toBe('42');
    });

    /*
    | 🔴 El bug del 13-ago-2026, con el plano de EL BAMBU. Adentro de cada
    | lote hay tres o cuatro textos —el numero, el area en m2, el area en
    | varas2 y las medidas de los lados— y gana el que quede mas cerca del
    | centro. Buscando "el primer numero que aparezca" en cualquier parte
    | del texto, "A=157.63m2" daba el lote 63 y "17.40m" el lote 40-M: de
    | 84 lotes quedaba UNO con el numero correcto.
    */
    test('un area, una medida o un nombre no son un numero de lote', function (): void {
        $archivo = new LectorDxf()->leer(dxfConEntidades([
            [0, 'TEXT'], [8, 'TEXTOS'], [10, '0'], [20, '0'], [40, '2'], [1, 'A=157.63m2'],
            [0, 'TEXT'], [8, 'TEXTOS'], [10, '0'], [20, '0'], [40, '2'], [1, '17.40m'],
            [0, 'TEXT'], [8, 'TEXTOS'], [10, '0'], [20, '0'], [40, '2'], [1, '521.563V2'],
            [0, 'TEXT'], [8, 'TEXTOS'], [10, '0'], [20, '0'], [40, '2'], [1, '250 m2'],
            [0, 'TEXT'], [8, 'TEXTOS'], [10, '0'], [20, '0'], [40, '2'], [1, 'AREA MUNICIPAL'],
        ]));

        $numeros = array_map(
            static fn (RotuloDxf $rotulo): ?string => $rotulo->numeroDeLote(),
            new ExtractorDeGeometria()->rotulos($archivo)
        );

        expect($numeros)->toBe([null, null, null, null, null]);
    });

    /*
    | La letra de la manzana se lee APARTE del numero, y por posicion:
    | adelante es el bloque ("A1"), atras es el sufijo de una subdivision
    | ("12B"), que es formato que el sistema ya admitia. Las palabras que
    | quieren decir "lote" no son manzana; una "L" sola si, porque "L-12"
    | da el numero 12 lo mismo leyendola de una forma que de la otra.
    */
    test('la manzana pegada al numero se lee aparte del numero', function (): void {
        $archivo = new LectorDxf()->leer(dxfConEntidades([
            [0, 'TEXT'], [8, 'TEXTOS'], [10, '0'], [20, '0'], [40, '2'], [1, 'A1'],
            [0, 'TEXT'], [8, 'TEXTOS'], [10, '0'], [20, '0'], [40, '2'], [1, 'B-7'],
            [0, 'TEXT'], [8, 'TEXTOS'], [10, '0'], [20, '0'], [40, '2'], [1, '12'],
            [0, 'TEXT'], [8, 'TEXTOS'], [10, '0'], [20, '0'], [40, '2'], [1, '12B'],
            [0, 'TEXT'], [8, 'TEXTOS'], [10, '0'], [20, '0'], [40, '2'], [1, 'LOTE 12'],
        ]));

        $leidos = array_map(
            static fn (RotuloDxf $rotulo): array => [$rotulo->bloqueDeLote(), $rotulo->numeroDeLote()],
            new ExtractorDeGeometria()->rotulos($archivo)
        );

        expect($leidos)->toBe([
            ['A', '1'],
            ['B', '7'],
            [null, '12'],
            [null, '12-B'],
            [null, '12'],
        ]);
    });
});

describe('Lector — contra el plano de prueba completo', function (): void {
    test('lee las 78 parcelas, las calles y los rotulos', function (): void {
        $archivo = new LectorDxf()->leer(fixtureValleVerde());
        $poligonos = new ExtractorDeGeometria()->poligonos($archivo);

        $porCapa = [];

        foreach ($poligonos as $poligono) {
            $porCapa[$poligono->capa] = ($porCapa[$poligono->capa] ?? 0) + 1;
        }

        expect($archivo->unidades())->toBe(UnidadDxf::Metros)
            ->and($porCapa['LOTES'])->toBe(78)
            ->and($porCapa['CALLES'])->toBe(4)
            ->and($porCapa['AREAS_VERDES'])->toBe(1)
            ->and(new ExtractorDeGeometria()->rotulos($archivo))->toHaveCount(78);
    });

    test('los seis lotes en abanico conservan sus arcos', function (): void {
        $poligonos = new ExtractorDeGeometria()->poligonos(new LectorDxf()->leer(fixtureValleVerde()));

        $conArco = array_filter(
            $poligonos,
            static fn (PoligonoDxf $p): bool => $p->capa === 'LOTES' && $p->vertices() > 8
        );

        expect($conArco)->toHaveCount(6);

        // 180 grados en 6 lotes, entre radios 12 y 32 metros.
        $exacta = (deg2rad(180.0) / 6 / 2) * (32.0 ** 2 - 12.0 ** 2);

        foreach ($conArco as $lote) {
            expect(abs($lote->area() - $exacta) / $exacta)->toBeLessThan(0.0005);
        }
    });
});

describe('DXF — el area que escribio el topografo', function (): void {
    /*
    | 25-ago-2026. Mauricio, con el plano de ALTAMIRA al lado de la
    | pantalla: «no esta dando medidas exactas; ejemplo, ese es 314.16 la
    | medida real, tiene que ser exacto».
    |
    | El area es lo que se VENDE: multiplica al precio y sale impresa en la
    | escritura. Sacada del contorno no puede ser exacta cuando el lote
    | tiene un lado curvo —el arco entra teselado y una poligonal inscrita
    | encierra menos que el arco—: el G-7 daba 314.02 contra los 314.16 del
    | plano, y el J-1 daba 296.78 contra 296.72.
    |
    | Cuando el plano dice el area, la dice el plano.
    */

    /**
     * @param list<string> $sufijos
     *
     * @return list<?string>
     */
    function areasLeidas(string $texto, array $sufijos): array
    {
        $archivo = new LectorDxf()->leer(dxfConEntidades([
            [0, 'TEXT'], [8, 'TEXTOS'], [10, '0'], [20, '0'], [40, '2'], [1, $texto],
        ]));

        return array_map(
            static fn (RotuloDxf $rotulo): ?string => $rotulo->areaRotulada($sufijos),
            new ExtractorDeGeometria()->rotulos($archivo)
        );
    }

    test('lee el numero tal como lo escribio el topografo', function (): void {
        // String y no float: «314.16» es exactamente 314.16 y el float no.
        expect(areasLeidas('A=314.16m2', ['m2']))->toBe(['314.16'])
            ->and(areasLeidas('A=157.63m2', ['m2']))->toBe(['157.63'])
            ->and(areasLeidas('250 m2', ['m2']))->toBe(['250'])
            ->and(areasLeidas('521.563V2', ['v2']))->toBe(['521.563'])
            ->and(areasLeidas('286.85v2', ['v2', 'vr2']))->toBe(['286.85']);
    });

    test('toma la unidad del PROYECTO, no la primera que encuentre', function (): void {
        /*
        | 🔴 Es el caso que decide todo: un plano rotula las DOS areas del
        | mismo lote —«A=200.00m2» arriba y «286.85v2» abajo—. Leer la que
        | no es deja cada lote con el area de la otra unidad, que es un
        | error del 43 % pasando por exacto.
        */
        expect(areasLeidas('A=200.00m2', ['v2', 'vr2']))->toBe([null])
            ->and(areasLeidas('286.85v2', ['m2']))->toBe([null]);
    });

    test('una medida, un radio o un nombre no son un area', function (): void {
        expect(areasLeidas('17.40m', ['m2']))->toBe([null])
            ->and(areasLeidas('r=20.00m', ['m2']))->toBe([null])
            ->and(areasLeidas('AREA MUNICIPAL', ['m2']))->toBe([null])
            ->and(areasLeidas('A12', ['m2']))->toBe([null]);
    });

    test('sin unidades declaradas no lee ninguna area', function (): void {
        // El default del importador: calcular el area del contorno, que es
        // lo unico posible cuando el plano no la rotula.
        expect(areasLeidas('A=314.16m2', []))->toBe([null]);
    });

    test('el separador de miles del dibujante no se cuela en el numero', function (): void {
        expect(areasLeidas('A=1,234.56m2', ['m2']))->toBe(['1234.56']);
    });
});
