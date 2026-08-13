<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A nombre de quien sale el recibo de ESTE lote.
 *
 * ═══ QUE LO PIDIO ═══
 *
 * Mauricio, el 12-ago-2026: «hay persona representante pero los representados
 * quieren recibo a nombre de ellos, todo en el expediente del representante».
 * Y al día siguiente, precisando: «si son 3 lotes debe decidir a nombre de
 * quién sale el recibo de ESE lote; si no colocan ningún nombre, sale a nombre
 * del dueño del expediente».
 *
 * Pasa cuando un grupo compra junto y firma UNA sola persona. El contrato es
 * del representante —y así tiene que quedar— pero cada representado tiene SU
 * lote adentro de ese contrato, paga por él y quiere el papel a su nombre.
 *
 * ═══ POR QUE VIVE EN EL LOTE Y NO EN CADA RECIBO ═══
 *
 * Porque es una CONFIGURACION del contrato, no una decisión de cada cobro. Se
 * escribe una vez al vender y de ahí en adelante todos los recibos de ese lote
 * salen solos a ese nombre. Preguntárselo cada mes a quien está en ventanilla
 * es garantizar que algún mes se le olvide, y ese recibo ya se fue impreso.
 *
 * ═══ POR QUE TEXTO Y NO UN CLIENTE ═══
 *
 * Decisión de Mauricio, y es la correcta: «no es necesario guardarlo en
 * clientes ya que ahí solo se guardan para expediente». Un representado no
 * compró nada a su nombre; meterlo en `clientes` lo mete también en la lista
 * que alimenta el formulario de ventas, el plano y los reportes de cartera —
 * gente que aparece como cliente sin tener un solo lote.
 *
 * El DNI va aparte y es OPCIONAL: un recibo a nombre de «JOSÉ MEJÍA» a secas
 * puede ser cualquiera de tres, y si el papel va a servirle de prueba a alguien
 * conviene que diga quién. Vacío no molesta a nadie.
 *
 * ═══ Y EL RECIBO SE QUEDA CON UNA COPIA ═══
 *
 * `recibos.a_nombre_de` NO es una lectura del lote: es una copia congelada al
 * emitir, igual que el área y el precio en `compromisos` (§8.2). Si mañana se
 * corrige el titular del recibo del lote, los papeles ya entregados tienen que
 * seguir diciendo lo que decían — un recibo entregado no se corrige, se anula y
 * se emite otro.
 *
 * ⚠️ Nada de esto convierte al representado en dueño del lote ni en
 * copropietario. Para eso está `venta_cliente` (R8), que es otra cosa: ahí el
 * contrato va a varios nombres.
 *
 * ⚠️ Reemplaza a `2026_08_12_170000_el_recibo_a_nombre_de_otro`, que llegó a
 * escribirse con el diseño viejo —una FK a `clientes`, por recibo— y quedó
 * guardada en `storage/app/_analisis/descartado/`. El `hasColumn` de abajo
 * limpia esa columna por si alcanzó a correr.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('recibos', 'a_nombre_de_id')) {
            DB::statement('ALTER TABLE recibos DROP CONSTRAINT IF EXISTS recibos_a_nombre_de_es_otro_chk');
            DB::statement('DROP INDEX IF EXISTS recibos_a_nombre_de_idx');

            Schema::table('recibos', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('a_nombre_de_id');
            });
        }

        // La configuracion, en el renglon del contrato.
        Schema::table('compromisos', function (Blueprint $table): void {
            $table->string('titular_recibo', 150)->nullable()->after('cliente_id');
            $table->string('titular_recibo_dni', 13)->nullable()->after('titular_recibo');
        });

        // La copia congelada, en el papel.
        Schema::table('recibos', function (Blueprint $table): void {
            $table->string('a_nombre_de', 150)->nullable()->after('cliente_id');
            $table->string('a_nombre_de_dni', 13)->nullable()->after('a_nombre_de');
        });

        /*
         * Un nombre en blanco no es un nombre. Sin esto, una cadena vacia o un
         * espacio se leerian como «hay titular de recibo» y el papel saldria a
         * nombre de nadie. Es el mismo CHECK que ya cuida `recibos.referencia`.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE compromisos
                ADD CONSTRAINT compromisos_titular_recibo_no_vacio_chk
                CHECK (titular_recibo IS NULL OR btrim(titular_recibo) <> ''),

                ADD CONSTRAINT compromisos_dni_sin_titular_chk
                CHECK (titular_recibo_dni IS NULL OR titular_recibo IS NOT NULL)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE recibos
                ADD CONSTRAINT recibos_a_nombre_de_no_vacio_chk
                CHECK (a_nombre_de IS NULL OR btrim(a_nombre_de) <> ''),

                ADD CONSTRAINT recibos_dni_sin_nombre_chk
                CHECK (a_nombre_de_dni IS NULL OR a_nombre_de IS NOT NULL)
        SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE compromisos
                DROP CONSTRAINT IF EXISTS compromisos_titular_recibo_no_vacio_chk,
                DROP CONSTRAINT IF EXISTS compromisos_dni_sin_titular_chk
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE recibos
                DROP CONSTRAINT IF EXISTS recibos_a_nombre_de_no_vacio_chk,
                DROP CONSTRAINT IF EXISTS recibos_dni_sin_nombre_chk
        SQL);

        Schema::table('compromisos', function (Blueprint $table): void {
            $table->dropColumn(['titular_recibo', 'titular_recibo_dni']);
        });

        Schema::table('recibos', function (Blueprint $table): void {
            $table->dropColumn(['a_nombre_de', 'a_nombre_de_dni']);
        });
    }
};
