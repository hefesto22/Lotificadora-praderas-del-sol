<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rehacer el plan de un contrato que se está pagando (25-sep-2026).
 *
 * ═══ POR QUE ═══
 *
 * Un contrato con dos lotes firmados a 6 meses y un cliente que paga
 * L 100,000.00 al mes porque entendió un año. La lotificadora pidió poder
 * «unificar el expediente», que todo se pague como un solo lote —un capital,
 * un interés, una cuota—, fijando los meses o la cuota, con el interés
 * incluido o encima.
 *
 * ═══ DOS COSAS ═══
 *
 *  1. **`reestructuraciones`**: el acta, una fila por trámite y por contrato,
 *     con el antes, el después y el plan pactado renglón por renglón
 *     (`plan_nuevo`). Es lo que se imprime y se firma, y tiene que poder
 *     imprimirse igual dentro de un año.
 *  2. **`reprogramaciones.reestructuracion_id`**: lo que le pasó a cada lote
 *     queda donde ya vive la historia de un plan que cambió —la constancia con
 *     `plan_anterior`— y apunta al acta. Así la pestaña «Reprogramaciones» del
 *     expediente lo muestra sin tocarla.
 *
 * ═══ LOS CHECK QUE SE AFLOJAN, Y POR QUE SIGUEN CERRADOS ═══
 *
 * Una reestructuración no entra plata, así que su constancia va sin recibo,
 * sin abono y sin modalidad de abono. Se afloja exactamente eso y nada más:
 *
 *  - `modalidad` pasa a admitir NULL, pero solo si hay reestructuración
 *    (`reprogramaciones_modalidad_segun_origen_chk`). Un abono sigue
 *    exigiéndola; el CHECK de valores válidos no se toca —con NULL no se
 *    evalúa—.
 *  - `abono_capital > 0` sigue valiendo para todo abono; una reestructuración
 *    va en cero, y el CHECK de que el saldo cuadra (`saldo_nuevo =
 *    saldo_anterior − abono_capital`) sigue en pie.
 *  - Una reestructuración no tiene recibo.
 *
 * No asume nada de los datos de ninguna instalación (§9.F9): la tabla nace
 * vacía y la columna nueva nace NULL en todas las filas que ya existen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reestructuraciones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('venta_id')->constrained('ventas')->cascadeOnDelete();

            // La primera, la segunda… de ESTE contrato. El folio del acta es
            // el contrato más este número: no gasta un correlativo propio.
            $table->unsignedSmallInteger('numero');

            // Fecha de operación, puesta por PHP (§7.5), no `created_at`.
            $table->date('fecha');
            $table->text('motivo');

            // Qué se pactó: los meses o la cuota. El otro sale de la cuenta.
            $table->string('fijado', 10);
            $table->boolean('interes_incluido');

            // NULL cuando los lotes venían con tasas distintas.
            $table->decimal('tasa_anterior', 6, 3)->nullable();
            $table->decimal('tasa_nueva', 6, 3);

            $table->decimal('capital', 14, 2);
            $table->decimal('cuota_anterior', 14, 2)->nullable();
            $table->decimal('cuota_nueva', 14, 2);
            $table->unsignedSmallInteger('meses_antes');
            $table->unsignedSmallInteger('meses_despues');
            $table->decimal('interes_antes', 14, 2);
            $table->decimal('interes_despues', 14, 2);
            $table->date('termina_antes')->nullable();
            $table->date('termina_despues')->nullable();
            $table->date('primer_vencimiento');
            $table->unsignedSmallInteger('dia_pago_anterior');
            $table->unsignedSmallInteger('dia_pago_nuevo');
            $table->unsignedSmallInteger('lotes');

            // El plan pactado, renglón por renglón, tal como salió ese día.
            $table->jsonb('plan_nuevo');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['venta_id', 'numero']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE reestructuraciones
                ADD CONSTRAINT reestructuraciones_numero_positivo_chk
                CHECK (numero > 0),

                ADD CONSTRAINT reestructuraciones_motivo_con_texto_chk
                CHECK (btrim(motivo) <> ''),

                ADD CONSTRAINT reestructuraciones_fijado_valido_chk
                CHECK (fijado IN ('meses', 'cuota')),

                ADD CONSTRAINT reestructuraciones_tasas_razonables_chk
                CHECK (
                    (tasa_anterior IS NULL OR (tasa_anterior >= 0 AND tasa_anterior <= 120))
                    AND tasa_nueva >= 0 AND tasa_nueva <= 120
                ),

                ADD CONSTRAINT reestructuraciones_montos_chk
                CHECK (
                    capital > 0 AND cuota_nueva > 0
                    AND (cuota_anterior IS NULL OR cuota_anterior > 0)
                    AND interes_antes >= 0 AND interes_despues >= 0
                ),

                ADD CONSTRAINT reestructuraciones_meses_chk
                CHECK (meses_antes > 0 AND meses_despues > 0 AND meses_despues <= 600 AND lotes > 0),

                ADD CONSTRAINT reestructuraciones_dia_pago_chk
                CHECK (dia_pago_anterior BETWEEN 1 AND 31 AND dia_pago_nuevo BETWEEN 1 AND 31),

                ADD CONSTRAINT reestructuraciones_plan_es_lista_chk
                CHECK (jsonb_typeof(plan_nuevo) = 'array' AND jsonb_array_length(plan_nuevo) > 0)
        SQL);

        Schema::table('reprogramaciones', function (Blueprint $table): void {
            $table->foreignId('reestructuracion_id')
                ->nullable()
                ->constrained('reestructuraciones')
                ->cascadeOnDelete();

            $table->index('reestructuracion_id');
        });

        DB::statement('ALTER TABLE reprogramaciones ALTER COLUMN modalidad DROP NOT NULL');

        DB::statement(<<<'SQL'
            ALTER TABLE reprogramaciones
                DROP CONSTRAINT reprogramaciones_abono_positivo_chk,

                ADD CONSTRAINT reprogramaciones_abono_positivo_chk
                CHECK (abono_capital > 0 OR (reestructuracion_id IS NOT NULL AND abono_capital = 0)),

                -- Un abono dice cómo reprogramó; una reestructuración no, y
                -- las dos cosas a la vez no existen.
                ADD CONSTRAINT reprogramaciones_modalidad_segun_origen_chk
                CHECK ((reestructuracion_id IS NULL) = (modalidad IS NOT NULL)),

                -- Una reestructuración no entra plata: no tiene recibo.
                ADD CONSTRAINT reprogramaciones_reestructuracion_sin_recibo_chk
                CHECK (reestructuracion_id IS NULL OR recibo_id IS NULL)
        SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE reprogramaciones
                DROP CONSTRAINT IF EXISTS reprogramaciones_reestructuracion_sin_recibo_chk,
                DROP CONSTRAINT IF EXISTS reprogramaciones_modalidad_segun_origen_chk,
                DROP CONSTRAINT IF EXISTS reprogramaciones_abono_positivo_chk
        SQL);

        // Las constancias de una reestructuración no tienen modalidad ni
        // abono: sin la tabla nueva no significan nada, y se van con ella.
        DB::table('reprogramaciones')->whereNotNull('reestructuracion_id')->delete();

        Schema::table('reprogramaciones', function (Blueprint $table): void {
            $table->dropIndex(['reestructuracion_id']);
            $table->dropConstrainedForeignId('reestructuracion_id');
        });

        DB::statement('ALTER TABLE reprogramaciones ALTER COLUMN modalidad SET NOT NULL');

        DB::statement(<<<'SQL'
            ALTER TABLE reprogramaciones
                ADD CONSTRAINT reprogramaciones_abono_positivo_chk
                CHECK (abono_capital > 0)
        SQL);

        Schema::dropIfExists('reestructuraciones');
    }
};
