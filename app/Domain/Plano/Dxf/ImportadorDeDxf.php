<?php

declare(strict_types=1);

namespace App\Domain\Plano\Dxf;

use App\Domain\Enums\EstadoLote;
use App\Domain\Enums\TipoCalle;
use App\Domain\Exceptions\GeneracionDeLotesException;
use App\Models\Bloque;
use App\Models\Calle;
use App\Models\Lote;
use Illuminate\Support\Facades\DB;

/**
 * Convierte un plano de AutoCAD en lotes y calles del sistema.
 *
 * Es la respuesta a la pregunta 15 de docs/dominio.md —como llegan los
 * ~500 lotes— cuando la respuesta es "en el plano". En vez de digitar
 * quinientas filas a mano, se lee el archivo que ya hizo el topografo.
 *
 * TRES CONVERSIONES QUE HAY QUE HACER BIEN O EL PLANO SALE MAL:
 *
 * 1. EL EJE Y SE INVIERTE. En CAD la Y crece hacia el norte; en SVG crece
 *    hacia abajo. Sin invertir, el plano queda reflejado y el lote de la
 *    esquina noreste aparece en la sureste.
 *
 * 2. LAS COORDENADAS SE LLEVAN AL ORIGEN. Un plano en UTM tiene abscisas
 *    de seis o siete digitos; dibujarlo tal cual da un viewBox absurdo. Se
 *    resta la esquina de la caja que encierra todo el dibujo.
 *
 * 3. LAS UNIDADES SE PASAN A VARAS, que es la unidad del negocio. El
 *    factor lo decide quien importa, no el archivo: ver OpcionesDeImportacion.
 *
 * Las tres se aplican con UNA sola transformacion global, calculada sobre
 * lotes y calles juntos. Si cada capa usara la suya, el plano quedaria
 * despedazado.
 *
 * ═══ POR QUE UN PLANO DE VARIAS MANZANAS ENTRA DE UNA SOLA VEZ ═══
 *
 * Esa transformacion es tambien la razon por la que existe la opcion
 * `bloquePorRotulo` (13-ago-2026, con el plano de EL BAMBU: 84 lotes en
 * seis manzanas, A1..A36, B1..B7, C1..C8, D1..D17, E1..E8, F1..F8).
 *
 * La salida obvia —partir el DXF y hacer seis importaciones, una por
 * bloque— NO funciona: cada importacion calcula su propio minX/maxY y
 * lleva SUS lotes al origen, asi que las seis manzanas terminan apiladas
 * una encima de la otra en la esquina del plano. Para que las posiciones
 * relativas sobrevivan, los 84 tienen que pasar por la MISMA
 * transformacion, y entonces el reparto en bloques tiene que hacerlo el
 * importador leyendo la letra del rotulo.
 */
