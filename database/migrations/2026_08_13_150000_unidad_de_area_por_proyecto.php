<?php

declare(strict_types=1);

use App\Domain\Enums\UnidadDeArea;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * En qué unidad se mide y se COBRA la superficie de cada desarrollo.
 *
 * Lo pidió Mauricio el 13-ago-2026, con EL BAMBÚ cargado: «al crear el
 * proyecto debe decidirse si en metros o varas, según la que se escoja
 * todo se trabajará en base a eso». Hasta hoy la unidad era una sola para
 * toda la instalación —`config('lotificadora.area.unidad_plural')`— y eso
 * obliga a elegir cuál de los dos desarrollos muestra la unidad
 * equivocada. Impresa en un contrato, «equivocada» no es un detalle.
 *
 * ⚠️ NO CONVIERTE NADA. El área sigue viviendo en `lotes.area_varas`, y
 * el número guardado es el que se midió al importar el plano. Esta
 * columna dice con qué palabra se escribe y en qué se midió, no cuánto
 * vale. Por eso cambiarla con lotes vendidos está prohibido en el
 * formulario: ver Proyecto::puedeCambiarLaUnidad().
 *
 * ═══ POR QUÉ EL RELLENO MIRA `vara_en_metros` ═══
 *
 * El 13-ago, antes de que existiera esta columna, EL BAMBÚ se cargó
 * poniéndole `vara_en_metros = 1.000000`: el truco de decir «la vara de
 * este proyecto es el metro» para que las áreas entraran en m². Ese uno
 * NO es una vara de ningún país —el CHECK del rango admite de 0.5 a 1.5
 * justamente porque ahí caben todas las que existen— así que un uno
 * redondo solo puede significar una cosa. El relleno lo lee y deja al
 * proyecto en metros², que es lo que ya venía siendo de hecho.
 *
 * Todos los demás quedan en varas², que es la costumbre del país y lo que
 * Praderas del Sol tiene firmado en 24 contratos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proyectos', function (Blueprint $table): void {
            $table->string('unidad_area', 10)->default(UnidadDeArea::Varas->value)->after('codigo');
        });

        $valores = implode(', ', array_map(
            static fn (string $unidad): string => "'{$unidad}'",
            UnidadDeArea::valores()
        ));

        DB::statement(<<<SQL
            ALTER TABLE proyectos
                ADD CONSTRAINT proyectos_unidad_area_valida_chk
                CHECK (unidad_area IN ({$valores}))
        SQL);

        // El proyecto que ya venía trabajando en metros con el truco del
        // factor uno. Ver el docblock de arriba.
        DB::table('proyectos')
            ->where('vara_en_metros', '=', 1)
            ->update(['unidad_area' => UnidadDeArea::Metros->value]);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE proyectos DROP CONSTRAINT IF EXISTS proyectos_unidad_area_valida_chk');

        Schema::table('proyectos', function (Blueprint $table): void {
            $table->dropColumn('unidad_area');
        });
    }
};
