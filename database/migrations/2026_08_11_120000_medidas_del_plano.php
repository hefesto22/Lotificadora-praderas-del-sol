<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * En qué unidad se muestran las medidas del plano, y cuánto mide la vara
 * de ESTE desarrollo.
 *
 * ═══ POR QUÉ POR PROYECTO Y NO EN LA CONFIG ═══
 *
 * `config('lotificadora.area.vara_en_metros')` sigue siendo la vara del
 * sistema y el valor por defecto de todos. Pero el número lo fija el
 * topógrafo de cada desarrollo, no Olympo: la vara castellana son 0.8359 m,
 * la mexicana 0.8380 y la de Texas 0.8467. Un solo número global obliga a
 * elegir cuál de los planos sale con el área equivocada.
 *
 * NULLABLE a propósito: null significa «la vara del sistema». Así el
 * default sigue viviendo en un solo lugar —la config— en vez de quedar
 * copiado en 300 filas el día que se decida cambiarlo.
 *
 * ⚠️ ESTE NÚMERO TOCA EL DINERO. De él sale cuántas varas² tiene cada lote
 * al importar el DXF, y el precio es por vara². Por eso cambiarlo NO
 * recalcula nada de lo ya cargado (decisión del 11-ago-2026): rige para lo
 * que se importe de ahí en adelante, y el formulario lo avisa. Mover el
 * área de un lote que ya está apartado o vendido es cambiarle el precio a
 * un contrato firmado.
 *
 * ═══ EL CHECK ═══
 *
 * Entre 0.5 y 1.5 m entra cualquier vara que se haya usado alguna vez en
 * América; afuera de ese rango no hay una convención distinta, hay un dedo
 * que se resbaló. La base lo frena aunque el valor entre por un seeder o
 * por tinker.
 *
 * `medidas_en_metros` es solo presentación: las áreas se siguen guardando
 * y operando en varas² (§8.3.7). Lo único que cambia es qué número se le
 * enseña al cliente al lado de cada lado del lote, para que cuadre con lo
 * que dice el plano impreso que tiene en la mano.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proyectos', function (Blueprint $table): void {
            $table->boolean('medidas_en_metros')->default(false)->after('plano_esquematico');
            $table->decimal('vara_en_metros', 8, 6)->nullable()->after('medidas_en_metros');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE proyectos
                ADD CONSTRAINT proyectos_vara_en_rango_chk
                CHECK (vara_en_metros IS NULL OR vara_en_metros BETWEEN 0.5 AND 1.5)
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE proyectos DROP CONSTRAINT IF EXISTS proyectos_vara_en_rango_chk');

        Schema::table('proyectos', function (Blueprint $table): void {
            $table->dropColumn(['medidas_en_metros', 'vara_en_metros']);
        });
    }
};
