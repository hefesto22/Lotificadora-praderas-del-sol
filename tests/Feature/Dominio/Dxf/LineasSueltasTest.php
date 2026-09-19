<?php

declare(strict_types=1);

use App\Domain\Exceptions\GeneracionDeLotesException;
use App\Domain\Plano\Dxf\ArmadorDeContornos;
use App\Domain\Plano\Dxf\ContornoArmado;
use App\Domain\Plano\Dxf\ImportadorDeDxf;
use App\Domain\Plano\Dxf\OpcionesDeImportacion;
use App\Domain\Plano\Dxf\ResultadoDeImportacion;
use App\Domain\Plano\Dxf\RotuloDxf;
use App\Models\Bloque;
use App\Models\Lote;
use App\Models\Proyecto;

/*
|--------------------------------------------------------------------------
| El plano dibujado con lineas sueltas — 18-sep-2026
|--------------------------------------------------------------------------
| Llegaron dos planos del mismo ingeniero, de 83 y 95 lotes, sin UN solo
| contorno cerrado: el perimetro de cada manzana es una polilinea abierta,
| las divisiones son LINE sueltas, y todo vive en la capa «0» junto con el
| cajetin. Los numeros no traen letra («1», «2», «3») y la manzana va en un
| texto aparte, «BLOQUE A».
|
| Los dos planos reales se prueban en PlanoDesdeDxfSeederTest. Aca esta la
| mecanica, con dibujos chicos donde cada numero se puede hacer de cabeza.
*/

/**
 * Un rectangulo dibujado como lo dibuja ese ingeniero: cuatro lineas.
 *
 * @return list<array{float, float, float, float}>
 */
function rectanguloDeLineas(float $x, float $y, float $ancho, float $alto): array
{
    return [
        [$x, $y, $x + $ancho, $y],
        [$x + $ancho, $y, $x + $ancho, $y + $alto],
        [$x + $ancho, $y + $alto, $x, $y + $alto],
        [$x, $y + $alto, $x, $y],
    ];
}

/**
 * Las superficies de las caras, de menor a mayor y redondeadas.
 *
 * @param list<ContornoArmado> $caras
 *
 * @return list<float>
 */
function superficiesDe(array $caras): array
{
    $superficies = array_map(static fn (ContornoArmado $cara): float => round($cara->area(), 2), $caras);
    sort($superficies);

    return $superficies;
}

/**
 * @param list<array{float, float, float, float}> $lineas
 * @param list<array{string, string, float, float}> $textos [capa, texto, x, y]
 */
function dxfDeLineas(array $lineas, array $textos): string
{
    /** @var list<array{int, string}> $tags */
    $tags = [
        [0, 'SECTION'], [2, 'HEADER'],
        [9, '$INSUNITS'], [70, '6'],
        [0, 'ENDSEC'],
        [0, 'SECTION'], [2, 'ENTITIES'],
    ];

    foreach ($lineas as [$x1, $y1, $x2, $y2]) {
        $tags = [
            ...$tags,
            [0, 'LINE'], [100, 'AcDbEntity'], [8, '0'], [100, 'AcDbLine'],
            [10, (string) $x1], [20, (string) $y1], [11, (string) $x2], [21, (string) $y2],
        ];
    }

    foreach ($textos as [$capa, $texto, $x, $y]) {
        $tags = [
            ...$tags,
            [0, 'TEXT'], [100, 'AcDbEntity'], [8, $capa], [100, 'AcDbText'],
            [10, (string) $x], [20, (string) $y], [40, '1.0'], [1, $texto],
        ];
    }

    $tags[] = [0, 'ENDSEC'];
    $tags[] = [0, 'EOF'];

    $renglones = [];

    foreach ($tags as [$codigo, $valor]) {
        $renglones[] = str_pad((string) $codigo, 3, ' ', STR_PAD_LEFT);
        $renglones[] = $valor;
    }

    return implode("\r\n", $renglones)."\r\n";
}

