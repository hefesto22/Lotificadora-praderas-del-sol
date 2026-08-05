<?php

declare(strict_types=1);

namespace App\Domain\Lotes;

use App\Domain\Enums\EstadoLote;
use App\Domain\ValueObjects\Monto;
use App\Models\Bloque;
use App\Models\Lote;
use App\Models\Proyecto;
use Illuminate\Support\Facades\DB;

/**
 * Fija el precio por vara² de muchos lotes de una sola vez.
 *
 * ═══ POR QUE EXISTE ═══
 *
 * R15: los datos llegan en papel. La geometria de los 301 lotes ya la
 * resolvio el importador de DXF, pero **la parte comercial no**: hay que
 * cargar a mano el precio de cada uno. Hacerlo lote por lote son 301
 * formularios; en la practica el precio es el mismo para todo un bloque o
 * para el proyecto entero.
 *
 * Esta es la operacion que convierte esa tarde de trabajo en un formulario.
 *
 * ═══ LOS VENDIDOS NO SE TOCAN, Y SE DICE CUALES ═══
 *
 * El §8.2 congela area y precio de un lote vendido: el modelo lanza
 * `LoteInmutableException` y un trigger de PostgreSQL lo impide igual. Asi
 * que en vez de reventar a la mitad, este Service **los saltea y devuelve
 * sus codigos**, para que la pantalla pueda decir exactamente cuales
 * quedaron sin cambiar y por que. Un "se actualizaron 298 de 301" sin decir
 * cuales tres faltan es una respuesta inutil.
 *
 * ⚠️ DEUDA CONOCIDA (§4.L4): un lote APARTADO si se repreciar. Su compromiso
 * conserva el valor congelado —eso esta bien—, pero `RegistroDeVentas`
 * relee el precio del lote al convertir el apartado en venta, no el del
 * compromiso. Si alguien repreciara entre el apartado y la firma, el cliente
 * terminaria pagando el precio nuevo. Hoy no muerde porque la carga de
 * precios ocurre antes de que exista un solo apartado, pero hay que
 * decidirlo antes de que la lotificadora empiece a mover precios.
 */
final readonly class FijacionDePrecios
{
    /**
     * @param ?Bloque $bloque null = todo el proyecto
     *
     * @return array{aplicados: int, omitidos: list<string>}
     */
    public function fijar(Proyecto $proyecto, ?Bloque $bloque, Monto $precioPorVara): array
    {
        return DB::transaction(function () use ($proyecto, $bloque, $precioPorVara): array {
            $consulta = Lote::query()->where('proyecto_id', $proyecto->getKey());

            if ($bloque instanceof Bloque) {
                $consulta->where('bloque_id', $bloque->getKey());
            }

            // `lockForUpdate` porque entre leer y escribir alguien podria
            // vender uno de estos lotes desde otra pantalla, y entonces el
            // trigger cortaria el update a la mitad de la tanda.
            $lotes = $consulta->orderBy('codigo')->lockForUpdate()->get();

            $precio = $precioPorVara->redondeado();
            $aplicados = 0;
            $omitidos = [];

            foreach ($lotes as $lote) {
                if ($lote->getAttribute('estado') === EstadoLote::Vendido) {
                    $omitidos[] = (string) $lote->getAttribute('codigo');

                    continue;
                }

                // El modelo recalcula `valor` = area x precio con bcmath en
                // su hook `saving`. No se escribe el valor desde aca: seria
                // una segunda fuente para el mismo numero.
                $lote->update(['precio_vara' => $precio]);
                $aplicados++;
            }

            return ['aplicados' => $aplicados, 'omitidos' => $omitidos];
        });
    }
}
