<?php

declare(strict_types=1);

namespace App\Domain\Plano;

use App\Domain\Enums\Numeracion;
use App\Models\Bloque;
use App\Models\Lote;
use App\Models\Proyecto;
use Illuminate\Support\Facades\DB;

/**
 * Le pone dibujo a los lotes que YA existen.
 *
 * Es lo inverso del generador y hacia falta: los ~55 lotes cargados
 * tienen numero, area y precio reales, y el generador no sabe hacer otra
 * cosa que crear lotes nuevos. Correrle el generador encima a un bloque
 * cargado no lo dibuja: intenta duplicarlo y falla.
 *
 * LO QUE ESTE SERVICIO NUNCA TOCA: numero, area_varas, precio_vara, valor
 * ni estado. Escribe una sola columna, `poligono`. Por eso puede pasar
 * incluso sobre lotes vendidos, que el §8.2 congela en area y precio pero
 * no en geometria — y menos mal, porque los lotes vendidos son
 * justamente los que mas interesa ver pintados.
 *
 * El resultado es un ESQUEMA, no el plano del topografo: los lotes salen
 * en fila, en el orden del codigo, cada uno con su area exacta pero sin
 * relacion con su ubicacion real en el terreno. Por eso acomodarProyecto()
 * marca el proyecto como esquematico.
 */
final readonly class AcomodadorDelPlano
{
    /**
     * Dibuja los lotes existentes de un bloque. Devuelve cuantos dibujo.
     */
    public function acomodarBloque(Bloque $bloque, ParametrosDeAcomodo $parametros): int
    {
        /*
         * Ordenados por `codigo` y no por `numero`: el codigo lleva el
         * relleno a 3 digitos, asi que su orden alfabetico ES el orden del
         * plano. Ordenar por numero pondria el 10 antes que el 9.
         */
        /** @var list<Lote> $lotes */
        $lotes = $bloque->lotes()->orderBy('codigo')->get()->all();

        if ($lotes === []) {
            return 0;
        }

        $fondo = (float) $parametros->fondoVaras;
        $separacionFilas = (float) $parametros->separacionFilasVaras;
        $dibujados = 0;

        /*
         * Un update por lote y no un bulk: cada guardado recalcula `valor`
         * y `codigo` en el modelo, que es la garantia de que ningun camino
         * de escritura los deje inconsistentes. Son ~3 consultas por lote,
         * unas 1500 para 500 lotes: es una accion manual que se corre una
         * vez, y perder esa garantia para ahorrar un segundo seria un mal
         * negocio.
         */
        DB::transaction(function () use ($lotes, $parametros, $fondo, $separacionFilas, &$dibujados): void {
            foreach ($this->repartirEnFilas($lotes, $parametros->filas) as $indiceFila => $lotesDeLaFila) {
                $enOrden = $parametros->numeracion === Numeracion::Serpentina && $indiceFila % 2 === 1
                    ? array_reverse($lotesDeLaFila)
                    : $lotesDeLaFila;

                $x = $parametros->origenX;
                $y = $parametros->origenY + $indiceFila * ($fondo + $separacionFilas);

                foreach ($enOrden as $lote) {
                    $frente = $this->frenteDe($lote, $fondo);

                    $lote->update(['poligono' => [
                        [round($x, 4), round($y, 4)],
                        [round($x + $frente, 4), round($y, 4)],
                        [round($x + $frente, 4), round($y + $fondo, 4)],
                        [round($x, 4), round($y + $fondo, 4)],
                    ]]);

                    $x += $frente;
                    $dibujados++;
                }
            }
        });

        return $dibujados;
    }

    /**
     * Acomoda el proyecto entero, apilando los bloques uno debajo del otro
     * en el orden que ya define `bloques.orden`.
     *
     * Marca el proyecto como esquematico si dibujo algo. Esa marca es la
     * unica diferencia visible entre este dibujo y uno trazado del plano
     * legal, asi que se pone aca y no se deja a criterio de quien llama.
     */
    public function acomodarProyecto(Proyecto $proyecto, ParametrosDeAcomodo $parametros): int
    {
        /** @var list<Bloque> $bloques */
        $bloques = $proyecto->bloques()->orderBy('orden')->orderBy('nombre')->get()->all();

        $alto = $parametros->altoDeBloqueVaras();
        $separacion = (float) $parametros->separacionBloquesVaras;

        $dibujados = 0;
        $y = $parametros->origenY;

        foreach ($bloques as $bloque) {
            $enBloque = $this->acomodarBloque($bloque, $parametros->conOrigenY($y));

            // Un bloque sin lotes no deja hueco: avanzar igual dejaria una
            // franja vacia en el plano sin nada que la explique.
            if ($enBloque === 0) {
                continue;
            }

            $dibujados += $enBloque;
            $y += $alto + $separacion;
        }

        if ($dibujados > 0) {
            $proyecto->update(['plano_esquematico' => true]);
        }

        return $dibujados;
    }

    /**
     * Frente que le toca al lote para que su rectangulo encierre
     * exactamente su area cargada.
     *
     * Float y sin culpa: esto define donde se pinta una linea, no cuanto
     * paga nadie. `area_varas` sale intacta de la base y el valor del lote
     * se sigue calculando con bcmath desde ella (§8.3.1).
     */
    private function frenteDe(Lote $lote, float $fondo): float
    {
        $area = $lote->getAttribute('area_varas');

        if (! is_numeric($area) || (float) $area <= 0.0) {
            // La base ya exige area > 0, asi que esto no deberia pasar.
            // Si pasa, el lote se dibuja angosto en vez de desaparecer del
            // plano sin que nadie se entere.
            return 1.0;
        }

        return (float) $area / $fondo;
    }

    /**
     * Reparte los lotes en filas lo mas parejo posible.
     *
     * Con 55 lotes en 2 filas salen 28 y 27, no 27 y 28: las filas de
     * arriba se quedan con el sobrante, que es como se lee un plano.
     *
     * @param list<Lote> $lotes
     *
     * @return list<list<Lote>>
     */
    private function repartirEnFilas(array $lotes, int $filas): array
    {
        $total = count($lotes);
        $base = intdiv($total, $filas);
        $resto = $total % $filas;

        $repartidas = [];
        $cursor = 0;

        for ($fila = 0; $fila < $filas; $fila++) {
            $cantidad = $base + ($fila < $resto ? 1 : 0);

            if ($cantidad === 0) {
                continue;
            }

            $repartidas[] = array_slice($lotes, $cursor, $cantidad);
            $cursor += $cantidad;
        }

        return $repartidas;
    }
}
