<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reestructurar: los lotes juntos en una cuota, o cada uno con la suya
 * (Praderas, 25-oct-2026).
 *
 * ═══ POR QUE ═══
 *
 * La reestructuración vino de Inmobiliaria Maya, donde los lotes que se
 * marcan se juntan SIEMPRE: un capital, un interés, una cuota. Mauricio, con
 * el expediente 0033 enfrente —dos lotes que se reestructuran a 24 meses cada
 * uno—: «que se pueda unificar los lotes para una sola cuota o que tengan
 * cuota independiente según como lo reestructure».
 *
 * ═══ DOS COLUMNAS ═══
 *
 *  - **`agrupacion`**: `unificado` —lo de Maya, y el valor de toda acta que
 *    ya exista— o `por_lote`, cada lote con su plan y su cuota.
 *  - **`lotes_detalle`**: cómo quedó cada lote, escrito ese día: su capital,
 *    cuántas cuotas, de cuánto, desde qué número y hasta cuándo. Con los lotes
 *    juntos el acta lee el plan del contrato (`plan_nuevo`); cada uno por su
 *    lado, el papel tiene que poder decir lote por lote qué se firmó, aunque
 *    después un abono reescriba las cuotas.
 *
 * Aditiva: las actas que ya existen quedan `unificado` y con la lista vacía,
 * que es lo que eran. No asume datos de ninguna instalación (§9.F9).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reestructuraciones', function (Blueprint $table): void {
            $table->string('agrupacion', 10)->default('unificado');
            // Postgres convierte el texto '[]' a jsonb solo: sin `DB::raw`.
            $table->jsonb('lotes_detalle')->default('[]');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE reestructuraciones
                ADD CONSTRAINT reestructuraciones_agrupacion_valida_chk
                CHECK (agrupacion IN ('unificado', 'por_lote')),

                ADD CONSTRAINT reestructuraciones_lotes_detalle_es_lista_chk
                CHECK (jsonb_typeof(lotes_detalle) = 'array')
        SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE reestructuraciones
                DROP CONSTRAINT IF EXISTS reestructuraciones_lotes_detalle_es_lista_chk,
                DROP CONSTRAINT IF EXISTS reestructuraciones_agrupacion_valida_chk
        SQL);

        Schema::table('reestructuraciones', function (Blueprint $table): void {
            $table->dropColumn(['agrupacion', 'lotes_detalle']);
        });
    }
};
