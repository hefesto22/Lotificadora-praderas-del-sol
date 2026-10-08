<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A quién se le carga un gasto: al proyecto entero, o a un socio.
 *
 * ═══ QUE LO PIDIO ═══
 *
 * El cliente, por Mauricio, el 8-oct-2026: «lo más importante, al registrar un
 * gasto, registrar a quién de los socios se le carga el gasto».
 *
 * Y la regla de plata la eligió Mauricio el mismo día: **se le descuenta**. Un
 * gasto cargado a un socio lo paga solo él —no le baja la utilidad a los
 * demás— y en el Estado mensual se le resta de lo que le toca, igual que un
 * adelanto. Ver `CierreDelMes`.
 *
 * ═══ NULL ES «AL PROYECTO», Y ES LO QUE YA HABIA ═══
 *
 * `socio_id` en NULL quiere decir lo de siempre: el gasto es del desarrollo y
 * lo pagan todos según su parte. Por eso los gastos de antes de hoy no se
 * tocan ni se rellenan: todos eran del proyecto, y eso es exactamente lo que
 * NULL dice. Una migración que corre en N instalaciones no asume nada más
 * (§9.F9).
 *
 * ═══ EL SOCIO TIENE QUE SER DEL MISMO PROYECTO ═══
 *
 * FK COMPUESTA `(socio_id, proyecto_id)` contra `socios (id, proyecto_id)`, el
 * mismo molde que lote → bloque y compromiso → lote. Un gasto de Praderas
 * cargado a un socio de Río Blanco sería plata que sale de un reparto y entra
 * en otro, y eso no lo tiene que frenar un formulario: lo frena la base.
 *
 * ⚠️ Con `socio_id` en NULL la FK no se evalúa (MATCH SIMPLE): un gasto del
 * proyecto no necesita socio, y no hay que hacer nada para que así sea.
 *
 * ON DELETE RESTRICT: un socio que ya tiene gastos cargados no se borra de
 * verdad. Hoy los socios se borran con SoftDeletes, así que esto solo frena un
 * borrado físico — que es justo el que se llevaría el dato de quién pagó qué.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Destino de la FK compuesta. `id` ya es PK, así que para Postgres
         * este único es redundante, pero una FK solo puede apuntar a columnas
         * con un índice único que las cubra exactas.
         */
        DB::statement('ALTER TABLE socios ADD CONSTRAINT socios_id_proyecto_uq UNIQUE (id, proyecto_id)');

        Schema::table('gastos', function (Blueprint $tabla): void {
            $tabla->unsignedBigInteger('socio_id')->nullable();

            /*
             * El Estado mensual pregunta «cuánto se le cargó a cada socio en
             * este mes»: por proyecto, por fecha, agrupado por socio.
             */
            $tabla->index(['proyecto_id', 'socio_id', 'fecha']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE gastos
                ADD CONSTRAINT gastos_socio_del_mismo_proyecto_fk
                FOREIGN KEY (socio_id, proyecto_id)
                REFERENCES socios (id, proyecto_id)
                ON DELETE RESTRICT
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE gastos DROP CONSTRAINT IF EXISTS gastos_socio_del_mismo_proyecto_fk');

        Schema::table('gastos', function (Blueprint $tabla): void {
            $tabla->dropIndex(['proyecto_id', 'socio_id', 'fecha']);
            $tabla->dropColumn('socio_id');
        });

        DB::statement('ALTER TABLE socios DROP CONSTRAINT IF EXISTS socios_id_proyecto_uq');
    }
};