/**
 * Dos manzanas y un cajetin, todo en la capa «0».
 *
 *   Manzana A: 30 x 20 partida en tres lotes de 10 x 20 = 200 m².
 *   Manzana B: 20 x 20 partida en dos, a diez metros de calle.
 *   Cajetin:   un recuadro lejos, con el numero de colegiado adentro.
 *
 * Los numeros no traen letra. El A-3 trae el area SIN unidad y el B-2 trae
 * en su lugar la medida de un lado, que es el caso que no tiene que colar.
 */
function dxfDeDosManzanasSueltas(): string
{
    return dxfDeLineas(
        [
            ...rectanguloDeLineas(0.0, 0.0, 30.0, 20.0),
            [10.0, 0.0, 10.0, 20.0],
            [20.0, 0.0, 20.0, 20.0],
            ...rectanguloDeLineas(40.0, 0.0, 20.0, 20.0),
            [50.0, 0.0, 50.0, 20.0],
            ...rectanguloDeLineas(100.0, 0.0, 20.0, 10.0),
        ],
        [
            ['NUMEROS', '1', 5.0, 14.0], ['AREAS', 'A=200.00m2', 5.0, 8.0],
            ['NUMEROS', '2', 15.0, 14.0], ['AREAS', 'A=200.00m2', 15.0, 8.0],
            ['NUMEROS', '3', 25.0, 14.0], ['AREAS', 'A=200.00', 25.0, 8.0],
            ['NUMEROS', '1', 45.0, 14.0], ['AREAS', 'A=200.00m2', 45.0, 8.0],
            ['NUMEROS', '2', 55.0, 14.0], ['AREAS', '17.40', 55.0, 8.0],
            ['TITULOS', 'BLOQUE A', 15.0, 3.0],
            ['TITULOS', 'BLOQUE B', 45.0, 3.0],
            // El numero de colegiado: tiene forma de numero de lote y esta
            // adentro de un recuadro cerrado. No es un lote.
            ['0', '9293', 110.0, 5.0],
        ],
    );
}

function opcionesDeLineasSueltas(): OpcionesDeImportacion
{
    return new OpcionesDeImportacion(
        capaDeLotes: '0',
        precioVara: '0',
        capaDeRotulos: 'NUMEROS',
        // En metros²: la vara del proyecto es el metro y el area del
        // dibujo se lee directo.
        varaEnMetros: '1.000000',
        sufijosDeArea: ['m2'],
        armarContornos: true,
        capaDeAreas: 'AREAS',
    );
}

/**
 * El area guardada del lote con ese numero adentro de ese bloque.
 */
function areaGuardada(Bloque $bloque, string $numero): mixed
{
    return Lote::query()
        ->where('bloque_id', $bloque->getKey())
        ->where('numero', $numero)
        ->value('area_varas');
}

