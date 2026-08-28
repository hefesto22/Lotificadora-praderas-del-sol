<?php

declare(strict_types=1);

namespace App\Domain\Plano;

use App\Domain\Enums\EstadoLote;
use App\Domain\Enums\EstadoVenta;
use App\Domain\Enums\TipoCalle;
use App\Domain\Enums\UnidadDeArea;
use App\Domain\Plano\Dxf\GeometriaPlana;
use App\Domain\Ventas\CarteraDelPlano;
use App\Models\Bloque;
use App\Models\Calle;
use App\Models\Compromiso;
use App\Models\Lote;
use App\Models\Proyecto;
use App\Models\Venta;

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
     *     donaciones: array{activas: bool, cupo: int, hechas: int, quedan: int, puede: bool},
     *     herencia: array{activa: bool, cupo: int, guardados: int, quedan: int, puede: bool},
     *     medidas: array{enMetros: bool, varaEnMetros: float, factor: float, unidad: string, pie: string, area: string, areaCorta: string, areaAbreviada: string, porUnidad: string, dosUnidades: bool},
     *     lotes: list<array{id: int, codigo: string, numero: string, bloque: string, rotulo: string, estado: string, etiqueta: string, seDeshace: bool, seReserva: bool, seDeshaceReserva: bool, color: string, puntos: string, centro: array{float, float}, cliente: string|null, cartera: array{venta: int, contrato: string, lotes: int, saldo: string, proximaCuota: string|null, vencidas: int, alDia: bool, seCobra: bool}|null, areaVaras: string, areaMetros: string, valor: string, valorFormateado: string, desalineado: bool, foto360: string|null, foto360Mini: string|null, foto360Marcas: list<array<string, mixed>>}>,
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
            ->with(['cliente', 'venta'])
            ->get()
            ->keyBy('lote_id');

        /*
         * Cuanto debe cada contrato, para el panel del lote vendido. Dos
         * consultas agregadas para TODO el proyecto — ver CarteraDelPlano,
         * que explica por que los numeros son del contrato y no del lote.
         *
         * `lotesPorVenta` sale del arreglo que ya esta en memoria: cuantos
         * lotes lleva cada contrato no cuesta una consulta mas.
         */
        $lotesPorVenta = [];

        foreach ($comprometidos as $compromiso) {
            $venta = $compromiso->getAttribute('venta_id');

            if (is_int($venta)) {
                $lotesPorVenta[$venta] = ($lotesPorVenta[$venta] ?? 0) + 1;
            }
        }

        $cartera = CarteraDelPlano::de(array_keys($lotesPorVenta));

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
                'id'       => (int) $lote->getKey(),
                'codigo'   => (string) $lote->getAttribute('codigo'),
                'numero'   => (string) $lote->getAttribute('numero'),
                'bloque'   => $nombreBloque,
                'rotulo'   => Lote::componerRotulo($nombreBloque, (string) $lote->getAttribute('numero')),
                'estado'   => $estado->value,
                'etiqueta' => $estado->etiquetaInterna(),
                /*
                 * Que se pueda vender lo decide el ENUM, no el panel. Antes el
                 * blade preguntaba `estado !== 'vendido'` y por eso un lote
                 * reservado —y ahora uno donado— ofrecía «Vender este lote».
                 */
                'seVende'          => $estado->seVende(),
                'seDona'           => $estado->seDona(),
                'seDeshace'        => $estado->seDeshaceLaDonacion(),
                'seReserva'        => $estado->seReserva(),
                'seDeshaceReserva' => $estado->seDeshaceLaReserva(),
                'porQueNoSeVende'  => $estado->porQueNoSeVende(),
                'color'            => $estado->colorHex(),
                'puntos'           => $this->comoPuntosSvg($vertices),
                'centro'           => $this->centroDe($vertices),
                'cliente'          => $cliente,
                'cartera'          => $this->carteraDe($compromiso, $cartera, $lotesPorVenta),
                'areaVaras'        => (string) $lote->getAttribute('area_varas'),
                'areaMetros'       => $this->enMetrosCuadrados(
                    (string) $lote->getAttribute('area_varas'),
                    $proyecto->varaEnMetros(),
                ),
                'valor'           => (string) $lote->getAttribute('valor'),
                'valorFormateado' => $lote->montoValor()->formateado(),
                'desalineado'     => $lote->poligonoDesalineado(),
                'foto360'         => $lote->foto360Url(),
                'foto360Mini'     => $lote->foto360MiniUrl(),
                'foto360Marcas'   => $lote->foto360Marcas(),
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
            'medidas'      => $this->medidasDe($proyecto),
            'sinDibujar'   => $sinDibujar,
            'resumen'      => $this->resumen($lotes),
            'donaciones'   => $this->donacionesDe($proyecto),
            'herencia'     => $this->herenciaDe($proyecto),
            'lotes'        => $lotesDibujados,
            'calles'       => $callesDibujadas,
        ];
    }

    /**
     * El cupo de donaciones, para que el plano sepa si dibuja el botón.
     *
     * Va en el payload y no se resuelve en el blade porque es una regla
     * del negocio —cuántos lotes decidió regalar la lotificadora— y no una
     * decisión de presentación. El botón es la consecuencia, no la regla.
     *
     * @return array{activas: bool, cupo: int, hechas: int, quedan: int, puede: bool}
     */
    private function donacionesDe(Proyecto $proyecto): array
    {
        return [
            'activas' => $proyecto->donaLotes(),
            'cupo'    => $proyecto->cupoDeDonaciones(),
            'hechas'  => $proyecto->lotesDonados(),
            'quedan'  => $proyecto->donacionesQueQuedan(),
            'puede'   => $proyecto->puedeDonarOtroLote(),
        ];
    }

    /**
     * Lo que debe el contrato de este lote, o null si no hay nada que cobrar.
     *
     * Null en tres casos, y los tres son «acá no hay cartera»: el lote no
     * tiene compromiso vigente, el compromiso no cuelga de una venta —un
     * apartado, una donación— o la venta no dejó ni una cuota.
     *
     * ⚠️ Los números son DEL CONTRATO. Ver CarteraDelPlano: el recibo
     * también lo es, y dos números de la misma pantalla que no quieren
     * decir lo mismo son un error esperando a que alguien atienda apurado.
     * Por eso viaja `lotes`: cuando son varios, el panel lo dice.
     *
     * @param array<int, array{saldo: string, vencidas: int, proximaCuota: string|null, alDia: bool}> $cartera
     * @param array<int, int> $lotesPorVenta
     *
     * @return array{venta: int, contrato: string, lotes: int, saldo: string, proximaCuota: string|null, vencidas: int, alDia: bool, seCobra: bool}|null
     */
    private function carteraDe(?Compromiso $compromiso, array $cartera, array $lotesPorVenta): ?array
    {
        if (! $compromiso instanceof Compromiso) {
            return null;
        }

        $id = $compromiso->getAttribute('venta_id');

        if (! is_int($id) || ! array_key_exists($id, $cartera)) {
            return null;
        }

        $venta = $compromiso->getRelationValue('venta');
        $estado = $venta instanceof Venta ? $venta->getAttribute('estado') : null;
        $contrato = $venta instanceof Venta ? $venta->getAttribute('numero_contrato') : null;

        return [
            'venta'        => $id,
            'contrato'     => is_string($contrato) && $contrato !== '' ? $contrato : '—',
            'lotes'        => $lotesPorVenta[$id] ?? 1,
            'saldo'        => $cartera[$id]['saldo'],
            'proximaCuota' => $cartera[$id]['proximaCuota'],
            'vencidas'     => $cartera[$id]['vencidas'],
            'alDia'        => $cartera[$id]['alDia'],
            'seCobra'      => CarteraDelPlano::seCobra($estado instanceof EstadoVenta ? $estado : null),
        ];
    }

    /**
     * El cupo de herencia, para que el plano sepa si dibuja el botón.
     *
     * Gemelo de `donacionesDe()` y por la misma razón: cuántos lotes se
     * guardan para la familia es una regla del negocio, no una decisión de
     * presentación. El botón es la consecuencia.
     *
     * @return array{activa: bool, cupo: int, guardados: int, quedan: int, puede: bool}
     */
    private function herenciaDe(Proyecto $proyecto): array
    {
        return [
            'activa'    => $proyecto->reservaLotes(),
            'cupo'      => $proyecto->cupoDeReservas(),
            'guardados' => $proyecto->lotesReservados(),
            'quedan'    => $proyecto->reservasQueQuedan(),
            'puede'     => $proyecto->puedeReservarOtroLote(),
        ];
    }

    /**
     * En qué unidad se le enseñan las medidas de este proyecto a la gente.
     *
     * ═══ POR QUÉ ESTO EXISTE ═══
     *
     * El plano del topógrafo viene acotado en METROS: «25.05m», «17.98m».
     * El negocio compra, vende y cobra en VARAS². Un cliente parado frente
     * al plano impreso, con el teléfono en la mano, tiene que ver los
     * mismos números en los dos lados o la conversación se va a las manos.
     *
     * Se resuelve mostrando lo que dice el plano y guardando lo que se
     * cobra: las cotas de los lados salen en la unidad que elija el
     * proyecto, y el área sigue siendo en varas² —con los m² al lado,
     * igual que los rotula el topógrafo (A=320.19m2 / 459.22v2)—.
     *
     * `factor` viaja calculado para que el navegador no tenga que saber si
     * hay que convertir o no: multiplicar por 1 no convierte nada.
     *
     * @return array{enMetros: bool, varaEnMetros: float, factor: float, unidad: string, pie: string, area: string, areaCorta: string, areaAbreviada: string, porUnidad: string, dosUnidades: bool}
     */
    private function medidasDe(Proyecto $proyecto): array
    {
        $unidadDelArea = $proyecto->unidadDeArea();

        /*
         * Un proyecto que trabaja en metros² ya tiene todo en metros: las
         * cotas de los lados y el área. El toggle «mostrar las medidas en
         * metros» es de los proyectos en varas², donde el plano viene
         * acotado en una unidad y el negocio cobra en otra.
         */
        $enMetros = $unidadDelArea === UnidadDeArea::Metros
            || (bool) $proyecto->getAttribute('medidas_en_metros');

        $vara = (float) $proyecto->varaEnMetros();

        // `dosUnidades`: los m² al lado solo tienen sentido en varas². En
        // un proyecto en metros² serian el mismo numero dos veces.
        return [
            'enMetros'      => $enMetros,
            'varaEnMetros'  => $vara,
            'factor'        => $enMetros ? $vara : 1.0,
            'unidad'        => $enMetros ? 'm' : 'V',
            'area'          => $unidadDelArea->plural(),
            'areaCorta'     => $unidadDelArea->corta(),
            'areaAbreviada' => $unidadDelArea->abreviada(),
            'porUnidad'     => $unidadDelArea->porUnidad(),
            'dosUnidades'   => $unidadDelArea !== UnidadDeArea::Metros,
            'pie'           => $enMetros
                ? 'Medidas en metros, tomadas del plano del topógrafo.'
                : 'Medidas en varas, tomadas del plano del topógrafo.',
        ];
    }

    /**
     * El área de un lote en m², para mostrarla al lado de las varas².
     *
     * La cuenta pasa por bcmath y no por float aunque sea presentación:
     * son cuatro decimales por un factor de seis, y el número termina
     * impreso al lado de uno que sí es dinero. Un centavo de metro de
     * diferencia entre el sistema y el plano abre una discusión que no
     * tiene por qué existir.
     *
     * El `(float)` del final es solo para separar los miles: a esa altura
     * el número ya está redondeado a dos decimales y no vuelve a operarse.
     *
     * Los dos `is_numeric` no son paranoia: `bcmul` exige numeric-string y
     * los dos valores llegan como `string` —uno de una columna decimal, el
     * otro de la config o de la ficha del proyecto—. La guarda es lo que
     * convierte «podría no ser un número» en «es un número», acá y para
     * PHPStan.
     */
    private function enMetrosCuadrados(string $areaVaras, string $varaEnMetros): string
    {
        if (! is_numeric($areaVaras) || ! is_numeric($varaEnMetros)) {
            return '0.00';
        }

        $enMetros = bcmul(bcmul($areaVaras, $varaEnMetros, 12), $varaEnMetros, 12);

        // bcmath TRUNCA, no redondea: el medio centavo se suma a mano
        // antes de cortar, que es como redondea el resto del sistema.
        $redondeado = bcadd($enMetros, '0.005', 2);

        return number_format((float) $redondeado, 2, '.', ',');
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
     * ═══ 25-AGO-2026: ERA EL PROMEDIO DE LOS VERTICES, Y ESTABA MAL ═══
     *
     * Este docblock decia: «para una forma irregular la etiqueta queda
     * igual de bien puesta sin arrastrar la formula completa». Era verdad
     * mientras todos los lotes del sistema fueran cuadrilateros, y dejo de
     * serlo el dia que entro un plano con esquinas curvas.
     *
     * Un promedio de vertices pondera por CUANTOS hay. La pared curva de
     * un lote de esquina entra teselada en 30 o 60 vertices -ver
     * GeometriaPlana::GRADOS_POR_SEGMENTO- y se lleva el promedio hacia
     * ella. Lo reporto Mauricio mirando el plano de Altamira: «hay lotes
     * donde no se ve bien el numero que les corresponde». Medido: 64 de
     * 268 rotulos corridos mas de 1.5 m, y TRES fuera de su propio lote.
     * Como el rotulo se dibuja en blanco, afuera cae sobre la calle y no
     * se ve; el lote se queda sin numero.
     *
     * Con el centroide de area no queda ninguno afuera, y el mas apretado
     * tiene 4.60 m libres hasta su lindero. Praderas del Sol no se mueve:
     * la mediana de su corrimiento es cero.
     *
     * @param list<array{float, float}> $puntos
     *
     * @return array{float, float}
     */
    private function centroDe(array $puntos): array
    {
        [$x, $y] = GeometriaPlana::centroide($puntos);

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
