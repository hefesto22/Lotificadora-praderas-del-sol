<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * R14, la parte que faltaba: la prórroga y la seña que hay que devolver.
 *
 * ═══ POR QUE UN CONTADOR Y NO UNA FECHA MAS ═══
 *
 * `vence_el` ya dice hasta cuándo vale el apartado, y una prórroga lo mueve.
 * Lo que esa fecha NO puede decir es cuántas veces se movió — y ahí está la
 * regla: la contratante autorizó **una sola prórroga** (R14). Sin el
 * contador, la segunda prórroga es indistinguible de la primera y cualquiera
 * puede estirar un apartado para siempre de a quince días.
 *
 * El máximo NO va en un CHECK. Vive en `config('lotificadora.apartados')`
 * junto con el monto y los días, porque los tres los fijó la contratante y
 * los tres se cambian juntos el día que cambie de opinión. Un CHECK con el
 * 1 escrito a mano obligaría a una migración para eso. La base solo impide
 * lo que es absurdo en cualquier configuración: un contador negativo.
 *
 * ═══ Y POR QUE UNA FECHA PARA LA SEÑA DEVUELTA ═══
 *
 * R14 dice que si el apartado se cae, el dinero se devuelve. Hoy no hay
 * módulo de egresos —se decidió el 6-ago dejarlo para después—, así que lo
 * mínimo honesto es que el sistema sepa distinguir «hay L 5,000.00 que
 * devolverle a alguien» de «ya se devolvieron».
 *
 * Sin esta columna la lista de pendientes por devolver nunca se vaciaría, y
 * una lista que no se vacía se deja de mirar a la semana.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compromisos', function (Blueprint $table): void {
            $table->unsignedSmallInteger('prorrogas')->default(0);
            $table->date('senia_devuelta_el')->nullable();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE compromisos
                -- Solo un apartado se prorroga: una venta no vence.
                ADD CONSTRAINT compromisos_prorroga_solo_en_apartados_chk
                CHECK (prorrogas = 0 OR tipo = 'apartado'),

                -- No se devuelve una seña que no existe.
                ADD CONSTRAINT compromisos_senia_devuelta_con_senia_chk
                CHECK (senia_devuelta_el IS NULL OR monto_senia IS NOT NULL)
        SQL);

        /*
         * El indice que necesita la pantalla de apartados: «qué vence esta
         * semana» es la pregunta que se hace todos los días, y sin esto
         * recorre los 500 lotes cada vez.
         *
         * PARCIAL, sobre los vigentes nada más: un apartado cerrado ya no
         * vence, y los cerrados van a ser la mayoría de la tabla al año.
         */
        DB::statement(<<<'SQL'
            CREATE INDEX compromisos_apartados_por_vencer_idx
                ON compromisos (vence_el)
                WHERE tipo = 'apartado' AND estado = 'vigente'
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS compromisos_apartados_por_vencer_idx');

        DB::statement(<<<'SQL'
            ALTER TABLE compromisos
                DROP CONSTRAINT IF EXISTS compromisos_prorroga_solo_en_apartados_chk,
                DROP CONSTRAINT IF EXISTS compromisos_senia_devuelta_con_senia_chk
        SQL);

        Schema::table('compromisos', function (Blueprint $table): void {
            $table->dropColumn(['prorrogas', 'senia_devuelta_el']);
        });
    }
};
