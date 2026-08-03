<?php

declare(strict_types=1);

use App\Domain\Enums\TipoCalle;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Geometría del plano: la forma de cada lote y el trazado de las calles.
 *
 * Decisiones que conviene tener escritas antes de que alguien las cambie
 * sin saber lo que costaron:
 *
 * 1. COORDENADAS EN VARAS, no en píxeles ni en lat/lng. El plano de una
 *    lotificación es un dibujo plano y local, no geodesia. Guardar varas
 *    permite comparar el área del polígono contra `area_varas` sin factor
 *    de conversión de por medio, que es justo la comparación que importa.
 *
 * 2. NO se usa PostGIS. Agregaría una extensión al servidor, y su valor
 *    —proyecciones, distancias sobre el elipsoide, índices espaciales—
 *    no aplica a un dibujo de 500 polígonos que se renderiza entero.
 *
 * 3. ORIGEN ARRIBA-IZQUIERDA, Y HACIA ABAJO. Es la convención de SVG.
 *    Guardarlo así evita invertir el eje en cada render, a cambio de que
 *    un topógrafo que lea la tabla cruda vea el norte al revés. Como los
 *    datos entran por el sistema y no por el topógrafo, gana el render.
 *
 * 4. `poligono` es NULLABLE. Los lotes ya cargados no tienen geometría y
 *    tienen que seguir funcionando: el plano es una capa encima del
 *    negocio, no un requisito para vender.
 *
 * 5. LAS CALLES SON LÍNEA + ANCHO, no polígono. "Avenida Principal, 10
 *    varas de ancho" son dos puntos y un número; como polígono serían
 *    cuatro vértices que hay que mantener paralelos a mano cada vez que
 *    alguien mueve la calle.
 *
 * NO se agrega columna de área calculada desde el polígono. El §8.3.4
 * permite almacenar derivados, pero acá el derivado no debe existir: el
 * área que vale es `area_varas`, la cargada del plano legal. El polígono
 * es dibujo. Si difieren, el sistema avisa —ver Lote::poligonoDesalineado()—
 * pero jamás corrige.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lotes', function (Blueprint $table): void {
            $table->jsonb('poligono')->nullable();
        });

        // Un polígono con menos de 3 vértices no es una figura. El CHECK
        // vive en la base y no solo en el editor porque la geometría va a
        // entrar también por generador y por import.
        DB::statement(<<<'SQL'
            ALTER TABLE lotes
                ADD CONSTRAINT lotes_poligono_valido_chk
                CHECK (
                    poligono IS NULL
                    OR (
                        jsonb_typeof(poligono) = 'array'
                        AND jsonb_array_length(poligono) >= 3
                    )
                )
        SQL);

        Schema::create('calles', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('proyecto_id')->constrained('proyectos')->restrictOnDelete();

            $table->string('nombre', 60)->nullable();
            $table->string('tipo', 20);
            $table->decimal('ancho_varas', 8, 4);
            $table->jsonb('trazo');
            $table->unsignedSmallInteger('orden')->default(0);
            $table->text('observaciones')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['proyecto_id', 'orden']);
        });

        $tipos = implode(', ', array_map(
            static fn (string $tipo): string => "'{$tipo}'",
            TipoCalle::valores()
        ));

        DB::statement(<<<SQL
            ALTER TABLE calles
                ADD CONSTRAINT calles_tipo_valido_chk
                CHECK (tipo IN ({$tipos}))
        SQL);

        // Una línea necesita al menos dos puntos. Mismo criterio que el
        // polígono: la regla vive donde nadie la puede saltear.
        DB::statement(<<<'SQL'
            ALTER TABLE calles
                ADD CONSTRAINT calles_ancho_positivo_chk CHECK (ancho_varas > 0),
                ADD CONSTRAINT calles_trazo_valido_chk
                CHECK (
                    jsonb_typeof(trazo) = 'array'
                    AND jsonb_array_length(trazo) >= 2
                )
        SQL);

        // Único PARCIAL: dos calles sin nombre pueden convivir —hay
        // lotificaciones donde solo las principales lo tienen— pero dos
        // "Avenida Principal" en el mismo proyecto son un error de carga.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX calles_proyecto_nombre_uq
                ON calles (proyecto_id, nombre)
                WHERE nombre IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('calles');

        DB::statement('ALTER TABLE lotes DROP CONSTRAINT IF EXISTS lotes_poligono_valido_chk');

        Schema::table('lotes', function (Blueprint $table): void {
            $table->dropColumn('poligono');
        });
    }
};
