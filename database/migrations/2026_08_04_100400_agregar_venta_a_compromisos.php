<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `compromisos` pasa a ser el pivot de la venta.
 *
 * ═══ POR QUE NO EXISTE `venta_lote` ═══
 *
 * El §8.2 planeaba un pivot `venta_lote` que congelara area y precio de
 * cada lote al momento de la venta. Cuando se escribio eso, `compromisos`
 * no existia — y `compromisos` ya congela exactamente esas tres columnas,
 * ya tiene la FK compuesta que impide apuntar a un lote de otro proyecto, y
 * ya garantiza con un indice unico parcial que un lote no este comprometido
 * dos veces.
 *
 * Construir `venta_lote` seria congelar el mismo dinero en dos tablas. El
 * dia que discreparan —y discrepan, siempre— nadie sabria cual manda, y la
 * respuesta estaria en un estado de cuenta que ya se le entrego al cliente.
 *
 * Decision del 4-ago-2026: los lotes de una venta son sus compromisos de
 * tipo `venta`. `venta_lote` no se construye.
 *
 * ═══ POR QUE `venta_id` ES NULABLE ═══
 *
 * Podria parecer que todo compromiso de tipo venta deberia tener su venta.
 * No es asi, y el motivo es la carga inicial: los lotes llegan en papel
 * (R15) y entre ellos hay lotes YA VENDIDOS antes de que el sistema
 * existiera. Esas ventas viejas no tienen expediente, ni prima, ni plan de
 * cuotas — tienen un dueno y un valor. Obligarlas a inventarse una venta
 * completa seria obligar a digitar datos que nadie tiene.
 *
 * Lo que si se impone: **un apartado nunca pertenece a una venta**. Esa es
 * la parte que el CHECK protege, y es la que evita que el pivot se ensucie.
 *
 * Cuando el Service de ventas active un expediente, va a crear los
 * compromisos de tipo venta CON su `venta_id`. La regla "una venta vigente
 * tiene al menos un lote" no cabe en un CHECK y vive en ese Service.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compromisos', function (Blueprint $table): void {
            // Restrict: una venta con lotes detras no se borra. Se anula
            // desde borrador o se rescinde, y en los dos casos los
            // compromisos se cierran, no desaparecen.
            // Sin `after()`: Postgres no reordena columnas y el modificador
            // se ignora en silencio, asi que ponerlo solo confunde a quien
            // lea la migracion buscando donde quedo la columna.
            $table->foreignId('venta_id')
                ->nullable()
                ->constrained('ventas')
                ->restrictOnDelete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE compromisos
                ADD CONSTRAINT compromisos_venta_solo_en_tipo_venta_chk
                CHECK (venta_id IS NULL OR tipo = 'venta')
        SQL);

        // Los lotes de un expediente se consultan siempre juntos: el
        // estado de cuenta los lista, el contrato los imprime.
        DB::statement(<<<'SQL'
            CREATE INDEX compromisos_venta_idx
                ON compromisos (venta_id)
                WHERE venta_id IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS compromisos_venta_idx');
        DB::statement('ALTER TABLE compromisos DROP CONSTRAINT IF EXISTS compromisos_venta_solo_en_tipo_venta_chk');

        Schema::table('compromisos', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('venta_id');
        });
    }
};
