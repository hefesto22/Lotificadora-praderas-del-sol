<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Enums\EstadoLote;
use App\Domain\Enums\TipoCalle;
use App\Models\Bloque;
use App\Models\Calle;
use App\Models\Compromiso;
use App\Models\Lote;
use App\Models\Proyecto;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Carga PRADERAS DEL SOL con la geometria REAL del plano de distribucion
 * (Ing. Gerson Menjivar, abril 2026).
 *
 * De donde sale
 * ---------------
 * Del DXF NATIVO de AutoCAD que dibujo el topografo, PLANO DIONEL
 * CORPUS.dxf. No de una conversion del PDF: el archivo trae 652 entidades
 * TEXT, 24 rotulos BLOQUE, 305 rotulos de area y coordenadas de campo
 * reales.
 *
 * Por eso aca no se calcula NADA que el plano ya diga:
 *
 *   - El AREA sale del texto `250.00 vr2` que escribio el topografo.
 *   - El NUMERO de lote sale del texto que esta dentro de esa misma cara.
 *   - El BLOQUE sale del rotulo `BLOQUE X` que cae dentro de la manzana.
 *
 * El poligono sale de las caras del grafo de linderos, sin extender ni un
 * milimetro. El control es comparar el area DIBUJADA contra la IMPRESA:
 * la mediana da 0.006% y el percentil 90, 0.027%.
 *
 * La unidad la declara el propio archivo: `$DIMLFAC = 1.1976`, o sea
 * 1 vara = 0.835 m exactos.
 *
 * Los 309 vienen dibujados
 * ------------------------
 * Ninguno viaja sin poligono. NOTA_SIN_DIBUJO queda de red: si un dia el
 * JSON trae una cara que no cierra, el lote entra igual -con su area y su
 * numero, que son los que se venden- y el mapa lo manda a "Sin dibujar"
 * en vez de callarselo. Un lote de menos en el dibujo no le hace dano a
 * nadie; un poligono que miente, si.
 *
 * El unico con el dibujo peleado con su area es el X-15, y viaja MARCADO
 * por poligonoDesalineado() (§8.2). Hay un test que se pone rojo si
 * aparece un segundo.
 *
 * Si el plano crece con la base ya operando
 * -----------------------------------------
 * Este seeder REEMPLAZA el trazado, y por eso se detiene solo en cuanto
 * hay un lote fuera de `disponible`. Para agregar lo que falte sin tocar
 * lo vendido esta `olympo:completar-plano`, que lee este mismo archivo y
 * solo INSERTA. Paso el 22-ago-2026 con la manzana I.
 *
 * Ver docs/plano-real.md para el detalle de como se leyo el archivo.
 */
final class PlanoRealPraderasSeeder extends Seeder
{
    private const string CODIGO = 'RPS';

    /** Copán. La columna es varchar(2): guarda el código, no el nombre. */
    private const string DEPARTAMENTO = 'CP';

    private const string ARCHIVO = 'database/data/praderas-plano.json';

    private const string NOTA_SIN_DIBUJO = 'AREA Y NUMERO EXACTOS DEL PLANO. EL POLIGONO NO SE PUDO CERRAR: NO SE DIBUJA EN EL MAPA.';

