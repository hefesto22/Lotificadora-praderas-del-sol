<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Un plazo y una prima POR LOTE, dentro de un mismo contrato.
 *
 * ═══ QUE CAMBIO ═══
 *
 * Hasta hoy una venta tenía UN plazo y UNA cuota. La contratante pidió otra
 * cosa el 5-ago-2026: un contrato con tres lotes puede llevar el primero a
 * 12 meses, el segundo a 24 y el tercero a 48. Un solo expediente, un solo
 * cliente, cuotas propias por lote.
 *
 * No es un capricho de presentación: desde que el precio de la vara² depende
 * del plazo (`planes_de_pago`), un lote a 12 meses y otro a 48 valen distinto
 * la vara. Obligarlos al mismo plazo era obligarlos al mismo precio.
 *
 * ═══ POR QUE EN `compromisos` Y NO EN UNA TABLA NUEVA ═══
 *
 * El compromiso YA es el renglón del contrato: congela área, precio de lista,
 * precio pactado y valor de ese lote. El plazo y la prima son dos datos más
 * del mismo renglón. Una tabla aparte sería una segunda fila diciendo ser la
 * misma — el mismo error que `venta_lote` evitó en su momento.
 *
 * Nullable porque un APARTADO no tiene ni plazo ni prima. Se llenan al vender.
 *
 * ═══ LAS CUOTAS AHORA SON DEL LOTE ═══
 *
 * Con plazos distintos el plan ya no es del contrato: el lote a 12 meses
 * termina de pagarse mientras el de 48 sigue vivo. Cada cuota apunta a su
 * compromiso, y «lo que el cliente paga este mes» es la SUMA de las cuotas
 * vivas de ese mes — que baja cada vez que un lote se termina.
 *
 * `compromiso_id` queda nullable a propósito: una cuota sin lote es el plan
 * viejo, de un contrato entero. No hay ninguna todavía, pero el día que se
 * cargue una venta histórica en papel (R15) va a existir, y el índice único
 * la cubre igual.
 *
 * ═══ DOS INDICES PARCIALES, NO UNO ═══
 *
 * `unique(venta_id, numero)` dejó de ser cierto: con tres lotes hay tres
 * cuotas número 1, una por lote. Pero seguir sin ningún índice sería admitir
 * dos cuotas 5 del mismo lote. Se parte en dos según de quién sea el plan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compromisos', function (Blueprint $table): void {
            $table->unsignedSmallInteger('plazo_meses')->nullable()->after('valor');
            $table->decimal('prima', 14, 2)->nullable()->after('plazo_meses');
        });

        /*
         * El mismo tope de cordura que PlanDeCuotas::PLAZO_MAXIMO_MESES, y la
         * misma regla que `ventas`: la prima no puede superar lo que se está
         * comprando. Acá se mide contra el valor DE ESTE LOTE, que es lo que
         * hace falta para que su cuota se pueda calcular.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE compromisos
                ADD CONSTRAINT compromisos_plazo_razonable_chk
                CHECK (plazo_meses IS NULL OR plazo_meses <= 600),

                ADD CONSTRAINT compromisos_prima_no_supera_el_valor_chk
                CHECK (prima IS NULL OR (prima >= 0 AND prima <= valor))
        SQL);

        Schema::table('cuotas', function (Blueprint $table): void {
            // Cascade igual que venta_id: la cuota no existe sin su renglón.
            $table->foreignId('compromiso_id')
                ->nullable()
                ->after('venta_id')
                ->constrained('compromisos')
                ->cascadeOnDelete();
        });

        Schema::table('cuotas', function (Blueprint $table): void {
            $table->dropUnique(['venta_id', 'numero']);
            $table->index(['compromiso_id', 'fecha_vencimiento']);
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX cuotas_numero_por_lote_uidx
                ON cuotas (compromiso_id, numero)
             WHERE compromiso_id IS NOT NULL
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX cuotas_numero_por_venta_uidx
                ON cuotas (venta_id, numero)
             WHERE compromiso_id IS NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS cuotas_numero_por_lote_uidx');
        DB::statement('DROP INDEX IF EXISTS cuotas_numero_por_venta_uidx');

        Schema::table('cuotas', function (Blueprint $table): void {
            $table->dropIndex(['compromiso_id', 'fecha_vencimiento']);
            $table->dropConstrainedForeignId('compromiso_id');
            $table->unique(['venta_id', 'numero']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE compromisos
                DROP CONSTRAINT IF EXISTS compromisos_plazo_razonable_chk,
                DROP CONSTRAINT IF EXISTS compromisos_prima_no_supera_el_valor_chk
        SQL);

        Schema::table('compromisos', function (Blueprint $table): void {
            $table->dropColumn(['plazo_meses', 'prima']);
        });
    }
};
