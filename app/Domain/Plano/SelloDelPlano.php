<?php

declare(strict_types=1);

namespace App\Domain\Plano;

use App\Models\Proyecto;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * La huella de todo lo que el plano publico muestra.
 *
 * ═══ 🔴 EL PROBLEMA QUE RESUELVE ═══
 *
 * La pagina publica se cachea cinco minutos: es la unica URL de este sistema
 * que puede recibir cien aperturas en un minuto —un link que circula en un
 * grupo de WhatsApp— y sin cache cada una arma el plano de 301 lotes y cotiza
 * cada medida contra cada plan.
 *
 * Pero la clave era solo `proyectos.updated_at`, y ahi estaba la trampa:
 * **vender un lote no toca la fila del proyecto**. Cambiar el precio de un
 * plan tampoco. Asi que la administradora vendia un lote, abria la pagina
 * publica para comprobar, y seguia viendo el lote verde. Cinco minutos.
 *
 * Cinco minutos no suenan a nada hasta que alguien le manda el link a un
 * cliente en ese rato.
 *
 * ═══ 🔴 Y POR QUE NO ALCANZA CON MIRAR MAS `updated_at` ═══
 *
 * Fue el primer intento y estaba mal por una razon que no se ve leyendo el
 * codigo: **`updated_at` guarda segundos enteros, no microsegundos**.
 *
 * Laravel arma `$table->timestamps()` con `Blueprint::defaultTimePrecision()`,
 * que vale 0, asi que en Postgres la columna queda `timestamp(0)`. Se
 * comprueba en la base:
 *
 *   SELECT datetime_precision FROM information_schema.columns
 *    WHERE table_name = 'lotes' AND column_name = 'updated_at';   -- 0
 *
 * O sea que si la pagina se arma y el lote se vende **dentro del mismo
 * segundo**, el `MAX(updated_at)` no se mueve, la clave sale identica, y la
 * pagina vieja se sirve los cinco minutos completos. Y ese segundo no es
 * teorico: es exactamente el momento en que alguien graba la venta y en la
 * otra pestaña refresca el link para comprobar que quedo.
 *
 * ═══ LA HUELLA ES DE LOS DATOS, NO DEL RELOJ ═══
 *
 * Por eso se resume el CONTENIDO de lo que la vidriera muestra:
 *
 *   lotes          → cuantos son, que estado tiene cada uno, y cuanta vara²
 *                    suman entre todos
 *   planes_de_pago → cuantos son, y de cada uno el plazo, el precio de la
 *                    vara², la tasa y si esta activo
 *
 * Cambiar cualquiera de esas cosas cambia la huella **en el acto**, sin
 * depender de en que segundo cayo. Vender un lote mueve `string_agg` de
 * estados; bajar el precio de un plan mueve el suyo. No hay ventana.
 *
 * Son dos consultas de agregacion sobre indices por `proyecto_id` —301 filas
 * cortas y cinco filas— contra reconstruir el plano entero. La cache sigue
 * absorbiendo la avalancha de WhatsApp.
 *
 * ═══ QUE SIGUE COLGADO DEL RELOJ, Y POR QUE ESTA BIEN ═══
 *
 * El `updated_at` del proyecto (nombre, servicios, WhatsApp, coordenadas) y
 * `MAX(updated_at)` de cada tabla, que quedan como red para lo que la huella
 * no resume: redibujar un poligono sin cambiar el area, por ejemplo. Ahi si
 * hay un segundo de ventana y una pagina que se cura sola en cinco minutos.
 *
 * La diferencia es cual es el caso comun. Vender y cambiar precios pasa todos
 * los dias y se ve al instante; corregir un poligono pasa cuando se carga el
 * proyecto, y nadie esta mirando el link en ese momento.
 *
 * ⚠️ Encender y apagar el plano publico NO pasa por aca: el controlador lo
 * consulta antes de tocar la cache, asi que apagarlo da 404 en el acto.
 */
final readonly class SelloDelPlano
{
    public function para(Proyecto $proyecto): string
    {
        $partes = [
            'p:'.$this->fecha($proyecto->getAttribute('updated_at')),
            'l:'.$this->huellaDeLotes($proyecto),
            'c:'.$this->huellaDePlanes($proyecto),
        ];

        /*
         * Se resume a 16 caracteres. La clave de cache viaja a Redis en cada
         * request y no gana nada con las huellas completas; lo que importa es
         * que cambie cuando cambia cualquiera de las tres.
         */
        return substr(md5(implode('|', $partes)), 0, 16);
    }

    // ─── Interno ──────────────────────────────────────────────────────

    /**
     * Lo que la vidriera muestra de cada lote: si esta libre, cuanto mide y
     * cuantos hay.
     *
     * `string_agg(... ORDER BY id)` y no un `COUNT` por estado: dos lotes que
     * se cruzan —uno se vende y otro se libera el mismo dia— dejarian el
     * conteo igual y la pagina vieja. Ordenado, porque sin `ORDER BY` el
     * orden de agregacion no esta garantizado y la huella bailaria sola.
     */
    private function huellaDeLotes(Proyecto $proyecto): string
    {
        $fila = DB::table('lotes')
            ->where('proyecto_id', $proyecto->getKey())
            ->selectRaw(<<<'SQL'
                COUNT(*) AS filas,
                MAX(updated_at) AS ultimo,
                COALESCE(SUM(area_varas), 0) AS area,
                COALESCE(md5(string_agg(concat_ws(':', id, estado), ',' ORDER BY id)), '-') AS estados
            SQL)
            ->first();

        return $this->pegar($fila);
    }

    /**
     * El precio de la vara² y el del dinero, que son las dos columnas que el
     * cliente lee en el modal, mas el plazo y si el plan sigue ofreciendose.
     */
    private function huellaDePlanes(Proyecto $proyecto): string
    {
        $fila = DB::table('planes_de_pago')
            ->where('proyecto_id', $proyecto->getKey())
            ->selectRaw(<<<'SQL'
                COUNT(*) AS filas,
                MAX(updated_at) AS ultimo,
                COALESCE(md5(string_agg(
                    concat_ws(':', id, meses, precio_vara, tasa_interes_anual, activo), ',' ORDER BY id
                )), '-') AS detalle
            SQL)
            ->first();

        return $this->pegar($fila);
    }

    /**
     * La fila de agregacion, aplanada a texto.
     *
     * Se castea a array y no se leen propiedades del `stdClass`: `first()`
     * devuelve `object|null` y PHPStan tiene razon en no dejar pasar un
     * `->filas` que nadie declaro.
     */
    private function pegar(mixed $fila): string
    {
        $valores = is_object($fila) ? get_object_vars($fila) : [];

        return implode(',', array_map(
            static fn (mixed $valor): string => is_scalar($valor) ? (string) $valor : 'x',
            $valores,
        ));
    }

    private function fecha(mixed $valor): string
    {
        return $valor instanceof DateTimeInterface ? $valor->format('YmdHis') : 'x';
    }
}
