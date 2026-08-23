<?php

declare(strict_types=1);

namespace App\Models\Pivots;

use Illuminate\Database\Eloquent\Relations\Pivot;
use Override;

/**
 * Una fila de `venta_cliente`: alguien que figura como dueño del expediente.
 *
 * ═══ POR QUE HAY UNA CLASE PARA UN PIVOT ═══
 *
 * Por los CASTS, y no es un detalle de estilo. `withCasts()` sobre la
 * relación **no castea el pivot**: mergea el cast en el modelo relacionado
 * —acá `Cliente`— con la clave `titular_hasta`, pero los atributos del
 * pivot salen crudos de `getAttributes()` con el prefijo `pivot_`, y
 * `Pivot::fromRawAttributes()` arma un pivot sin ningún cast.
 *
 * Resultado sin esta clase: `$cliente->pivot->titular_hasta` es un string
 * y todo `instanceof CarbonInterface` da false — la fecha de la cesión
 * nunca se imprime, en silencio. Con `->using()` acá sí se castea.
 *
 * `titular` viaja igual: en Postgres un boolean llega como `true`/`false`
 * de PDO, pero declararlo deja de depender del driver.
 */
final class DuenoDelExpediente extends Pivot
{
    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'titular'       => 'boolean',
            'titular_hasta' => 'date',
        ];
    }
}
