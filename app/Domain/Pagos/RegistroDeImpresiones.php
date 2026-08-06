<?php

declare(strict_types=1);

namespace App\Domain\Pagos;

use App\Models\ImpresionDeRecibo;
use App\Models\Recibo;
use Illuminate\Support\Facades\DB;

/**
 * Cada salida impresa de un recibo queda anotada.
 *
 * ═══ POR QUE NO ES UN `create()` SUELTO ═══
 *
 * El numero de impresion se calcula contando las anteriores, y contar y
 * escribir sin bloquear es la receta clasica de dos filas iguales: don Elder
 * y doña Rosa abren el mismo recibo en el mismo segundo, los dos leen «0
 * impresiones» y los dos se creen el original. El indice unico
 * `(recibo_id, numero_de_impresion)` haria fallar a uno con un error de base
 * de datos en la cara; el bloqueo hace que espere y salga como copia, que es
 * la verdad.
 *
 * Es el mismo razonamiento de `ConsumoDeCorrelativos` (§8.3.6), en chico.
 */
final readonly class RegistroDeImpresiones
{
    /**
     * Anota una salida impresa y devuelve su registro.
     *
     * La primera es el original; de la segunda en adelante el papel lleva la
     * marca COPIA, y `esCopia()` es lo que la plantilla consulta.
     */
    public function registrar(Recibo $recibo): ImpresionDeRecibo
    {
        return DB::transaction(function () use ($recibo): ImpresionDeRecibo {
            /*
             * Se bloquea el RECIBO, no sus impresiones: bloquear las filas
             * hijas no impide que aparezca una nueva, y es justo la que se
             * quiere impedir.
             */
            $fresco = Recibo::query()->whereKey($recibo->getKey())->lockForUpdate()->firstOrFail();

            $previas = ImpresionDeRecibo::query()
                ->where('recibo_id', $fresco->getKey())
                ->count();

            return ImpresionDeRecibo::query()->create([
                'recibo_id'           => $fresco->getKey(),
                'numero_de_impresion' => $previas + 1,
            ]);
        });
    }
}
