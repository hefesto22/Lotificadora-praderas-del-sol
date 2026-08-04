<?php

declare(strict_types=1);

use App\Domain\Enums\EstadoCompromiso;
use App\Domain\Enums\TipoCompromiso;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Quien se comprometio con que lote, cuando y por cuanto.
 *
 * Hasta ahora un lote apartado o vendido solo tenia un `estado`: la
 * columna decia que estaba comprometido pero no con quien, ni desde
 * cuando, ni por que monto. Esta tabla es el respaldo de ese estado, y el
 * primer ladrillo del modulo de ventas del §8.2.
 *
 * Decisiones que quedan grabadas en la base:
 *
 * 1. UN SOLO COMPROMISO VIGENTE POR LOTE, garantizado por un indice unico
 *    PARCIAL. No es una validacion de formulario que se pueda saltear con
 *    dos pestañas abiertas o con un import: la base misma hace imposible
 *    que un lote quede apartado a dos personas a la vez. Los compromisos
 *    cerrados no molestan porque el indice solo mira los vigentes.
 *
 * 2. AREA, PRECIO Y VALOR SE CONGELAN ACA. Es lo que pide el §8.2: "el
 *    valor que vale para una venta es el congelado en venta_lote". Si
 *    manana cambia el precio por vara del proyecto, la venta cerrada
 *    conserva el suyo y el estado de cuenta del cliente sigue cuadrando.
 *
 * 3. FK COMPUESTA (lote_id, proyecto_id) contra lotes(id, proyecto_id),
 *    igual que hace `lotes` con `bloques`. Sin esto, nada impediria un
 *    compromiso que apunte a un lote de otro proyecto.
 *
 * 4. NUMERIC, nunca float (§8.3.1).
 *
 * NO se usan soft deletes: un compromiso no se borra. Se libera, se
 * convierte o se rescinde, y esos tres estados son historia que hay que
 * poder consultar.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Habilita la FK compuesta de mas abajo. `id` ya es PK, asi que
        // este unico es redundante para Postgres pero necesario como
        // destino de la referencia.
        DB::statement('ALTER TABLE lotes ADD CONSTRAINT lotes_id_proyecto_uq UNIQUE (id, proyecto_id)');

        Schema::create('compromisos', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('proyecto_id')->constrained('proyectos')->restrictOnDelete();
            $table->unsignedBigInteger('lote_id');
            $table->foreignId('cliente_id')->constrained('clientes')->restrictOnDelete();

            $table->string('tipo', 20);
            $table->string('estado', 20)->default(EstadoCompromiso::Vigente->value);

            $table->decimal('area_varas', 12, 4);
            $table->decimal('precio_vara', 14, 2);
            $table->decimal('valor', 14, 2);
            $table->decimal('monto_senia', 14, 2)->nullable();

            $table->date('fecha');
            $table->date('vence_el')->nullable();
            $table->date('cerrado_el')->nullable();
            $table->text('motivo')->nullable();
            $table->text('observaciones')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['proyecto_id', 'estado']);
            $table->index(['lote_id', 'estado']);
            $table->index('cliente_id');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE compromisos
                ADD CONSTRAINT compromisos_lote_del_mismo_proyecto_fk
                FOREIGN KEY (lote_id, proyecto_id)
                REFERENCES lotes (id, proyecto_id)
                ON DELETE RESTRICT
        SQL);

        $tipos = self::comoLista(TipoCompromiso::valores());
        $estados = self::comoLista(EstadoCompromiso::valores());

        DB::statement(<<<SQL
            ALTER TABLE compromisos
                ADD CONSTRAINT compromisos_tipo_valido_chk CHECK (tipo IN ({$tipos})),
                ADD CONSTRAINT compromisos_estado_valido_chk CHECK (estado IN ({$estados}))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE compromisos
                ADD CONSTRAINT compromisos_area_positiva_chk CHECK (area_varas > 0),
                ADD CONSTRAINT compromisos_precio_no_negativo_chk CHECK (precio_vara >= 0),
                ADD CONSTRAINT compromisos_valor_no_negativo_chk CHECK (valor >= 0),
                ADD CONSTRAINT compromisos_senia_no_negativa_chk CHECK (monto_senia IS NULL OR monto_senia >= 0)
        SQL);

        // Un vencimiento anterior a la fecha del apartado, o un cierre
        // anterior al compromiso, son datos imposibles.
        DB::statement(<<<'SQL'
            ALTER TABLE compromisos
                ADD CONSTRAINT compromisos_vencimiento_coherente_chk
                CHECK (vence_el IS NULL OR vence_el >= fecha),
                ADD CONSTRAINT compromisos_cierre_coherente_chk
                CHECK (cerrado_el IS NULL OR cerrado_el >= fecha)
        SQL);

        // Vigente y cerrado a la vez no significa nada.
        DB::statement(<<<'SQL'
            ALTER TABLE compromisos
                ADD CONSTRAINT compromisos_cierre_segun_estado_chk
                CHECK (
                    (estado = 'vigente' AND cerrado_el IS NULL)
                    OR (estado <> 'vigente' AND cerrado_el IS NOT NULL)
                )
        SQL);

        /*
         * LA REGLA QUE MAS IMPORTA. Indice unico PARCIAL: un lote puede
         * tener todos los compromisos cerrados que quiera, pero uno solo
         * vigente. Con esto, dos personas apartando el mismo lote al mismo
         * tiempo terminan con una violacion de unicidad en vez de con dos
         * clientes creyendo que el lote es suyo.
         */
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX compromisos_un_vigente_por_lote_uq
                ON compromisos (lote_id)
                WHERE estado = 'vigente'
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('compromisos');

        DB::statement('ALTER TABLE lotes DROP CONSTRAINT IF EXISTS lotes_id_proyecto_uq');
    }

    /**
     * @param list<string> $valores
     */
    private static function comoLista(array $valores): string
    {
        return implode(', ', array_map(static fn (string $v): string => "'{$v}'", $valores));
    }
};
