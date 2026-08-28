<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Enums\EstadoLote;
use App\Domain\Enums\UnidadDeArea;
use App\Domain\Plano\Dxf\ExtractorDeGeometria;
use App\Domain\Plano\Dxf\ImportadorDeDxf;
use App\Domain\Plano\Dxf\LectorDxf;
use App\Domain\Plano\Dxf\ResultadoDeImportacion;
use App\Models\Bloque;
use App\Models\Calle;
use App\Models\Compromiso;
use App\Models\Lote;
use App\Models\Proyecto;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Carga un desarrollo entero desde el DXF del topografo, de una corrida.
 *
 * Es la puerta de arranque de una instalacion nueva: se corre el seeder y
 * el proyecto queda con sus manzanas, sus lotes, su geometria y su unidad
 * de area. Nada de esto se digita.
 *
 * ═══ POR QUE LEE EL DXF Y NO UN JSON YA MASTICADO ═══
 *
 * Porque el DXF del topografo es el unico documento que las dos partes
 * firman. Un JSON intermedio es una traduccion nuestra, y una traduccion
 * se puede editar a mano sin que nadie note la diferencia -que es
 * exactamente como se pierde un lote-. Leyendo el archivo original:
 *
 *  - hay UNA sola fuente de verdad, y es la del ingeniero;
 *  - la geometria la arma `ExtractorDeGeometria`, que ya sabe de arcos,
 *    de INSERT anidados y de las siete trampas del formato;
 *  - el dia que el topografo mande una revision, se reemplaza el archivo
 *    y se vuelve a correr. No hay paso de conversion que rehacer.
 *
 * ═══ O ENTRA COMPLETO O NO ENTRA ═══
 *
 * Todo el trabajo corre DENTRO de una transaccion, y los controles se
 * exigen adentro. Si la lectura no da exactamente el plano declarado en
 * `PlanoDeclarado` -manzana por manzana- se lanza y no queda NADA
 * cargado. Es deliberado: un plano a medias no avisa que esta a medias, y
 * el dia que se descubre ya hay contratos encima. Ver la manzana I de
 * Praderas del Sol, 22-ago-2026, y `docs/plano-real.md`.
 *
 * ═══ SI EL PROYECTO YA ESTA VENDIENDO, ESTE SEEDER NO PASA ═══
 *
 * REEMPLAZA el trazado, asi que se detiene solo en cuanto hay un lote
 * fuera de `disponible`: eso ya no es data de prueba, es un compromiso con
 * una persona. Para agregar lo que falte sin tocar lo vendido esta
 * `olympo:completar-plano`, que solo INSERTA.
 */
abstract class PlanoDesdeDxfSeeder extends Seeder
{
    /**
     * El plano impreso, declarado a mano. Ver PlanoDeclarado.
     */
    abstract protected function plano(): PlanoDeclarado;

    public function run(): void
    {
        $plano = $this->plano();
        $previo = Proyecto::query()->where('codigo', $plano->codigo)->first();

        if ($previo instanceof Proyecto && ! $this->sePuedeReemplazar($previo)) {
            return;
        }

        $contenido = $this->leerArchivo($plano);
        $rotulosDeArea = $this->rotulosDeArea($plano, $contenido);

        /** @var ResultadoDeImportacion $resultado */
        $resultado = DB::transaction(function () use ($plano, $previo, $contenido): ResultadoDeImportacion {
            if ($previo instanceof Proyecto) {
                $this->limpiar($previo);
            }

            $proyecto = $this->crearProyecto($plano);
            $semilla = $this->crearManzanaSemilla($proyecto, $plano);

            $resultado = (new ImportadorDeDxf)->importar($semilla, $contenido, $plano->opciones());

            // Adentro de la transaccion a proposito: si algo no cuadra, se
            // va todo -proyecto incluido- y la base queda como estaba.
            $this->exigirQueCuadre($plano, $resultado);

            $this->declararLoQueDiceElPlano($proyecto, $plano);

            return $resultado;
        });

        $this->informar($plano, $resultado, $rotulosDeArea);
    }