final readonly class ImportadorDeDxf
{
    /** Contornos con menos area que esto, en varas cuadradas, se descartan. */
    private const float AREA_MINIMA_VARAS = 0.5;

    public function __construct(
        private LectorDxf $lector = new LectorDxf,
        private ExtractorDeGeometria $extractor = new ExtractorDeGeometria,
    ) {}

    public function analizar(string $contenido): AnalisisDeDxf
    {
        $archivo = $this->lector->leer($contenido);
        $poligonos = $this->extractor->poligonos($archivo);
        $rotulos = $this->extractor->rotulos($archivo);

        /** @var array<string, array{contornos: int, rotulos: int, area: float}> $capas */
        $capas = [];
        $puntos = [];
        $espejados = 0;

        foreach ($poligonos as $poligono) {
            $capas[$poligono->capa] ??= ['contornos' => 0, 'rotulos' => 0, 'area' => 0.0];
            $capas[$poligono->capa]['contornos']++;
            $capas[$poligono->capa]['area'] += $poligono->area();

            if ($poligono->espejado) {
                $espejados++;
            }

            foreach ($poligono->puntos as $punto) {
                $puntos[] = $punto;
            }
        }

        foreach ($rotulos as $rotulo) {
            $capas[$rotulo->capa] ??= ['contornos' => 0, 'rotulos' => 0, 'area' => 0.0];
            $capas[$rotulo->capa]['rotulos']++;
        }

        uasort($capas, static fn (array $a, array $b): int => $b['contornos'] <=> $a['contornos']);

        return new AnalisisDeDxf(
            unidadDeclarada: $archivo->unidades(),
            capas: $capas,
            tipos: $archivo->conteoPorTipo(),
            caja: GeometriaPlana::caja($puntos),
            bloquesInsertados: $archivo->bloquesInsertados(),
            contornosEspejados: $espejados,
        );
    }

    public function importar(Bloque $bloque, string $contenido, OpcionesDeImportacion $opciones): ResultadoDeImportacion
    {
        $archivo = $this->lector->leer($contenido);
        $poligonos = $this->extractor->poligonos($archivo);
        $rotulos = $this->extractor->rotulos($archivo);

        $deLotes = array_values(array_filter(
            $poligonos,
            static fn (PoligonoDxf $p): bool => $opciones->usaCapa($p->capa, $opciones->capaDeLotes)
        ));

        $deCalles = $opciones->capaDeCalles === null ? [] : array_values(array_filter(
            $poligonos,
            static fn (PoligonoDxf $p): bool => $opciones->usaCapa($p->capa, $opciones->capaDeCalles)
        ));

        if ($deLotes === []) {
            throw GeneracionDeLotesException::porCapaSinContornos($opciones->capaDeLotes);
        }

        $transformar = $this->transformacion($deLotes, $deCalles, $opciones->varasPorUnidad());

        $proyectoId = (int) $bloque->getAttribute('proyecto_id');
        $bloquePorDefecto = (string) $bloque->getAttribute('nombre');

        $candidatos = $this->candidatos($rotulos, $opciones);
        $bloquesDelProyecto = $this->bloquesDelProyecto($proyectoId);
        $usados = $this->numerosOcupadosPorBloque($bloquesDelProyecto);

        $advertencias = [];
        $sinRotulo = 0;
        $descartados = 0;
        $repetidos = 0;
        $conLetra = 0;
        $areaTotal = 0.0;

        /** @var list<array{bloque: string, numero: string, area: string, puntos: list<array{float, float}>}> $planificados */
        $planificados = [];

        foreach ($deLotes as $poligono) {
            $puntos = array_map($transformar, $poligono->puntos);
            $area = GeometriaPlana::area($puntos);

            if ($area < self::AREA_MINIMA_VARAS) {
                $descartados++;

                continue;
            }

            $rotulo = $this->rotuloPara($poligono, $candidatos);
            $numero = $rotulo === null ? null : $rotulo['numero'];
            $destino = $bloquePorDefecto;

            /*
             * La letra del rotulo manda sobre el bloque elegido en el
             * formulario, y solo si se pidio: un plano rotulado "12B"
             * —que es como el propio sistema dibuja el mapa— diria bloque
             * "12" con la opcion prendida sin querer. Ver RotuloDxf.
             */
            if ($opciones->bloquePorRotulo && $rotulo !== null && $rotulo['bloque'] !== null) {
                $destino = $rotulo['bloque'];
                $conLetra++;
            }

            $usados[$destino] ??= [];

            if ($numero === null) {
                $sinRotulo++;
                $numero = $this->siguienteLibre($usados[$destino]);
            }

            if (isset($usados[$destino][$numero])) {
                $repetidos++;
                $numero = $this->siguienteLibre($usados[$destino]);
            }

            $usados[$destino][$numero] = true;
            $areaTotal += $area;

            $planificados[] = [
                'bloque' => $destino,
                'numero' => $numero,
                // Unico lugar del sistema donde un area nace de un float:
                // la fuente es geometria y no hay otra manera. Se fija a los
                // 4 decimales de la columna en el momento de cruzar la
                // frontera, y de ahi en adelante es string (§8.3.1).
                'area'   => number_format($area, 4, '.', ''),
                'puntos' => $puntos,
            ];
        }

        /** @var array<string, int> $lotesPorBloque */
        $lotesPorBloque = [];

        foreach ($planificados as $plan) {
            $lotesPorBloque[$plan['bloque']] = ($lotesPorBloque[$plan['bloque']] ?? 0) + 1;
        }

        // Por nombre y no por orden de aparicion en el DXF: el aviso lo lee
        // una persona, y "36 en A, 7 en B" se contrasta con el plano impreso.
        ksort($lotesPorBloque);

        if ($repetidos > 0) {
            $advertencias[] = "{$repetidos} numeros ya estaban ocupados en su bloque y se renumeraron. ".
                'Suele significar que el plano se importo dos veces.';
        }

        if ($sinRotulo > 0) {
            $advertencias[] = "{$sinRotulo} lotes no tenian rotulo adentro y se numeraron correlativamente.";
        }

        if ($descartados > 0) {
            $advertencias[] = "{$descartados} contornos se descartaron por tener area despreciable.";
        }

        if ($opciones->bloquePorRotulo && $conLetra === 0) {
            $advertencias[] = "Ningun rotulo traia la letra de su bloque, asi que todo entro en {$bloquePorDefecto}. ".
                'Revisa que la capa de los numeros sea la correcta.';
        }

        $creados = 0;
        $calles = 0;
        /** @var list<string> $bloquesCreados */
        $bloquesCreados = [];

        DB::transaction(function () use ($bloque, $bloquesDelProyecto, $lotesPorBloque, $planificados, $deCalles, $opciones, $transformar, &$creados, &$calles, &$bloquesCreados): void {
            $destinos = $this->bloquesDestino($bloque, $bloquesDelProyecto, $lotesPorBloque, $bloquesCreados);

            foreach ($planificados as $plan) {
                Lote::query()->create([
                    'proyecto_id' => $bloque->getAttribute('proyecto_id'),
                    'bloque_id'   => $destinos[$plan['bloque']]->getKey(),
                    'numero'      => $plan['numero'],
                    'area_varas'  => $plan['area'],
                    'precio_vara' => $opciones->precioVara,
                    'estado'      => EstadoLote::Disponible,
                    'poligono'    => $plan['puntos'],
                ]);

                $creados++;
            }

            foreach ($deCalles as $poligono) {
                $puntos = array_map($transformar, $poligono->puntos);

                if (GeometriaPlana::area($puntos) < self::AREA_MINIMA_VARAS) {
                    continue;
                }

                Calle::query()->create([
                    'proyecto_id' => $bloque->getAttribute('proyecto_id'),
                    'tipo'        => TipoCalle::Calle,
                    'poligono'    => $puntos,
                ]);

                $calles++;
            }
        });

        if ($creados > 0) {
            /*
             * Un plano importado de un DXF viene del documento del
             * topografo, asi que deja de ser un esquema. Es la unica
             * operacion del sistema que APAGA esa marca sola; el
             * acomodador solo la enciende.
             */
            $bloque->proyecto()->update(['plano_esquematico' => false]);
        }

        return new ResultadoDeImportacion(
            lotesCreados: $creados,
            callesCreadas: $calles,
            sinRotulo: $sinRotulo,
            descartados: $descartados,
            areaTotalVaras: $areaTotal,
            advertencias: $advertencias,
            lotesPorBloque: $lotesPorBloque,
            bloquesCreados: $bloquesCreados,
        );
    }

    /**
     * La transformacion global: invertir Y, llevar al origen y pasar a varas.
     *
     * @param list<PoligonoDxf> $lotes
     * @param list<PoligonoDxf> $calles
     *
     * @return callable(array{float, float}): array{float, float}
     */
    private function transformacion(array $lotes, array $calles, float $varasPorUnidad): callable
    {
        $puntos = [];

        foreach ([...$lotes, ...$calles] as $poligono) {
            foreach ($poligono->puntos as $punto) {
                $puntos[] = $punto;
            }
        }

        $caja = GeometriaPlana::caja($puntos) ?? [0.0, 0.0, 0.0, 0.0];
        [$minX, , , $maxY] = $caja;

        return static fn (array $punto): array => [
            round(($punto[0] - $minX) * $varasPorUnidad, 4),
            round(($maxY - $punto[1]) * $varasPorUnidad, 4),
        ];
    }

    /**
     * Los rotulos que SI nombran un lote, leidos una sola vez.
     *
     * Se resuelven antes del bucle y no adentro porque rotuloPara()
     * recorre todos los rotulos por cada contorno: en el plano de EL BAMBU
     * son 84 x 540 lecturas del mismo texto, que no cambia entre una y
     * otra. Aca tambien queda filtrada la capa, que es constante.
     *
     * @param list<RotuloDxf> $rotulos
     *
     * @return list<array{numero: string, bloque: ?string, x: float, y: float}>
     */
    private function candidatos(array $rotulos, OpcionesDeImportacion $opciones): array
    {
        $candidatos = [];

        foreach ($rotulos as $rotulo) {
            if ($opciones->capaDeRotulos !== null && ! $opciones->usaCapa($rotulo->capa, $opciones->capaDeRotulos)) {
                continue;
            }

            $numero = $rotulo->numeroDeLote();

            if ($numero === null) {
                continue;
            }

            $candidatos[] = [
                'numero' => $numero,
                'bloque' => $rotulo->bloqueDeLote(),
                'x'      => $rotulo->x,
                'y'      => $rotulo->y,
            ];
        }

        return $candidatos;
    }

    /**
     * El rotulo que le corresponde al contorno, o null si no hay ninguno.
     *
     * Si hay varios adentro, gana el mas cercano al centro: en un plano
     * suele haber tambien el area rotulada, y el numero va al medio.
     *
     * @param list<array{numero: string, bloque: ?string, x: float, y: float}> $candidatos
     *
     * @return array{numero: string, bloque: ?string}|null
     */
    private function rotuloPara(PoligonoDxf $poligono, array $candidatos): ?array
    {
        [$centroX, $centroY] = $poligono->centro();

        $mejor = null;
        $distanciaMenor = INF;

        foreach ($candidatos as $candidato) {
            if (! $poligono->contiene($candidato['x'], $candidato['y'])) {
                continue;
            }

            $distancia = hypot($candidato['x'] - $centroX, $candidato['y'] - $centroY);

            if ($distancia < $distanciaMenor) {
                $distanciaMenor = $distancia;
                $mejor = ['numero' => $candidato['numero'], 'bloque' => $candidato['bloque']];
            }
        }

        return $mejor;
    }

    /**
     * Los bloques que ya tiene el proyecto, por nombre.
     *
     * @return array<string, Bloque>
     */
    private function bloquesDelProyecto(int $proyectoId): array
    {
        $porNombre = [];

        foreach (Bloque::query()->where('proyecto_id', $proyectoId)->orderBy('orden')->get() as $bloque) {
            $porNombre[(string) $bloque->getAttribute('nombre')] = $bloque;
        }

        return $porNombre;
    }

    /**
     * A que bloque va a parar cada nombre del plan, creando los que falten.
     *
     * Corre DENTRO de la transaccion: si la creacion de un lote revienta,
     * los bloques que se hayan creado para el se van con ella y no queda
     * un bloque vacio de recuerdo.
     *
     * `lotes_planificados` se escribe solo en los que nacen aca —es lo que
     * dice el plano que trae ese bloque— y nunca se pisa en uno que ya
     * existia, porque ese numero pudo haberlo declarado alguien a mano.
     *
     * @param array<string, Bloque> $existentes
     * @param array<string, int> $lotesPorBloque
     * @param list<string> $creados
     *
     * @return array<string, Bloque>
     */
    private function bloquesDestino(Bloque $elegido, array $existentes, array $lotesPorBloque, array &$creados): array
    {
        $proyectoId = (int) $elegido->getAttribute('proyecto_id');
        $nombres = array_keys($lotesPorBloque);
        sort($nombres);

        $orden = 0;

        foreach ($existentes as $bloque) {
            $orden = max($orden, (int) $bloque->getAttribute('orden'));
        }

        $destinos = [];

        foreach ($nombres as $nombre) {
            if (isset($existentes[$nombre])) {
                $destinos[$nombre] = $existentes[$nombre];

                continue;
            }

            $orden++;

            $destinos[$nombre] = Bloque::query()->create([
                'proyecto_id'        => $proyectoId,
                'nombre'             => $nombre,
                'orden'              => $orden,
                'lotes_planificados' => $lotesPorBloque[$nombre],
            ]);

            $creados[] = $nombre;
        }

        return $destinos;
    }

    /**
     * @param array<string, Bloque> $bloques
     *
     * @return array<string, array<string, bool>>
     */
    private function numerosOcupadosPorBloque(array $bloques): array
    {
        $ocupados = [];

        foreach ($bloques as $nombre => $bloque) {
            $ocupados[$nombre] = $this->numerosOcupados($bloque);
        }

        return $ocupados;
    }

    /**
     * @return array<string, bool>
     */
    private function numerosOcupados(Bloque $bloque): array
    {
        $ocupados = [];

        foreach ($bloque->lotes()->pluck('numero') as $numero) {
            if (is_string($numero)) {
                $ocupados[$numero] = true;
            }
        }

        return $ocupados;
    }

    /**
     * @param array<string, bool> $usados
     */
    private function siguienteLibre(array $usados): string
    {
        $candidato = 1;

        while (isset($usados[(string) $candidato])) {
            $candidato++;
        }

        return (string) $candidato;
    }
}
