<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reestructurar a fechas pactadas, y con un descuento de capital (18-oct-2026).
 *
 * ═══ POR QUE ═══
 *
 * El caso que lo pidió: un plan a 95 meses del que se pagó la cuota 1, y
 * que se renegoció a dos pagos —L 650,000.00 el 13/10/2026 y L 350,000.00 el
 * 15/01/2027— perdonándole al cliente una parte del capital para que el total
 * quedara en un millón parejo. Mauricio: «debería poder manejarse para estos
 * casos especiales». El descuento es firme: se aplica el día del acta.
 *
 * ═══ TRES COSAS ═══
 *
 *  1. **`reestructuraciones.fijado` admite `fechas`**: lo pactado no fueron
 *     meses ni una cuota, fueron fechas.
 *  2. **`reestructuraciones.descuento`**: lo que se perdonó del capital, el
 *     total del acta. Menor que el capital: perdonarlo entero no es
 *     reestructurar, es otra cosa, y el plan nuevo se quedaría sin cuotas.
 *  3. **`reprogramaciones.descuento`**: lo que se le perdonó a cada lote, en
 *     su constancia. El CHECK de que el saldo cuadra pasa a restarlo:
 *     `saldo_nuevo = saldo_anterior − abono_capital − descuento`. Solo una
 *     reestructuración descuenta; un abono, nunca —ese es plata que entró—.
 *
 * No asume nada de los datos de ninguna instalación (§9.F9): las dos columnas
 * nacen en cero, y con cero los dos CHECK que se recrean dicen exactamente lo
 * mismo que decían.
 *
 * ⚠️ El `down()` vuelve a poner los CHECK de antes. Si ya hay un acta a fechas
 * o un descuento escrito, falla — y está bien que falle: borrar esas filas en
 * silencio sería borrar lo que el cliente firmó.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reestructuraciones', function (Blueprint $table): void {
            $table->decimal('descuento', 14, 2)->default('0.00');
        });

        Schema::table('reprogramaciones', function (Blueprint $table): void {
            $table->decimal('descuento', 14, 2)->default('0.00');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE reestructuraciones
                DROP CONSTRAINT reestructuraciones_fijado_valido_chk,

                ADD CONSTRAINT reestructuraciones_fijado_valido_chk
                CHECK (fijado IN ('meses', 'cuota', 'fechas')),

                ADD CONSTRAINT reestructuraciones_descuento_chk
                CHECK (descuento >= 0 AND descuento < capital)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE reprogramaciones
                DROP CONSTRAINT reprogramaciones_saldo_cuadra_chk,

                ADD CONSTRAINT reprogramaciones_saldo_cuadra_chk
                CHECK (saldo_nuevo = saldo_anterior - abono_capital - descuento),

                -- Solo la constancia de una reestructuración descuenta.
                ADD CONSTRAINT reprogramaciones_descuento_chk
                CHECK (descuento >= 0 AND (descuento = 0 OR reestructuracion_id IS NOT NULL))
        SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE reprogramaciones
                DROP CONSTRAINT IF EXISTS reprogramaciones_descuento_chk,
                DROP CONSTRAINT IF EXISTS reprogramaciones_saldo_cuadra_chk,

                ADD CONSTRAINT reprogramaciones_saldo_cuadra_chk
                CHECK (saldo_nuevo = saldo_anterior - abono_capital)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE reestructuraciones
                DROP CONSTRAINT IF EXISTS reestructuraciones_descuento_chk,
                DROP CONSTRAINT IF EXISTS reestructuraciones_fijado_valido_chk,

                ADD CONSTRAINT reestructuraciones_fijado_valido_chk
                CHECK (fijado IN ('meses', 'cuota'))
        SQL);

        Schema::table('reprogramaciones', function (Blueprint $table): void {
            $table->dropColumn('descuento');
        });

        Schema::table('reestructuraciones', function (Blueprint $table): void {
            $table->dropColumn('descuento');
        });
    }
};
