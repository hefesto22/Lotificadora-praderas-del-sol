<?php

declare(strict_types=1);

use App\Domain\Enums\EstadoLote;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lotes — la unidad que se vende (§8.2).
 *
 * Decisiones que quedan grabadas en la base, no solo en el código:
 *
 * 1. FK COMPUESTA (bloque_id, proyecto_id) contra bloques(id, proyecto_id).
 *    Sin esto, nada impediría que un lote del proyecto A apuntara a un
 *    bloque del proyecto B. Con dos proyectos y un import mal armado, ese
 *    error es silencioso y contamina reportes de dinero.
 *
 * 2. CHECK sobre los cuatro estados contractuales, generado desde
 *    EstadoLote::valores(). El enum es la fuente de verdad y la base no
 *    puede divergir de él.
 *
 * 3. TRIGGER que bloquea editar área, precio o valor de un lote vendido.
 *    La regla del §8.2 no puede vivir solo en el Resource de Filament: un
 *    seeder, un import o un tinker la saltearían sin enterarse.
 *
 * 4. NUMERIC, nunca float (§8.3.1). Áreas con 4 decimales, dinero con 2.
 *
 * NO se agrega un CHECK que fuerce valor = area × precio. El §8.3.4 fija
 * el patrón para columnas derivadas que se almacenan: se calculan dentro
 * de la transacción y existe un test que las recalcula desde cero y
 * compara al céntimo. Un CHECK acá haría fallar en duro un import legítimo
 * con diferencias de redondeo en origen, y todavía no sabemos cómo van a
 * entrar los ~500 lotes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lotes', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('proyecto_id')->constrained('proyectos')->restrictOnDelete();

            // Sin ->constrained(): la integridad la da la FK compuesta de
            // más abajo, que además valida que el bloque sea del mismo
            // proyecto. Una FK simple acá sería redundante.
            $table->unsignedBigInteger('bloque_id');

            $table->string('numero', 20);
            $table->decimal('area_varas', 12, 4);
            $table->decimal('precio_vara', 14, 2);
            $table->decimal('valor', 14, 2);
            $table->string('estado', 20)->default(EstadoLote::Disponible->value);
            $table->text('observaciones')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['proyecto_id', 'bloque_id', 'numero']);
            $table->index(['proyecto_id', 'estado']);
            $table->index('bloque_id');
        });

        $estados = implode(', ', array_map(
            static fn (string $estado): string => "'{$estado}'",
            EstadoLote::valores()
        ));

        DB::statement(<<<'SQL'
            ALTER TABLE lotes
                ADD CONSTRAINT lotes_bloque_del_mismo_proyecto_fk
                FOREIGN KEY (bloque_id, proyecto_id)
                REFERENCES bloques (id, proyecto_id)
                ON DELETE RESTRICT
        SQL);

        DB::statement(<<<SQL
            ALTER TABLE lotes
                ADD CONSTRAINT lotes_estado_valido_chk
                CHECK (estado IN ({$estados}))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE lotes
                ADD CONSTRAINT lotes_area_positiva_chk CHECK (area_varas > 0),
                ADD CONSTRAINT lotes_precio_no_negativo_chk CHECK (precio_vara >= 0),
                ADD CONSTRAINT lotes_valor_no_negativo_chk CHECK (valor >= 0)
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION lotes_proteger_vendido() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF OLD.estado = 'vendido' AND (
                       NEW.area_varas  IS DISTINCT FROM OLD.area_varas
                    OR NEW.precio_vara IS DISTINCT FROM OLD.precio_vara
                    OR NEW.valor       IS DISTINCT FROM OLD.valor
                ) THEN
                    RAISE EXCEPTION
                        'El lote % esta vendido: no se pueden modificar area, precio ni valor (§8.2).',
                        OLD.id
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER lotes_proteger_vendido_trg
                BEFORE UPDATE ON lotes
                FOR EACH ROW EXECUTE FUNCTION lotes_proteger_vendido();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS lotes_proteger_vendido_trg ON lotes');
        DB::unprepared('DROP FUNCTION IF EXISTS lotes_proteger_vendido()');

        Schema::dropIfExists('lotes');
    }
};