    public function run(): void
    {
        $precioVara = (string) (getenv('PRECIO_VARA') ?: '1500');

        $datos = $this->leerArchivo();

        // Reemplaza el trazado esquematico anterior: es el MISMO proyecto,
        // con la geometria correcta. Si ya hay movimiento comercial encima
        // no se borra nada y el seeder se detiene.
        $previo = Proyecto::query()->where('codigo', self::CODIGO)->first();

        if ($previo instanceof Proyecto && ! $this->limpiar($previo)) {
            return;
        }

        $proyecto = Proyecto::query()->updateOrCreate(
            ['codigo' => self::CODIGO],
            [
                'nombre'    => 'RESIDENCIAL PRADERAS DEL SOL',
                'municipio' => 'CORPUS',
                // `departamento` es el CODIGO de dos letras, no el nombre.
                'departamento' => self::DEPARTAMENTO,
                'direccion'    => 'CORPUS, LA UNION, COPAN, HONDURAS C.A.',
                'activo'       => true,
                // El plano ya no es esquematico: la geometria es la del terreno.
                'plano_esquematico' => false,
                'observaciones'     => 'PLANO DE DISTRIBUCION DE LOTES, CICH 9293, ABRIL DE 2026. '.
                                       'PROPIETARIO: DIONEL PINTO. LEVANTO: TOP ANTONIO MEJIA. '.
                                       'CALCULO, DIBUJO Y REVISO: ING. GERSON MENJIVAR. '.
                                       'GEOMETRIA RECONSTRUIDA DEL PLANO VECTORIAL DEL PDF ORIGINAL.',
            ],
        );

        $bloques = [];

        foreach ($this->nombresDeBloque($datos['lotes']) as $orden => $nombre) {
            $delBloque = array_filter(
                $datos['lotes'],
                static fn (array $l): bool => $l['bloque'] === $nombre,
            );

            $bloques[$nombre] = Bloque::query()->updateOrCreate(
                ['proyecto_id' => $proyecto->getKey(), 'nombre' => $nombre],
                [
                    'area_total_varas'   => $this->decimal(array_sum(array_column($delBloque, 'area'))),
                    'lotes_planificados' => count($delBloque),
                    'orden'              => $orden + 1,
                ],
            );
        }

        $creados = 0;
        $sinDibujo = 0;

        foreach ($datos['lotes'] as $lote) {
            /** @var list<array{float, float}> $poligono */
            $poligono = $lote['poligono'];
            $dibujado = count($poligono) >= 3;

            if (! $dibujado) {
                $sinDibujo++;
            }

            Lote::query()->updateOrCreate(
                [
                    'proyecto_id' => $proyecto->getKey(),
                    'bloque_id'   => $bloques[$lote['bloque']]->getKey(),
                    'numero'      => (string) $lote['numero'],
                ],
                [
                    'area_varas'    => $this->decimal($lote['area']),
                    'precio_vara'   => $precioVara,
                    'estado'        => EstadoLote::Disponible,
                    'poligono'      => $dibujado ? $poligono : null,
                    'observaciones' => $dibujado ? null : self::NOTA_SIN_DIBUJO,
                ],
            );
            $creados++;
        }

        // Las calles entran POR POLIGONO (no por trazo + ancho): el area de
        // rodaje del plano no es una franja de ancho constante, se abre en
        // los cruces. El CHECK de la migracion admite las dos formas.
        foreach ($datos['calles'] as $orden => $calle) {
            Calle::query()->updateOrCreate(
                ['proyecto_id' => $proyecto->getKey(), 'nombre' => $calle['nombre']],
                [
                    'tipo'     => TipoCalle::Calle,
                    'poligono' => $calle['poligono'],
                    'orden'    => $orden + 1,
                ],
            );
        }

        $this->command?->info(sprintf(
            'PRADERAS DEL SOL: %d bloques, %d lotes (%d sin dibujo), %d calles. Area en lotes: %s vr2.',
            count($bloques),
            $creados,
            $sinDibujo,
            count($datos['calles']),
            number_format(array_sum(array_column($datos['lotes'], 'area')), 2),
        ));
    }

    /**
     * Borra el trazado anterior. Devuelve false —y no borra nada— si algun
     * lote ya salio de `disponible`: eso ya no es data de prueba, es un
     * compromiso con un cliente y no lo pisa un seeder.
     */
    private function limpiar(Proyecto $proyecto): bool
    {
        $comprometidos = Lote::query()
            ->where('proyecto_id', $proyecto->getKey())
            ->where('estado', '!=', EstadoLote::Disponible->value)
            ->count();

        if ($comprometidos > 0) {
            $this->command?->error(
                "Hay {$comprometidos} lote(s) apartados o vendidos en el proyecto {$proyecto->codigo}."
            );
            $this->command?->line('No los piso. Liberalos primero, o corre este seeder sobre una base limpia.');

            return false;
        }

        DB::transaction(function () use ($proyecto): void {
            $lotes = Lote::query()->where('proyecto_id', $proyecto->getKey())->pluck('id');

            Compromiso::query()->whereIn('lote_id', $lotes)->delete();
            Lote::query()->where('proyecto_id', $proyecto->getKey())->delete();
            Bloque::query()->where('proyecto_id', $proyecto->getKey())->delete();
            Calle::query()->where('proyecto_id', $proyecto->getKey())->delete();
        });

        $this->command?->warn('Se reemplazo el trazado esquematico anterior por la geometria del plano.');

        return true;
    }

    /**
     * @return array{lotes: list<array<string, mixed>>, calles: list<array<string, mixed>>}
     */
    private function leerArchivo(): array
    {
        $ruta = base_path(self::ARCHIVO);

        if (! is_file($ruta)) {
            throw new RuntimeException("No encuentro el plano en {$ruta}.");
        }

        $crudo = file_get_contents($ruta);

        if ($crudo === false) {
            throw new RuntimeException("No pude leer {$ruta}.");
        }

        /** @var array{lotes: list<array<string, mixed>>, calles: list<array<string, mixed>>} $datos */
        $datos = json_decode($crudo, true, 512, JSON_THROW_ON_ERROR);

        return $datos;
    }

    /**
     * @param list<array<string, mixed>> $lotes
     *
     * @return list<string>
     */
    private function nombresDeBloque(array $lotes): array
    {
        $nombres = array_values(array_unique(array_map(
            static fn (array $l): string => (string) $l['bloque'],
            $lotes,
        )));
        sort($nombres);

        return $nombres;
    }

    /**
     * bcmath consume strings, no floats (§8.3.1). El area entra al modelo
     * como string con dos decimales para no perder el centavo en el camino.
     */
    private function decimal(float|int|string $v): string
    {
        return number_format((float) $v, 2, '.', '');
    }
}
