<?php

declare(strict_types=1);

namespace App\Domain\Plano;

use App\Domain\Enums\EstadoLote;
use App\Domain\Enums\TipoCalle;
use App\Models\Bloque;
use App\Models\Calle;
use App\Models\Compromiso;
use App\Models\Lote;
use App\Models\Proyecto;

/**
 * Arma todo lo que el plano necesita para dibujarse.
 *
 * Vive en el dominio y no en la pagina de Filament porque el encuadre y
 * el armado de poligonos son logica con casos borde reales —proyecto sin
 * geometria, lotes a medio dibujar, calles que sobresalen del bloque— y
 * eso se testea sin levantar Livewire.
 *
 * El sistema de coordenadas ya es el de SVG (origen arriba-izquierda, Y
 * hacia abajo), asi que aca no se invierte ningun eje. Ver la migracion.
 */
final readonly class PlanoDelProyecto
{
    /** Aire alrededor del dibujo, en varas, para que no quede pegado al borde. */
    private const float MARGEN_VARAS = 5.0;

    /** Encuadre cuando todavia no hay nada dibujado. */
    private const string VIEWBOX_VACIO = '0 0 100 100';

    /**
     * Donde vive el calco del plano original de un proyecto, si lo hay.
     *
     * Es el dibujo del topografo tal cual —linderos, calles, areas verdes,
     * la cancha, y los rotulos con area y numero escritos por el— en las
     * MISMAS coordenadas en varas que los poligonos. Va encima del color:
     * lo que se pinta por estado y se clickea siguen siendo los lotes de
     * la base.
     *
     * Existe porque reconstruir un plano nunca sale completo. Con el calco
     * puesto, un lote que el extractor no logro cerrar igual se ve.
     */
    private const string CARPETA_DE_CALCOS = 'planos';

    /**
     * @return array{
     *     viewBox: string,
     *     calco: string|null,
     *     hayGeometria: bool,
     *     esquematico: bool,
     *     sinDibujar: int,
     *     resumen: array<string, int>,
     *     lotes: list<array{id: int, codigo: string, numero: string, bloque: string, rotulo: string, estado: string, etiqueta: string, color: string, puntos: string, centro: array{float, float}, cliente: string|null, areaVaras: string, valor: string, valorFormateado: string, desalineado: bool}>,
     *     calles: list<array{nombre: string|null, tipo: string, etiqueta: string, ancho: float, esArea: bool, puntos: string}>
     * }
     */
    public function para(Proyecto $proyecto): array
    {
        // with('bloque'): la letra del bloque va en el rotulo de cada
        // lote, y preguntarsela al lote uno por uno es un N+1 de manual
        // sobre 300 filas (§4.L4).
        /** @var list<Lote> $lotes */
        $lotes = Lote::query()
            ->delProyecto($proyecto)
            ->with('bloque')
            ->orderBy('codigo')
            ->get()
            ->all();

        /** @var list<Calle> $calles */
        $calles = Calle::query()->delProyecto($proyecto)->orderBy('orden')->get()->all();

        /*
         * Una sola consulta para todos los compromisos vigentes del
         * proyecto. Preguntarle a cada lote por el suyo seria un N+1 de
         * manual sobre 500 filas (§4.L4).
         */
        $comprometidos = Compromiso::query()
            ->delProyecto($proyecto)
            ->vigentes()
            ->with('cliente')
            ->get()
            ->keyBy('lote_id');

        $lotesDibujados = [];
        $sinDibujar = 0;
        $puntosParaEncuadre = [];

        foreach ($lotes as $lote) {
            $vertices = $lote->verticesPoligono();

            if ($vertices === []) {
                $sinDibujar++;

                continue;
            }

            $estado = $lote->getAttribute('estado');
            $estado = $estado instanceof EstadoLote ? $estado : EstadoLote::Disponible;

            foreach ($vertices as $vertice) {
                $puntosParaEncuadre[] = $vertice;
            }

            $bloque = $lote->getRelationValue('bloque');
            $nombreBloque = $bloque instanceof Bloque
                ? (string) $bloque->getAttribute('nombre')
                : '';

            $compromiso = $comprometidos->get($lote->getKey());

            $cliente = $compromiso instanceof Compromiso
                ? $this->textoOpcional($compromiso->cliente?->getAttribute('nombre'))
                : null;

            $lotesDibujados[] = [
                'id'              => (int) $lote->getKey(),
                'codigo'          => (string) $lote->getAttribute('codigo'),
                'numero'          => (string) $lote->getAttribute('numero'),
                'bloque'          => $nombreBloque,
                'rotulo'          => Lote::componerRotulo($nombreBloque, (string) $lote->getAttribute('numero')),
                'estado'          => $estado->value,
                'etiqueta'        => $estado->etiqueta(),
                'color'           => $estado->colorHex(),
                'puntos'          => $this->comoPuntosSvg($vertices),
                'centro'          => $this->centroDe($vertices),
                'cliente'         => $cliente,
                'areaVaras'       => (string) $lote->getAttribute('area_varas'),
                'valor'           => (string) $lote->getAttribute('valor'),
                'valorFormateado' => $lote->montoValor()->formateado(),
                'desalineado'     => $lote->poligonoDesalineado(),
            ];
        }

        $callesDibujadas = [];

        foreach ($calles as $calle) {
            /*
             * Una calle viene de dos formas: importada de un plano es un
             * AREA (su poligono), dibujada a mano es un EJE con ancho. Un
             * area necesita tres vertices para existir; un eje, dos.
             */
            $esArea = $calle->esArea();
            $puntos = $esArea ? $calle->verticesDelArea() : $calle->puntos();
            $minimo = $esArea ? 3 : 2;

            if (count($puntos) < $minimo) {
                continue;
            }

            $ancho = (float) (string) $calle->getAttribute('ancho_varas');
            $tipo = $calle->getAttribute('tipo');

            if ($esArea) {
                foreach ($puntos as $punto) {
                    $puntosParaEncuadre[] = $punto;
                }
            } else {
                // El trazo se pinta grueso, asi que el encuadre tiene que
                // contemplar medio ancho a cada lado o la calle del borde
                // aparece cortada por la mitad.
                foreach ($puntos as $punto) {
                    $puntosParaEncuadre[] = [$punto[0] - $ancho / 2, $punto[1] - $ancho / 2];
                    $puntosParaEncuadre[] = [$punto[0] + $ancho / 2, $punto[1] + $ancho / 2];
                }
            }

            $callesDibujadas[] = [
                'nombre'   => $this->textoOpcional($calle->getAttribute('nombre')),
                'tipo'     => $tipo instanceof TipoCalle ? $tipo->value : 'calle',
                'etiqueta' => $tipo instanceof TipoCalle ? $tipo->etiqueta() : 'Calle',
                'ancho'    => $ancho,
                'esArea'   => $esArea,
                'puntos'   => $this->comoPuntosSvg($puntos),
            ];
        }

        return [
            'viewBox'      => $this->encuadre($puntosParaEncuadre),
            'calco'        => $this->calcoDe($proyecto),
            'hayGeometria' => $puntosParaEncuadre !== [],
            'esquematico'  => (bool) $proyecto->getAttribute('plano_esquematico'),
            'sinDibujar'   => $sinDibujar,
            'resumen'      => $this->resumen($lotes),
            'lotes'        => $lotesDibujados,
            'calles'       => $callesDibujadas,
        ];
    }

    /**
     * Conteo por estado, con los cuatro estados siempre presentes.
     *
     * Un estado en cero tiene que aparecer igual: "0 vendidos" es
     * informacion, y una leyenda que cambia de tamano segun el dia es
     * mas dificil de leer que una fija.
     *
     * @param list<Lote> $lotes
     *
     * @return array<string, int>
     */
    private function resumen(array $lotes): array
    {
        $resumen = array_fill_keys(EstadoLote::valores(), 0);

        foreach ($lotes as $lote) {
            $estado = $lote->getAttribute('estado');

            if ($estado instanceof EstadoLote) {
                $resumen[$estado->value]++;
            }
        }

        return $resumen;
    }

    /**
     * URL del calco del proyecto, o null si no tiene.
     *
     * Se busca por codigo de proyecto en minusculas: `RPS` ->
     * `public/planos/rps-fondo.json`. No se valida el contenido aca; si el
     * archivo esta roto lo unico que pasa es que el fondo no se dibuja y
     * los lotes se ven igual.
     */
    private function calcoDe(Proyecto $proyecto): ?string
    {
        $codigo = $proyecto->getAttribute('codigo');

        if (! is_string($codigo) || $codigo === '') {
            return null;
        }

        $relativa = self::CARPETA_DE_CALCOS.'/'.mb_strtolower($codigo).'-fondo.json';

        return is_file(public_path($relativa)) ? asset($relativa) : null;
    }

    /**
     * @param list<array{float, float}> $puntos
     */
    private function encuadre(array $puntos): string
    {
        if ($puntos === []) {
            return self::VIEWBOX_VACIO;
        }

        $xs = array_map(static fn (array $punto): float => $punto[0], $puntos);
        $ys = array_map(static fn (array $punto): float => $punto[1], $puntos);

        $minX = min($xs) - self::MARGEN_VARAS;
        $minY = min($ys) - self::MARGEN_VARAS;

        // max(..., 1.0): un proyecto con un solo lote degenerado daria
        // ancho cero y el navegador no dibujaria absolutamente nada.
        $ancho = max(max($xs) - min($xs) + self::MARGEN_VARAS * 2, 1.0);
        $alto = max(max($ys) - min($ys) + self::MARGEN_VARAS * 2, 1.0);

        return sprintf(
            '%s %s %s %s',
            $this->numero($minX),
            $this->numero($minY),
            $this->numero($ancho),
            $this->numero($alto),
        );
    }

    /**
     * @param list<array{float, float}> $puntos
     */
    private function comoPuntosSvg(array $puntos): string
    {
        return implode(' ', array_map(
            fn (array $punto): string => $this->numero($punto[0]).','.$this->numero($punto[1]),
            $puntos
        ));
    }

    /**
     * Centro para colgar la etiqueta con el numero de lote.
     *
     * Es el promedio de los vertices, no el centroide real del poligono.
     * Para rectangulos coinciden, y para una forma irregular la etiqueta
     * queda igual de bien puesta sin arrastrar la formula completa.
     *
     * @param list<array{float, float}> $puntos
     *
     * @return array{float, float}
     */
    private function centroDe(array $puntos): array
    {
        $total = count($puntos);

        $x = array_sum(array_map(static fn (array $p): float => $p[0], $puntos)) / $total;
        $y = array_sum(array_map(static fn (array $p): float => $p[1], $puntos)) / $total;

        return [round($x, 3), round($y, 3)];
    }

    /**
     * Recorta decimales inutiles: el SVG no gana nada con 14 cifras y el
     * HTML de un plano de 500 lotes pesa bastante menos.
     */
    private function numero(float $valor): string
    {
        return rtrim(rtrim(number_format($valor, 3, '.', ''), '0'), '.');
    }

    private function textoOpcional(mixed $valor): ?string
    {
        return is_string($valor) && $valor !== '' ? $valor : null;
    }
}
