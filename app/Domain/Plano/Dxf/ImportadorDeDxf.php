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

        $advertencias = [];
        $usados = $this->numerosOcupados($bloque);
        $sinRotulo = 0;
        $descartados = 0;
        $repetidos = 0;
        $areaTotal = 0.0;

        /** @var list<array{numero: string, area: string, puntos: list<array{float, float}>}> $planificados */
        $planificados = [];

        foreach ($deLotes as $poligono) {
            $puntos = array_map($transformar, $poligono->puntos);
            $area = GeometriaPlana::area($puntos);

            if ($area < self::AREA_MINIMA_VARAS) {
                $descartados++;

                continue;
            }

            $numero = $this->numeroPara($poligono, $rotulos, $opciones);

            if ($numero === null) {
                $sinRotulo++;
                $numero = $this->siguienteLibre($usados);
            }

            if (isset($usados[$numero])) {
                $repetidos++;
                $numero = $this->siguienteLibre($usados);
            }

            $usados[$numero] = true;
            $areaTotal += $area;

            $planificados[] = [
                'numero' => $numero,
                // Unico lugar del sistema donde un area nace de un float:
                // la fuente es geometria y no hay otra manera. Se fija a los
                // 4 decimales de la columna en el momento de cruzar la
                // frontera, y de ahi en adelante es string (§8.3.1).
                'area'   => number_format($area, 4, '.', ''),
                'puntos' => $puntos,
            ];
        }

        if ($repetidos > 0) {
            $advertencias[] = "{$repetidos} numeros ya estaban ocupados en el bloque y se renumeraron. ".
                'Suele significar que el plano se importo dos veces.';
        }

        if ($sinRotulo > 0) {
            $advertencias[] = "{$sinRotulo} lotes no tenian rotulo adentro y se numeraron correlativamente.";
        }

        if ($descartados > 0) {
            $advertencias[] = "{$descartados} contornos se descartaron por tener area despreciable.";
        }

        $creados = 0;
        $calles = 0;

        DB::transaction(function () use ($bloque, $planificados, $deCalles, $opciones, $transformar, &$creados, &$calles): void {
            foreach ($planificados as $plan) {
                Lote::query()->create([
                    'proyecto_id' => $bloque->getAttribute('proyecto_id'),
                    'bloque_id'   => $bloque->getKey(),
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
     * Numero de lote a partir del rotulo que cae adentro del contorno.
     *
     * Si hay varios, gana el mas cercano al centro: en un plano suele
     * haber tambien el area rotulada, y el numero va al medio.
     *
     * @param list<RotuloDxf> $rotulos
     */
    private function numeroPara(PoligonoDxf $poligono, array $rotulos, OpcionesDeImportacion $opciones): ?string
    {
        [$centroX, $centroY] = $poligono->centro();

        $mejor = null;
        $distanciaMenor = INF;

        foreach ($rotulos as $rotulo) {
            if ($opciones->capaDeRotulos !== null && ! $opciones->usaCapa($rotulo->capa, $opciones->capaDeRotulos)) {
                continue;
            }

            if ($rotulo->numeroDeLote() === null) {
                continue;
            }

            if (! $poligono->contiene($rotulo->x, $rotulo->y)) {
                continue;
            }

            $distancia = hypot($rotulo->x - $centroX, $rotulo->y - $centroY);

            if ($distancia < $distanciaMenor) {
                $distanciaMenor = $distancia;
                $mejor = $rotulo->numeroDeLote();
            }
        }

        return $mejor;
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