describe('armar contornos siguiendo lineas', function (): void {
    test('una division que muere contra el perimetro parte la manzana en dos lotes', function (): void {
        $caras = new ArmadorDeContornos()->armar([
            ...rectanguloDeLineas(0.0, 0.0, 20.0, 10.0),
            [10.0, 0.0, 10.0, 10.0],
        ], 0.03);

        // Dos, no tres: el borde de afuera de la manzana no es una cara.
        expect(superficiesDe($caras))->toBe([100.0, 100.0]);
    });

    test('un hueco de milimetros cierra y uno de medio metro no', function (): void {
        $manzana = rectanguloDeLineas(0.0, 0.0, 20.0, 10.0);

        $cerroAOjo = new ArmadorDeContornos()->armar([...$manzana, [10.0, 0.0, 10.0, 9.99]], 0.03);
        $noLlega = new ArmadorDeContornos()->armar([...$manzana, [10.0, 0.0, 10.0, 9.5]], 0.03);

        expect(superficiesDe($cerroAOjo))->toBe([100.0, 100.0])
            // La division que no llega queda colgando, se poda, y la
            // manzana es UNA sola cara, sin espiga.
            ->and(superficiesDe($noLlega))->toBe([200.0])
            ->and($noLlega[0]->puntosSinAlineados(0.003))->toHaveCount(4);
    });

    test('dos lineas que se cruzan por el medio se parten las dos', function (): void {
        $caras = new ArmadorDeContornos()->armar([
            ...rectanguloDeLineas(0.0, 0.0, 20.0, 20.0),
            [0.0, 0.0, 20.0, 20.0],
            [0.0, 20.0, 20.0, 0.0],
        ], 0.03);

        expect(superficiesDe($caras))->toBe([100.0, 100.0, 100.0, 100.0]);
    });

    test('dos caras vecinas comparten lindero, y fundidas vuelven a ser el rectangulo', function (): void {
        $caras = new ArmadorDeContornos()->armar([
            ...rectanguloDeLineas(0.0, 0.0, 20.0, 10.0),
            [10.0, 0.0, 10.0, 10.0],
        ], 0.03);

        $compartidos = array_intersect_key($caras[0]->linderos(), $caras[1]->linderos());
        $fundida = $caras[0]->unidoCon($caras[1]);

        expect($compartidos)->toHaveCount(1)
            ->and($fundida)->toBeInstanceOf(ContornoArmado::class)
            ->and(round((float) $fundida?->area(), 2))->toBe(200.0)
            // Los dos nodos donde moria la division quedan sobre un lado
            // recto: para el dibujo sobran.
            ->and($fundida?->puntosSinAlineados(0.003))->toHaveCount(4);
    });

    test('dos caras que no se tocan no se funden', function (): void {
        $caras = new ArmadorDeContornos()->armar([
            ...rectanguloDeLineas(0.0, 0.0, 10.0, 10.0),
            ...rectanguloDeLineas(50.0, 0.0, 10.0, 10.0),
        ], 0.03);

        expect($caras)->toHaveCount(2)
            ->and($caras[0]->unidoCon($caras[1]))->toBeNull();
    });
});

describe('los rotulos de un plano que numera sin letra', function (): void {
    test('«BLOQUE A» nombra una manzana, y un numero de lote no', function (string $texto, ?string $esperado): void {
        expect(new RotuloDxf('TITULOS', $texto, 0.0, 0.0, 1.0)->nombreDeBloque())->toBe($esperado);
    })->with([
        ['BLOQUE A', 'A'],
        ['bloque  b', 'B'],
        ['MANZANA-12', '12'],
        // Con cuatro letras o menos ya es «el lote 3 del bloque MZ».
        ['MZ 3', null],
        ['A1', null],
        ['CALLE PUBLICA.', null],
    ]);

    test('un area sin unidad se lee, y una medida de lado con su unidad no', function (string $texto, ?string $esperado): void {
        expect(new RotuloDxf('AREAS', $texto, 0.0, 0.0, 1.0)->areaSinUnidad())->toBe($esperado);
    })->with([
        ['A=447.08', '447.08'],
        ['260.00', '260.00'],
        ['A=1,911.29', '1911.29'],
        // Sin decimales es un numero de lote.
        ['12', null],
        ['17.40m', null],
        // Con unidad ya lo lee areaRotulada(): no se cuenta dos veces.
        ['A=200.00m2', null],
    ]);
});

describe('analizar un plano de lineas sueltas', function (): void {
    /*
    | 🔴 La capa por defecto de AutoCAD se llama «0», y PHP guarda como
    | ENTERO toda clave de texto que parezca un numero. Con strict_types,
    | esa clave entera no entra en un parametro `string` ni sale de un
    | metodo `?string`: el analisis reventaba con un TypeError -un 500 al
    | subir el archivo- en cuanto la «0» tenia un solo texto adentro. En un
    | plano de lineas sueltas TODO esta en la «0».
    */
    test('la capa «0» no rompe el analisis, y es la que se sugiere para armar los lotes', function (): void {
        $analisis = new ImportadorDeDxf()->analizar(dxfDeDosManzanasSueltas());

        expect($analisis->capaSugeridaDeTramos())->toBe('0')
            ->and($analisis->nombresDeCapas())->toContain('0')
            // No hay ni un contorno cerrado: por el camino de siempre no
            // hay capa que sugerir, y tiene que decirlo sin romperse.
            ->and($analisis->capaSugeridaDeLotes())->toBeNull()
            ->and($analisis->capaSugeridaDeRotulos())->toBe('NUMEROS');
    });
});

