<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La renegociación del contrato, aparte de la reestructuración del plan
 * (18-oct-2026, tarde).
 *
 * ═══ POR QUE ═══
 *
 * «Ya está la reestructuración, que es para cuadrar un plan, pero falta la
 * renegociación, que es para rehacer de 0 con lo que pagó, si se le dan
 * descuentos, si se le quita la mora o se le cambia la tasa» —Mauricio—. Las
 * dos son el mismo trámite por dentro y salen con un acta de esta tabla; lo
 * que cambia es qué se puede pactar, y el acta tiene que decir cuál fue.
 *
 * ═══ TRES COLUMNAS Y TRES CHECK ═══
 *
 *  - `tipo`: reestructuración o renegociación.
 *  - `quita_la_mora` y `mora_anterior`: desde el acta los lotes no cobran
 *    mora, y la que tenían queda escrita como se leía ese día. La mora es del
 *    contrato firmado (§8.6): quitarla es renegociarlo, y sin el antes el
 *    papel no se puede contrastar contra el contrato.
 *  - Lo que solo se renegocia —fechas, descuento, quitar la mora— no entra
 *    en un acta de reestructuración. Es la defensa en la base de lo que el
 *    Service ya niega (`porSerUnaRenegociacion`).
 *
 * Las actas que ya se escribieron a fechas o con descuento —la mañana del
 * 18-oct-2026 eso vivía en «Reestructurar»— pasan a ser renegociaciones antes
 * de poner el CHECK. No asume nada de ninguna instalación (§9.F9): en una base
 * sin esas actas, el UPDATE no toca nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reestructuraciones', function (Blueprint $table): void {
            $table->string('tipo', 20)->default('reestructuracion');
            $table->boolean('quita_la_mora')->default(false);
            $table->text('mora_anterior')->nullable();
        });

        DB::statement("UPDATE reestructuraciones SET tipo = 'renegociacion' WHERE fijado = 'fechas' OR descuento > 0");

        DB::statement(<<<'SQL'
            ALTER TABLE reestructuraciones
                ADD CONSTRAINT reestructuraciones_tipo_valido_chk
                CHECK (tipo IN ('reestructuracion', 'renegociacion')),

                ADD CONSTRAINT reestructuraciones_lo_que_solo_se_renegocia_chk
                CHECK (tipo = 'renegociacion' OR (fijado <> 'fechas' AND descuento = 0 AND quita_la_mora = false)),

                ADD CONSTRAINT reestructuraciones_mora_quitada_dice_cual_chk
                CHECK (quita_la_mora = false OR (mora_anterior IS NOT NULL AND btrim(mora_anterior) <> ''))
        SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE reestructuraciones
                DROP CONSTRAINT IF EXISTS reestructuraciones_mora_quitada_dice_cual_chk,
                DROP CONSTRAINT IF EXISTS reestructuraciones_lo_que_solo_se_renegocia_chk,
                DROP CONSTRAINT IF EXISTS reestructuraciones_tipo_valido_chk
        SQL);

        Schema::table('reestructuraciones', function (Blueprint $table): void {
            $table->dropColumn(['tipo', 'quita_la_mora', 'mora_anterior']);
        });
    }
};
