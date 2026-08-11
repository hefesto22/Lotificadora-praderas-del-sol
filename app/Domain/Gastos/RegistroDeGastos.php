<?php

declare(strict_types=1);

namespace App\Domain\Gastos;

use App\Domain\Correlativos\ConsumoDeCorrelativos;
use App\Domain\Exceptions\CorrelativoInvalidoException;
use App\Models\Gasto;
use App\Models\Proyecto;
use Illuminate\Support\Facades\DB;

/**
 * La única puerta por la que se registra un egreso del proyecto.
 *
 * ═══ POR QUE UN SERVICE Y NO `Gasto::create()` ═══
 *
 * Por el número. `ConsumoDeCorrelativos` **se niega a numerar fuera de una
 * transacción** —`lockForUpdate()` sin transacción no bloquea nada, se ve
 * correcto, pasa todos los tests de un solo hilo y falla en producción el día
 * más ocupado del mes—, así que alguien tiene que abrirla. Si eso viviera en
 * la pantalla, el día que un comando de importación registre gastos habría dos
 * lugares que tienen que acordarse de lo mismo.
 *
 * La transacción hace además lo que se espera: si el `INSERT` rebota contra
 * cualquiera de los seis CHECKs de la tabla, el correlativo se va con él y no
 * queda un hueco en la serie que después haya que explicarle a alguien.
 *
 * ═══ LO QUE ESTE SERVICE **NO** HACE ═══
 *
 * No valida los datos. Eso lo hacen dos cosas que ya existen y que no se
 * duplican acá: el formulario de Filament, para que la persona vea el error
 * antes de guardar, y los CHECKs de la base, que son los que de verdad no se
 * pueden esquivar. Una tercera capa de reglas en PHP sería la que algún día
 * diga algo distinto de las otras dos.
 */
final readonly class RegistroDeGastos
{
    public function __construct(private ConsumoDeCorrelativos $correlativos) {}

    /**
     * Registra un gasto del proyecto y le asigna su número de comprobante.
     *
     * `$datos` viene del formulario. `proyecto_id` y `numero` se ponen acá y
     * pisan lo que traiga: el proyecto es el de la ficha donde se está
     * parado, y el número no lo elige nadie.
     *
     * @param array<string, mixed> $datos
     *
     * @throws CorrelativoInvalidoException
     */
    public function registrar(Proyecto $proyecto, array $datos): Gasto
    {
        return DB::transaction(function () use ($proyecto, $datos): Gasto {
            $gasto = new Gasto;
            $gasto->fill($datos);

            $gasto->setAttribute('proyecto_id', $proyecto->getKey());
            $gasto->setAttribute('numero', $this->correlativos->siguienteDeGasto());

            $gasto->save();

            return $gasto;
        });
    }
}