describe('importar un plano de lineas sueltas', function (): void {
    beforeEach(function (): void {
        $this->proyecto = Proyecto::factory()->create(['codigo' => 'LS']);
        // El destino de los lotes que queden sin manzana. Se llama Z para
        // que un lote mal repartido se note.
        $this->bloque = Bloque::factory()->create([
            'proyecto_id' => $this->proyecto->getKey(),
            'nombre'      => 'Z',
        ]);
    });

    test('es lote el contorno que tiene un numero adentro, y entra en la manzana de su «BLOQUE»', function (): void {
        $resultado = new ImportadorDeDxf()->importar($this->bloque, dxfDeDosManzanasSueltas(), opcionesDeLineasSueltas());

        // Cinco: el recuadro del cajetin cierra y tiene un «9293» adentro,
        // pero ese numero no esta en la capa de los numeros de lote.
        expect($resultado->lotesCreados)->toBe(5)
            ->and($resultado->lotesPorBloque)->toBe(['A' => 3, 'B' => 2])
            ->and($resultado->bloquesCreados)->toBe(['A', 'B'])
            ->and($resultado->sinRotulo)->toBe(0)
            ->and(Lote::query()->where('bloque_id', $this->bloque->getKey())->count())->toBe(0);
    });

    test('el area sin unidad entra si el dibujo la confirma, y la medida de un lado no cuela', function (): void {
        $resultado = new ImportadorDeDxf()->importar($this->bloque, dxfDeDosManzanasSueltas(), opcionesDeLineasSueltas());

        /** @var Bloque $a */
        $a = Bloque::query()->where('proyecto_id', $this->proyecto->getKey())->where('nombre', 'A')->sole();
        /** @var Bloque $b */
        $b = Bloque::query()->where('proyecto_id', $this->proyecto->getKey())->where('nombre', 'B')->sole();

        // A-3 dice «A=200.00» a secas y mide 200: es su area.
        // B-2 dice «17.40» y mide 200: eso es un lado. Entra con el dibujo
        // y queda contado entre los que no traian area.
        expect(areaGuardada($a, '3'))->toBe('200.0000')
            ->and(areaGuardada($b, '2'))->toBe('200.0000')
            ->and($resultado->sinAreaRotulada)->toBe(1)
            ->and(round($resultado->areaTotalVaras, 2))->toBe(1000.0);
    });

    test('un lote partido por una linea sobrante se une si las dos mitades suman su rotulo', function (): void {
        $dxf = dxfDeLineas(
            [
                ...rectanguloDeLineas(0.0, 0.0, 40.0, 20.0),
                [20.0, 0.0, 20.0, 20.0],
                // La linea sobrante: cruza el lote 7 por la mitad.
                [10.0, 0.0, 10.0, 20.0],
            ],
            [
                ['NUMEROS', '7', 5.0, 14.0], ['AREAS', 'A=400.00m2', 5.0, 8.0],
                ['NUMEROS', '8', 30.0, 14.0], ['AREAS', 'A=400.00m2', 30.0, 8.0],
            ],
        );

        $resultado = new ImportadorDeDxf()->importar($this->bloque, $dxf, opcionesDeLineasSueltas());

        /** @var Lote $siete */
        $siete = Lote::query()->where('bloque_id', $this->bloque->getKey())->where('numero', '7')->sole();

        expect($resultado->lotesCreados)->toBe(2)
            ->and($siete->getAttribute('area_varas'))->toBe('400.0000')
            // El dibujo quedo entero: mide lo que dice su rotulo.
            ->and(round((float) $siete->areaSegunPoligonoVaras(), 2))->toBe(400.0)
            ->and(implode(' ', $resultado->advertencias))->toContain('linea sobrante');
    });

    test('sin esa suma no se une nada, y el lote queda avisado', function (): void {
        $dxf = dxfDeLineas(
            [
                ...rectanguloDeLineas(0.0, 0.0, 40.0, 20.0),
                [20.0, 0.0, 20.0, 20.0],
                [10.0, 0.0, 10.0, 20.0],
            ],
            [
                // 350 no es ni la mitad (200) ni el lote entero (400).
                ['NUMEROS', '7', 5.0, 14.0], ['AREAS', 'A=350.00m2', 5.0, 8.0],
                ['NUMEROS', '8', 30.0, 14.0], ['AREAS', 'A=400.00m2', 30.0, 8.0],
            ],
        );

        $resultado = new ImportadorDeDxf()->importar($this->bloque, $dxf, opcionesDeLineasSueltas());

        /** @var Lote $siete */
        $siete = Lote::query()->where('bloque_id', $this->bloque->getKey())->where('numero', '7')->sole();

        // Entra con lo que dice el rotulo -el area la dice el plano- y con
        // el dibujo tal cual. Lo que no hace es callarse.
        expect($siete->getAttribute('area_varas'))->toBe('350.0000')
            ->and(round((float) $siete->areaSegunPoligonoVaras(), 2))->toBe(200.0)
            ->and(implode(' ', $resultado->advertencias))->toContain('Z-7')
            ->and(implode(' ', $resultado->advertencias))->not->toContain('linea sobrante');
    });

    test('la manzana sin nombre entra en el bloque elegido, y se avisa', function (): void {
        $dxf = dxfDeLineas(
            [
                ...rectanguloDeLineas(0.0, 0.0, 20.0, 20.0),
                [10.0, 0.0, 10.0, 20.0],
                ...rectanguloDeLineas(40.0, 0.0, 10.0, 20.0),
            ],
            [
                ['NUMEROS', '1', 5.0, 14.0], ['NUMEROS', '2', 15.0, 14.0],
                ['TITULOS', 'BLOQUE A', 5.0, 3.0],
                // Una isla de un solo lote, sin «BLOQUE» que la nombre.
                ['NUMEROS', '1', 45.0, 14.0],
            ],
        );

        $resultado = new ImportadorDeDxf()->importar($this->bloque, $dxf, opcionesDeLineasSueltas());

        expect($resultado->lotesPorBloque)->toBe(['A' => 2, 'Z' => 1])
            ->and(implode(' ', $resultado->advertencias))->toContain('entraron en Z');
    });

    test('un lote que no cierra NO entra con la forma de la calle', function (): void {
        $dxf = dxfDeLineas(
            [
                // El marco del plano, que encierra todo.
                ...rectanguloDeLineas(-50.0, -50.0, 200.0, 200.0),
                ...rectanguloDeLineas(0.0, 0.0, 20.0, 20.0),
                [10.0, 0.0, 10.0, 20.0],
                // Al lote 3 le falta un lado entero.
                [40.0, 0.0, 50.0, 0.0],
                [50.0, 0.0, 50.0, 20.0],
                [50.0, 20.0, 40.0, 20.0],
            ],
            [
                ['NUMEROS', '1', 5.0, 14.0], ['NUMEROS', '2', 15.0, 14.0],
                ['NUMEROS', '3', 45.0, 14.0],
            ],
        );

        $resultado = new ImportadorDeDxf()->importar($this->bloque, $dxf, opcionesDeLineasSueltas());

        expect($resultado->lotesCreados)->toBe(2)
            ->and(implode(' ', $resultado->advertencias))->toContain('no tienen contorno propio');
    });

    test('si siguiendo las lineas no sale ningun lote, lo dice antes de crear nada', function (): void {
        $dxf = dxfDeLineas(rectanguloDeLineas(0.0, 0.0, 20.0, 20.0), []);

        expect(fn (): ResultadoDeImportacion => new ImportadorDeDxf()->importar($this->bloque, $dxf, opcionesDeLineasSueltas()))
            ->toThrow(GeneracionDeLotesException::class, 'no se armo ningun lote');

        expect(Lote::query()->count())->toBe(0);
    });
});