    /**
     * Los controles. Cada uno nacio de un dia en que algo entro mal.
     */
    private function exigirQueCuadre(PlanoDeclarado $plano, ResultadoDeImportacion $resultado): void
    {
        $esperado = $plano->manzanas();
        $leido = $resultado->lotesPorBloque;
        ksort($leido);

        if ($leido !== $esperado) {
            throw new RuntimeException(
                "El plano de {$plano->codigo} no entro completo y no se cargo nada.\n".
                '  El plano dice: '.$this->enTexto($esperado)."\n".
                '  Se leyeron:    '.$this->enTexto($leido)."\n".
                '  Diferencia:    '.$this->enTexto($this->diferencia($esperado, $leido), conSigno: true)."\n".
                '  Revisa el DXF contra el plano impreso antes de volver a correr.'
            );
        }

        if ($resultado->lotesCreados !== $plano->lotes()) {
            throw new RuntimeException(
                "El plano de {$plano->codigo} declara {$plano->lotes()} lotes y se crearon ".
                "{$resultado->lotesCreados}. No se cargo nada."
            );
        }

        /*
         * Un lote sin rotulo adentro se numera correlativamente, o sea que
         * el sistema le INVENTA el numero. En una carga inicial eso no es
         * un detalle: ese numero va a salir impreso en un contrato.
         */
        if ($resultado->sinRotulo > 0) {
            throw new RuntimeException(
                "{$resultado->sinRotulo} lote(s) de {$plano->codigo} no traen rotulo adentro y el ".
                'sistema les puso un numero correlativo. No se cargo nada: un numero de lote '.
                'inventado termina impreso en un contrato.'
            );
        }

        $diferencia = abs($resultado->areaTotalVaras - $plano->areaTotal);
        $porcentaje = $plano->areaTotal > 0.0 ? $diferencia / $plano->areaTotal * 100.0 : 0.0;

        if ($porcentaje > $plano->toleranciaDeArea) {
            throw new RuntimeException(sprintf(
                "El area de %s no cuadra con el plano y no se cargo nada.\n".
                "  El plano dice: %s\n  Se leyeron:    %s  (%.4f %% de diferencia, el tope es %.4f %%)",
                $plano->codigo,
                number_format($plano->areaTotal, 2),
                number_format($resultado->areaTotalVaras, 2),
                $porcentaje,
                $plano->toleranciaDeArea,
            ));
        }
    }

    /**
     * `area_total_varas` y `lotes_planificados` de cada manzana.
     *
     * Son DATOS DECLARADOS DEL PLANO, no un cache de lo cargado: es lo que
     * despues permite conciliar "el plano dice 42 y hay 40". El importador
     * los escribe solo en las manzanas que el crea, asi que la semilla
     * -que se creo antes- se quedaria sin ellos.
     */
    private function declararLoQueDiceElPlano(Proyecto $proyecto, PlanoDeclarado $plano): void
    {
        $orden = 0;

        foreach ($plano->manzanas() as $nombre => $planificados) {
            $orden++;

            $bloque = Bloque::query()
                ->where('proyecto_id', $proyecto->getKey())
                ->where('nombre', $nombre)
                ->first();

            if (! $bloque instanceof Bloque) {
                continue;
            }

            /*
             * La suma con bcmath y no con sum() de la base: `area_varas`
             * es lo que despues multiplica al precio, y por ahi no pasa un
             * float (§8.3.1). PDO de PostgreSQL devuelve NUMERIC como
             * string, que es justo lo que bcadd consume.
             */
            $area = '0';

            foreach (Lote::query()->where('bloque_id', $bloque->getKey())->pluck('area_varas') as $unLote) {
                if (is_numeric($unLote)) {
                    $area = bcadd($area, (string) $unLote, 4);
                }
            }

            $bloque->update([
                'lotes_planificados' => $planificados,
                'area_total_varas'   => $area,
                'orden'              => $orden,
            ]);
        }
    }

    private function crearProyecto(PlanoDeclarado $plano): Proyecto
    {
        return Proyecto::query()->updateOrCreate(
            ['codigo' => $plano->codigo],
            array_merge([
                'nombre'      => $plano->nombre,
                'activo'      => true,
                'unidad_area' => $plano->unidad,
                // El plano viene del documento del topografo: deja de ser
                // un esquema. El importador tambien lo apaga; aca queda
                // dicho desde el nacimiento del proyecto.
                'plano_esquematico' => false,
                'medidas_en_metros' => $plano->unidad === UnidadDeArea::Metros,
                'vara_en_metros'    => $plano->factorDeVara(),
            ], $plano->datos),
        );
    }

    /**
     * La manzana de arranque, que el importador necesita para empezar.
     */
    private function crearManzanaSemilla(Proyecto $proyecto, PlanoDeclarado $plano): Bloque
    {
        return Bloque::query()->updateOrCreate(
            ['proyecto_id' => $proyecto->getKey(), 'nombre' => $plano->manzanaSemilla()],
            ['orden' => 1],
        );
    }

    /**
     * Devuelve false -y no borra nada- si algun lote ya salio de
     * `disponible`. Ese lote ya tiene una persona detras.
     */
    private function sePuedeReemplazar(Proyecto $proyecto): bool
    {
        $comprometidos = Lote::query()
            ->where('proyecto_id', $proyecto->getKey())
            ->where('estado', '!=', EstadoLote::Disponible->value)
            ->count();

        if ($comprometidos === 0) {
            return true;
        }

        $codigo = (string) $proyecto->getAttribute('codigo');

        $this->command?->error("Hay {$comprometidos} lote(s) apartados o vendidos en el proyecto {$codigo}.");
        $this->command?->line('No los piso. Para agregar lo que falte sin tocarlos:');
        $this->command?->line("  php artisan olympo:completar-plano {$codigo} <archivo.json> --ensayo");

        return false;
    }

