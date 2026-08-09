<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Donde queda el proyecto, para el boton «Como llegar» del plano publico.
 *
 * ═══ POR QUE DOS NUMEROS Y NO LA DIRECCION QUE YA HAY ═══
 *
 * `direccion` existe desde el dia uno y sirve para el contrato. Pero «Aldea
 * El Zapote, contiguo al beneficio» no abre Waze. Despues del precio, la
 * segunda pregunta que hace todo el mundo es donde queda, y en un telefono la
 * respuesta util no es un texto: es un boton que arranca la navegacion.
 *
 * ═══ NUMERIC, NO FLOAT ═══
 *
 * Mismo criterio que el resto del sistema. Siete decimales son ~1 cm en el
 * ecuador —muchisimo mas de lo que hace falta para llegar a un porton— y tres
 * enteros alcanzan para los ±180 de la longitud.
 *
 * ═══ LOS DOS O NINGUNO ═══
 *
 * Media coordenada no apunta «casi» al proyecto: una latitud sin longitud
 * cae en el meridiano cero, y el (0, 0) del mundo esta en el Golfo de Guinea.
 * El CHECK lo impide en la base, y el formulario lo repite arriba para que el
 * usuario se entere antes de guardar y no despues.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proyectos', function (Blueprint $table): void {
            $table->decimal('latitud', 10, 7)->nullable()->after('direccion');
            $table->decimal('longitud', 10, 7)->nullable()->after('latitud');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE proyectos
                ADD CONSTRAINT proyectos_ubicacion_completa_chk
                CHECK (
                    (latitud IS NULL AND longitud IS NULL)
                    OR (latitud IS NOT NULL AND longitud IS NOT NULL)
                ),

                ADD CONSTRAINT proyectos_ubicacion_en_rango_chk
                CHECK (
                    latitud IS NULL
                    OR (latitud BETWEEN -90 AND 90 AND longitud BETWEEN -180 AND 180)
                )
        SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE proyectos
                DROP CONSTRAINT IF EXISTS proyectos_ubicacion_completa_chk,
                DROP CONSTRAINT IF EXISTS proyectos_ubicacion_en_rango_chk
        SQL);

        Schema::table('proyectos', function (Blueprint $table): void {
            $table->dropColumn(['latitud', 'longitud']);
        });
    }
};
