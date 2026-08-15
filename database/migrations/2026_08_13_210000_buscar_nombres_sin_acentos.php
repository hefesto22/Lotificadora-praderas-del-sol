<?php

declare(strict_types=1);

use App\Support\SinAcentos;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Que buscar «DIAZ» encuentre a «DÍAZ», sin tocarle la tilde a nadie.
 *
 * Lo pidió Mauricio el 13-ago-2026: «ya que todos los clientes se guardan
 * en mayúsculas, no debería haber acentuación, ya que cuando los busco no
 * coinciden». El problema es real; la cura de quitarle la tilde al dato no,
 * porque ese nombre se imprime en el contrato y en la escritura. Ver el
 * docblock de {@see SinAcentos}.
 *
 * ═══ QUE HACE ═══
 *
 * Le agrega a `clientes` y a `prospectos` una columna GENERADA con el
 * nombre ya sin acentos. Generada quiere decir que la calcula Postgres y
 * que no se puede escribir a mano: no hay forma de que se desincronice del
 * nombre, ni desde un seeder, ni desde tinker, ni desde un `update` que
 * alguien escriba dentro de tres meses.
 *
 * El buscador de Filament apunta a esa columna y hace el `ILIKE` de
 * siempre. Ni una línea de SQL crudo en la interfaz.
 *
 * ═══ POR QUE TRANSLATE Y NO unaccent ═══
 *
 * `unaccent` es la extensión que existe justo para esto y sería más
 * completa. Pero instalarla pide superusuario —`CREATE EXTENSION`— y eso no
 * está garantizado en el VPS de la lotificadora ni en el de la próxima que
 * compre el producto. `TRANSLATE` es SQL de siempre, funciona en cualquier
 * Postgres y cubre lo que hay: las vocales acentuadas, la ñ, la ç y la
 * diéresis.
 *
 * ⚠️ Las dos listas de letras están COPIADAS de `SinAcentos`, y tienen que
 * seguir siendo iguales. Se leen de la clase a propósito, para que agregar
 * una letra allá alcance —pero OJO: cambiarlas después NO regenera la
 * columna sola, hay que escribir otra migración que la vuelva a crear.
 */
return new class extends Migration
{
    /**
     * Las dos tablas con nombres de personas. `users.name` no entra: son
     * cuatro y se buscan por correo.
     *
     * @var list<string>
     */
    private array $tablas = ['clientes', 'prospectos'];

    public function up(): void
    {
        $acentos = SinAcentos::ACENTOS;
        $llanas = SinAcentos::LLANAS;

        foreach ($this->tablas as $tabla) {
            DB::statement(<<<SQL
                ALTER TABLE {$tabla}
                    ADD COLUMN nombre_busqueda TEXT
                    GENERATED ALWAYS AS (TRANSLATE(nombre, '{$acentos}', '{$llanas}')) STORED
            SQL);

            /*
             * El indice es de patron: `ILIKE '%diaz%'` con comodin adelante
             * no lo puede usar, pero `nombre_busqueda ILIKE 'diaz%'` si, y
             * ese es el caso de todos los dias —se teclea el apellido desde
             * el principio—. Con 300 clientes no cambia nada; con 30,000 de
             * la proxima lotificadora, si.
             */
            DB::statement("CREATE INDEX {$tabla}_nombre_busqueda_idx ON {$tabla} (nombre_busqueda text_pattern_ops)");
        }
    }

    public function down(): void
    {
        foreach ($this->tablas as $tabla) {
            DB::statement("DROP INDEX IF EXISTS {$tabla}_nombre_busqueda_idx");
            DB::statement("ALTER TABLE {$tabla} DROP COLUMN IF EXISTS nombre_busqueda");
        }
    }
};