    private function limpiar(Proyecto $proyecto): void
    {
        $lotes = Lote::query()->where('proyecto_id', $proyecto->getKey())->pluck('id');

        Compromiso::query()->whereIn('lote_id', $lotes)->delete();
        Lote::query()->where('proyecto_id', $proyecto->getKey())->delete();
        Bloque::query()->where('proyecto_id', $proyecto->getKey())->delete();
        Calle::query()->where('proyecto_id', $proyecto->getKey())->delete();

        $this->command?->warn("Se reemplazo el trazado anterior de {$proyecto->getAttribute('codigo')}.");
    }

    private function leerArchivo(PlanoDeclarado $plano): string
    {
        $ruta = base_path($plano->archivo);

        if (! is_file($ruta)) {
            throw new RuntimeException("No encuentro el plano de {$plano->codigo} en {$ruta}.");
        }

        $crudo = file_get_contents($ruta);

        if ($crudo === false) {
            throw new RuntimeException("No pude leer {$ruta}.");
        }

        return $crudo;
    }

    /**
     * LA RESTA: cuantos rotulos de area trae el archivo.
     *
     * Contra los lotes cargados, esta cuenta es la lista de lo que la
     * lectura dejo afuera. Es el control que le faltaba al 22-ago-2026, y
     * vale para cualquier lectura de un plano, no solo para esta.
     *
     * Es una AYUDA, no un juez: un plano puede rotular un area municipal o
     * dejar una esquina sin rotulo, y por eso avisa en vez de detener. El
     * que detiene es el conteo declarado en PlanoDeclarado.
     */
    private function rotulosDeArea(PlanoDeclarado $plano, string $contenido): int
    {
        $archivo = (new LectorDxf)->leer($contenido);
        $rotulos = (new ExtractorDeGeometria)->rotulos($archivo);

        $unidades = implode('|', array_map(
            static fn (string $u): string => preg_quote($u, '/'),
            $plano->unidadesDelRotulo(),
        ));

        $forma = '/^\s*(?:A\s*=\s*)?[\d.,]+\s*(?:'.$unidades.')\s*$/iu';
        $cuantos = 0;

        foreach ($rotulos as $rotulo) {
            $lineas = preg_split('/\R/u', $rotulo->texto);

            if (! is_array($lineas)) {
                $lineas = [$rotulo->texto];
            }

            foreach ($lineas as $linea) {
                if (preg_match($forma, (string) $linea) === 1) {
                    $cuantos++;
                }
            }
        }

        return $cuantos;
    }

    private function informar(PlanoDeclarado $plano, ResultadoDeImportacion $resultado, int $rotulosDeArea): void
    {
        $this->command?->info(sprintf(
            '%s (%s): %d lotes en %d manzanas, %s %s. Calles: %d.',
            $plano->nombre,
            $plano->codigo,
            $resultado->lotesCreados,
            count($plano->manzanas()),
            number_format($resultado->areaTotalVaras, 2),
            $plano->unidad->plural(),
            $resultado->callesCreadas,
        ));

        $this->command?->line('  Reparto: '.$resultado->repartoEnTexto());

        if ($rotulosDeArea !== $resultado->lotesCreados) {
            $this->command?->warn(sprintf(
                '  El archivo trae %d rotulos de area y se cargaron %d lotes: %d de diferencia. '.
                'Coteja esas filas contra el plano impreso.',
                $rotulosDeArea,
                $resultado->lotesCreados,
                abs($rotulosDeArea - $resultado->lotesCreados),
            ));
        }

        foreach ($resultado->advertencias as $advertencia) {
            $this->command?->warn('  '.$advertencia);
        }
    }

    /**
     * @param array<string, int> $esperado
     * @param array<string, int> $leido
     *
     * @return array<string, int>
     */
    private function diferencia(array $esperado, array $leido): array
    {
        $diferencia = [];

        foreach (array_keys($esperado + $leido) as $nombre) {
            $delta = ($leido[$nombre] ?? 0) - ($esperado[$nombre] ?? 0);

            if ($delta !== 0) {
                $diferencia[$nombre] = $delta;
            }
        }

        return $diferencia;
    }

    /**
     * @param array<string, int> $manzanas
     */
    private function enTexto(array $manzanas, bool $conSigno = false): string
    {
        if ($manzanas === []) {
            return $conSigno ? 'ninguna, cuadran una por una' : 'ninguna';
        }

        $partes = [];

        foreach ($manzanas as $nombre => $cuantos) {
            $partes[] = sprintf('%s%d en %s', $conSigno && $cuantos > 0 ? '+' : '', $cuantos, $nombre);
        }

        return implode(', ', $partes);
    }
}
