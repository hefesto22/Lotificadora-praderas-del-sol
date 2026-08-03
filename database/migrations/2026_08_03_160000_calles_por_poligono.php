<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Una calle se puede definir de dos maneras, y las dos son legitimas.
 *
 *  - DIBUJADA A MANO: un eje (`trazo`) y un ancho. Es como piensa una
 *    persona que traza el plano: "la avenida principal, 10 varas".
 *  - IMPORTADA DE UN DXF: el poligono del area de la calle. Es como lo
 *    dibuja el topografo, porque en el plano legal la calle es una
 *    superficie con sus linderos, no una linea con un ancho.
 *
 * Forzar la segunda a la primera obligaria a inventarle un eje a un
 * poligono irregular, que es justo la clase de dato inventado que despues
 * nadie sabe de donde salio. Asi que la tabla admite las dos y exige que
 * haya al menos una.
 *
 * `trazo` y `ancho_varas` pasan a ser opcionales, pero SIGUEN yendo
 * juntos: un eje sin ancho no se puede dibujar y un ancho sin eje no
 * describe nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calles', function (Blueprint $table): void {
            $table->jsonb('poligono')->nullable();
        });

        DB::statement('ALTER TABLE calles ALTER COLUMN trazo DROP NOT NULL');
        DB::statement('ALTER TABLE calles ALTER COLUMN ancho_varas DROP NOT NULL');

        DB::statement('ALTER TABLE calles DROP CONSTRAINT IF EXISTS calles_ancho_positivo_chk');
        DB::statement('ALTER TABLE calles DROP CONSTRAINT IF EXISTS calles_trazo_valido_chk');

        DB::statement(<<<'SQL'
            ALTER TABLE calles
                ADD CONSTRAINT calles_ancho_positivo_chk
                CHECK (ancho_varas IS NULL OR ancho_varas > 0)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE calles
                ADD CONSTRAINT calles_trazo_valido_chk
                CHECK (
                    trazo IS NULL
                    OR (jsonb_typeof(trazo) = 'array' AND jsonb_array_length(trazo) >= 2)
                )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE calles
                ADD CONSTRAINT calles_poligono_valido_chk
                CHECK (
                    poligono IS NULL
                    OR (jsonb_typeof(poligono) = 'array' AND jsonb_array_length(poligono) >= 3)
                )
        SQL);

        // Una calle sin ninguna de las dos formas es una fila que no se
        // puede dibujar ni medir: no deberia poder existir.
        DB::statement(<<<'SQL'
            ALTER TABLE calles
                ADD CONSTRAINT calles_con_forma_chk
                CHECK (
                    (trazo IS NOT NULL AND ancho_varas IS NOT NULL)
                    OR poligono IS NOT NULL
                )
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE calles DROP CONSTRAINT IF EXISTS calles_con_forma_chk');
        DB::statement('ALTER TABLE calles DROP CONSTRAINT IF EXISTS calles_poligono_valido_chk');
        DB::statement('ALTER TABLE calles DROP CONSTRAINT IF EXISTS calles_trazo_valido_chk');
        DB::statement('ALTER TABLE calles DROP CONSTRAINT IF EXISTS calles_ancho_positivo_chk');

        DB::statement('DELETE FROM calles WHERE trazo IS NULL OR ancho_varas IS NULL');

        Schema::table('calles', function (Blueprint $table): void {
            $table->dropColumn('poligono');
        });

        DB::statement('ALTER TABLE calles ALTER COLUMN trazo SET NOT NULL');
        DB::statement('ALTER TABLE calles ALTER COLUMN ancho_varas SET NOT NULL');

        DB::statement(<<<'SQL'
            ALTER TABLE calles
                ADD CONSTRAINT calles_ancho_positivo_chk CHECK (ancho_varas > 0),
                ADD CONSTRAINT calles_trazo_valido_chk
                CHECK (jsonb_typeof(trazo) = 'array' AND jsonb_array_length(trazo) >= 2)
        SQL);
    }
};
