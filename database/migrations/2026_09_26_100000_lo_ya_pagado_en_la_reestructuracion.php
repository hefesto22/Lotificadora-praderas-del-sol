<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lo ya pagado se reubica en el plan nuevo (26-sep-2026).
 *
 * ═══ POR QUE ═══
 *
 * La reestructuración se negaba si una cuota del contrato tenía un pago a
 * medias, y el camino era anular el recibo y volver a cobrar. Desde hoy lo
 * hace ella: el tramo que se reemplaza incluye lo pagado adentro de él, y
 * cada recibo se vuelve a poner sobre el plan nuevo sin cambiar de número,
 * de fecha ni de monto.
 *
 * El acta tiene que poder decir eso con números, porque es lo que firma el
 * cliente y lo que se lee dentro de un año:
 *
 *  - **`ya_pagado`**: lo que el cliente ya había entregado sobre el tramo,
 *    sin la mora. Cuenta como cuotas del plan nuevo.
 *  - **`mora_antes` / `mora_despues`**: la mora que corría el día del
 *    trámite y la que quedó corriendo sobre el plan nuevo. Si lo pagado cubre
 *    la cuota que estaba atrasada, esa mora deja de correr, y eso no puede
 *    pasar sin quedar escrito.
 *  - **`pagos_reubicados`**: cada recibo que se movió, de qué cuotas a qué
 *    cuotas. Una reimpresión que ahora dice otra cosa se anuncia acá (§4.L7).
 *
 * Aditiva: las actas que ya existen quedan en cero y con la lista vacía, que
 * es lo que eran —ninguna movió un recibo—. No asume datos de ninguna
 * instalación (§9.F9).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reestructuraciones', function (Blueprint $table): void {
            $table->decimal('ya_pagado', 14, 2)->default('0.00');
            $table->decimal('mora_antes', 14, 2)->default('0.00');
            $table->decimal('mora_despues', 14, 2)->default('0.00');
            // Postgres convierte el texto '[]' a jsonb solo: sin `DB::raw`.
            $table->jsonb('pagos_reubicados')->default('[]');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE reestructuraciones
                ADD CONSTRAINT reestructuraciones_lo_pagado_chk
                CHECK (ya_pagado >= 0 AND mora_antes >= 0 AND mora_despues >= 0),

                -- Lo ya pagado se pone sobre el plan nuevo: no puede valer más
                -- que el plan entero, capital más interés.
                ADD CONSTRAINT reestructuraciones_lo_pagado_cabe_chk
                CHECK (ya_pagado <= capital + interes_despues),

                ADD CONSTRAINT reestructuraciones_pagos_reubicados_es_lista_chk
                CHECK (jsonb_typeof(pagos_reubicados) = 'array')
        SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE reestructuraciones
                DROP CONSTRAINT IF EXISTS reestructuraciones_pagos_reubicados_es_lista_chk,
                DROP CONSTRAINT IF EXISTS reestructuraciones_lo_pagado_cabe_chk,
                DROP CONSTRAINT IF EXISTS reestructuraciones_lo_pagado_chk
        SQL);

        Schema::table('reestructuraciones', function (Blueprint $table): void {
            $table->dropColumn(['ya_pagado', 'mora_antes', 'mora_despues', 'pagos_reubicados']);
        });
    }
};
