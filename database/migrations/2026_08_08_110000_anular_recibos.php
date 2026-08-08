<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Un recibo mal emitido se ANULA. No se edita y no se borra.
 *
 * ═══ POR QUE NO SE BORRA ═══
 *
 * El número es lo único que hace serio a un recibo interno (R12): una serie
 * sin huecos es la que permite decir «entre el 000120 y el 000130 no falta
 * ninguno». Borrar la fila deja el hueco y con él se va la única prueba de
 * que ese número existió alguna vez. La fila se queda, marcada.
 *
 * Y porque el papel ya salió. El cliente tiene en la mano un recibo 000123
 * que la base ya no tendría — que es exactamente la situación que un
 * correlativo viene a evitar.
 *
 * ═══ POR QUE NO SE EDITA ═══
 *
 * Cambiarle el monto a un recibo entregado deja el papel del cliente diciendo
 * una cosa y la base diciendo otra, sin rastro de cuál era cuál. Se anula el
 * viejo, se emite uno nuevo, y las dos filas cuentan la historia completa.
 *
 * ═══ EL MOTIVO ES OBLIGATORIO, Y LO EXIGE LA BASE ═══
 *
 * Un recibo anulado sin motivo es dinero que desapareció del estado de cuenta
 * sin que nadie tenga que explicarlo. El CHECK lo impide: o los datos de la
 * anulación están completos, o la fila no está anulada.
 *
 * `anulado_por` queda fuera del CHECK a propósito: `nullOnDelete` puede
 * vaciarlo si algún día se borra ese usuario, y perder el nombre de quien
 * anuló no debería invalidar la anulación. El motivo y la fecha sí quedan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recibos', function (Blueprint $table): void {
            $table->timestamp('anulado_el')->nullable();
            $table->foreignId('anulado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->text('motivo_anulacion')->nullable();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE recibos
                ADD CONSTRAINT recibos_anulacion_completa_chk
                CHECK (
                    (anulado_el IS NULL AND motivo_anulacion IS NULL)
                    OR (anulado_el IS NOT NULL AND motivo_anulacion IS NOT NULL
                        AND btrim(motivo_anulacion) <> '')
                )
        SQL);

        /*
         * Índice PARCIAL: la consulta que importa es «los recibos vivos de
         * esta venta». Los anulados son pocos y no se cruzan con nada; que
         * ocupen lugar en el índice sería pagar por lo que no se busca.
         */
        DB::statement(<<<'SQL'
            CREATE INDEX recibos_vivos_por_venta_idx
                ON recibos (venta_id, fecha)
                WHERE anulado_el IS NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS recibos_vivos_por_venta_idx');
        DB::statement('ALTER TABLE recibos DROP CONSTRAINT IF EXISTS recibos_anulacion_completa_chk');

        Schema::table('recibos', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('anulado_por');
            $table->dropColumn(['anulado_el', 'motivo_anulacion']);
        });
    }
};
