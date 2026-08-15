<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Support\SinAcentos;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * Buscar por nombre sin que la tilde decida si aparece o no.
 *
 * Lo pidió Mauricio el 13-ago-2026: tecleaba DIAZ y no le salía DÍAZ. La
 * columna `nombre_busqueda` guarda el nombre ya doblado —la calcula
 * Postgres, ver la migración `2026_08_13_210000`— y acá se dobla lo que la
 * persona escribe. Con las dos puntas dobladas, los dos sentidos funcionan:
 * DIAZ encuentra a DÍAZ, y DÍAZ encuentra a los que la cartera vieja cargó
 * sin tilde.
 *
 * ⚠️ Lo que se guarda NO cambia. El nombre con su tilde es el que se
 * imprime en el contrato. Ver {@see SinAcentos}.
 *
 * ═══ POR QUE DEVUELVEN Closure ═══
 *
 * Porque es lo que pide `->searchable(query: …)` de Filament, que está
 * tipado `?Closure` y no acepta un callable cualquiera. Así el llamador
 * escribe una línea —`->searchable(query: BuscarNombre::propio())`— y no
 * una closure a mano en cada tabla, que es como se terminan escribiendo
 * cuatro búsquedas parecidas pero no iguales.
 */
final readonly class BuscarNombre
{
    /**
     * El nombre de la propia tabla: clientes, prospectos.
     */
    public static function propio(): Closure
    {
        return static fn (Builder $query, string $search): Builder => $query
            ->where('nombre_busqueda', 'ilike', self::patron($search));
    }

    /**
     * El nombre del cliente relacionado: recibos, apartados, ventas.
     *
     * `whereHas` y no un join: la tabla ya trae lo suyo y agregarle un join
     * a la consulta del listado cambia el conteo de los agregados que
     * algunas de estas pantallas calculan al lado.
     */
    public static function delCliente(): Closure
    {
        return static fn (Builder $query, string $search): Builder => $query
            ->whereHas('cliente', static fn (Builder $cliente): Builder => $cliente
                ->where('nombre_busqueda', 'ilike', self::patron($search)));
    }

    /**
     * Lo tecleado, sin acentos y entre comodines.
     *
     * Con comodín adelante Y atrás porque la gente busca por el apellido, y
     * el apellido va al final: «MEJIA» tiene que encontrar a «ROSA ELENA
     * MEJIA» sin obligar a escribir el nombre completo.
     */
    private static function patron(string $search): string
    {
        return '%'.SinAcentos::de(trim($search)).'%';
    }
}
