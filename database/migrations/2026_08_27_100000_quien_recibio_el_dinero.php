<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Quién recibió el dinero, y la referencia deja de ser obligatoria — 27-ago-2026.
 *
 * ═══ 1. «¿QUIÉN RECIBIÓ EL DINERO?» ═══
 *
 * Pedido de Mauricio: «que la administradora y yo podamos seleccionar quién
 * recibió el dinero, y también los receptores que seleccionen quién recibió».
 *
 * Hasta hoy el sistema solo sabía **quién tecleó** el cobro (`created_by`), y
 * lo daba por lo mismo. No es lo mismo: la administradora registra un pago que
 * recibió don Elder en la caseta, y el efectivo lo tiene él. El arqueo del día
 * es de quien tiene el billete en la mano.
 *
 * De hecho ya lo estaban resolviendo a mano: los 257 recibos de la cartera
 * vieja traen «RECIBIÓ DIONEL PINTO» escrito **adentro del número de
 * referencia**, que es el campo del banco. Esto le da su propio lugar.
 *
 * 🔴 **Se rellena con `created_by`**, y no queda en NULL: hasta hoy el que
 * tecleaba ERA el que recibía, así que ese es el dato verdadero para todo lo
 * que ya está. Sin esto, el corte de caja tendría que preguntar por dos
 * columnas para siempre — y una consulta que contempla dos verdades sobre lo
 * mismo es la que se equivoca (§8.3.4).
 *
 * ═══ 2. LA REFERENCIA DEJA DE SER OBLIGATORIA (R11 se afloja) ═══
 *
 * R11 pedía referencia obligatoria en todo lo que no fuera efectivo. En la
 * práctica traba el mostrador: llega una transferencia, el cliente está
 * enfrente y el número todavía no lo tiene nadie. El recibo no se puede emitir
 * y el cobro no se registra — que es peor que registrarlo sin la referencia.
 *
 * El campo **no se va**: sigue visible, con su ayuda diciendo para qué sirve.
 * Lo que se va es el freno.
 *
 * ⚠️ **Solo en `recibos`.** En `gastos`, `devoluciones` y `entregas_a_socios`
 * la referencia sigue obligatoria a propósito: ahí la plata SALE y el
 * comprobante es la única defensa. Nadie se quejó de esas tres.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recibos', function (Blueprint $table): void {
            $table->foreignId('recibido_por')
                ->nullable()
                ->after('forma_pago')
                ->constrained('users')
                ->nullOnDelete();
        });

        /*
         * El índice es el del corte de caja: «lo de HOY, por persona». Sin él,
         * el widget del Escritorio hace un seq scan de la tabla entera cada vez
         * que alguien abre el panel.
         */
        Schema::table('recibos', function (Blueprint $table): void {
            $table->index(['fecha', 'recibido_por'], 'recibos_del_dia_por_persona_idx');
        });

        // El dato verdadero de todo lo que ya existe: quien tecleaba, recibía.
        DB::statement('UPDATE recibos SET recibido_por = created_by WHERE recibido_por IS NULL');

        DB::statement('ALTER TABLE recibos DROP CONSTRAINT IF EXISTS recibos_referencia_cuando_hace_falta_chk');
    }

    /**
     * ⚠️ El `down()` vuelve a poner el CHECK, así que **falla si mientras tanto
     * se emitió un recibo sin referencia**. Es correcto que falle: revertir no
     * puede inventar el número que nadie escribió. Habría que completarlos o
     * anularlos primero.
     */
    public function down(): void
    {
        Schema::table('recibos', function (Blueprint $table): void {
            $table->dropIndex('recibos_del_dia_por_persona_idx');
            $table->dropConstrainedForeignId('recibido_por');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE recibos
                ADD CONSTRAINT recibos_referencia_cuando_hace_falta_chk
                CHECK (
                    forma_pago = 'efectivo'
                    OR (referencia IS NOT NULL AND btrim(referencia) <> '')
                )
        SQL);
    }
};
