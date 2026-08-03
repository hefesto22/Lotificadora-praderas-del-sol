<?php

declare(strict_types=1);

namespace App\Domain\Plano;

use App\Domain\Enums\EstadoLote;
use App\Domain\Enums\Numeracion;
use App\Domain\Exceptions\GeneracionDeLotesException;
use App\Models\Bloque;
use App\Models\Lote;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Emite los lotes de un bloque rectangular, ya dibujados y numerados.
 *
 * previsualizar() calcula TODO sin tocar la base. Eso permite mostrarle
 * el resultado al usuario antes de crear 40 registros, y hace que la
 * parte con logica —numeracion y geometria— se pueda testear sin
 * levantar Postgres.
 *
 * generar() falla ANTES de escribir si algun numero ya existe. Una tanda
 * a medias deja el bloque con la numeracion salteada y sin rastro de que
 * fallo, que es peor que no haber generado nada.
 */
final readonly class GeneradorDeLotes
{
    /**
     * Los lotes que saldrian, sin crear ninguno.
     *
     * @return list<array{numero: string, area_varas: numeric-string, poligono: list<array{float, float}>}>
     */
    public function previsualizar(ParametrosDeGeneracion $parametros): array
    {
        if ($parametros->totalDeLotes() > ParametrosDeGeneracion::MAXIMO_POR_TANDA) {
            throw GeneracionDeLotesException::porTandaDemasiadoGrande(
                $parametros->totalDeLotes(),
                ParametrosDeGeneracion::MAXIMO_POR_TANDA
            );
        }

        $frente = (float) $parametros->frenteVaras;
        $fondo = (float) $parametros->fondoVaras;

        // El paso incluye la separacion: asi es como se abre espacio para
        // una calle o un callejon entre filas sin dibujarlo dos veces.
        $pasoX = $frente + (float) $parametros->separacionColumnasVaras;
        $pasoY = $fondo + (float) $parametros->separacionFilasVaras;

        $area = $parametros->areaPorLoteVaras();
        $lotes = [];

        for ($fila = 0; $fila < $parametros->filas; $fila++) {
            for ($columna = 0; $columna < $parametros->columnas; $columna++) {
                $x = $parametros->origenX + ($columna * $pasoX);
                $y = $parametros->origenY + ($fila * $pasoY);

                $lotes[] = [
                    'numero'     => (string) $this->numeroDe($parametros, $fila, $columna),
                    'area_varas' => $area,
                    'poligono'   => [
                        [$x, $y],
                        [$x + $frente, $y],
                        [$x + $frente, $y + $fondo],
                        [$x, $y + $fondo],
                    ],
                ];
            }
        }

        return $lotes;
    }

    /**
     * Crea los lotes dentro de una transaccion.
     *
     * `valor` y `codigo` no se pasan: los recalcula el modelo en cada
     * guardado, y pasarlos seria abrir una segunda fuente de verdad.
     *
     * @return Collection<int, Lote>
     */
    public function generar(Bloque $bloque, ParametrosDeGeneracion $parametros): Collection
    {
        $planificados = $this->previsualizar($parametros);

        $this->verificarNumerosLibres($bloque, $planificados);

        /** @var Collection<int, Lote> $creados */
        $creados = new Collection;

        // La coleccion se llena por referencia y el closure devuelve void,
        // en vez de retornar a traves de DB::transaction(): asi el tipo de
        // retorno no depende de que los stubs de la facade propaguen el
        // generico. Si la transaccion revienta, la excepcion sale por
        // arriba y este `return` no se alcanza nunca.
        DB::transaction(function () use ($bloque, $parametros, $planificados, $creados): void {
            foreach ($planificados as $planificado) {
                $creados->push(Lote::query()->create([
                    'proyecto_id' => $bloque->getAttribute('proyecto_id'),
                    'bloque_id'   => $bloque->getKey(),
                    'numero'      => $planificado['numero'],
                    'area_varas'  => $planificado['area_varas'],
                    'precio_vara' => $parametros->precioVara,
                    'estado'      => EstadoLote::Disponible,
                    'poligono'    => $planificado['poligono'],
                ]));
            }
        });

        return $creados;
    }

    /**
     * Numero que le toca al lote de esa fila y columna.
     *
     * En serpentina las filas impares se recorren al reves, que es como
     * numeran los planos: el ultimo lote de una fila queda pegado al
     * primero de la siguiente.
     */
    private function numeroDe(ParametrosDeGeneracion $parametros, int $fila, int $columna): int
    {
        $columnaEfectiva = $parametros->numeracion === Numeracion::Serpentina && $fila % 2 === 1
            ? $parametros->columnas - 1 - $columna
            : $columna;

        return $parametros->numeroInicial + ($fila * $parametros->columnas) + $columnaEfectiva;
    }

    /**
     * @param list<array{numero: string, area_varas: numeric-string, poligono: list<array{float, float}>}> $planificados
     */
    private function verificarNumerosLibres(Bloque $bloque, array $planificados): void
    {
        $pedidos = array_map(
            static fn (array $planificado): string => $planificado['numero'],
            $planificados
        );

        /** @var list<string> $ocupados */
        $ocupados = $bloque->lotes()
            ->whereIn('numero', $pedidos)
            ->orderBy('numero')
            ->pluck('numero')
            ->all();

        if ($ocupados !== []) {
            throw GeneracionDeLotesException::porNumerosDuplicados(
                (string) $bloque->getAttribute('nombre'),
                $ocupados
            );
        }
    }
}
