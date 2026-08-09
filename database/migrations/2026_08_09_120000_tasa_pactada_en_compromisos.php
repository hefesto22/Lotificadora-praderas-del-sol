<?php

declare(strict_types=1);

use App\Domain\Ventas\TasaDeInteres;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La tasa PACTADA, separada de la tasa de LISTA.
 *
 * ═══ ES LA MISMA HISTORIA DEL PRECIO, UN MES DESPUES ═══
 *
 * El 5-ago se separo `precio_vara` de `precio_vara_lista` porque el precio
 * del terreno «se negocia caso por caso» (pregunta 4, contestada por la
 * contratante). El interes es el precio del DINERO y se negocia igual: el
 * vendedor sentado frente al cliente baja medio punto para cerrar, y hasta
 * hoy la unica forma de hacerlo era cambiarle la tasa al plan del proyecto
 * entero — a todos los clientes, para siempre, sin dejar rastro.
 *
 * Quedan entonces dos tasas congeladas por compromiso:
 *
 *   tasa_interes_lista → la que ofrecia el plan de ese plazo ese dia
 *   tasa_interes_anual → la que efectivamente se firmo
 *
 * Sin las dos no se puede contestar «¿cuanto interes se resigno este mes?»
 * sin adivinar, porque la tasa del plan cambia con el tiempo.
 *
 * ═══ R4 TAMBIEN, Y POR EL MISMO MOTIVO ═══
 *
 * Bajar la tasa es regalar plata igual que bajar el precio: L 43,020.56 de
 * intereses en un lote de 250 vr² a 12 meses, con los numeros reales de
 * Praderas. Si la pactada baja de la de lista y el motivo viene vacio,
 * Postgres rechaza la fila — el mismo CHECK, con la misma prueba de btrim
 * para que una cadena de espacios no cuente como motivo. El quien y el
 * cuando ya los traen `created_by` y `created_at`.
 *
 * Subirla NO pide nada: cobrar mas caro no hay que justificarlo ante nadie.
 *
 * ═══ POR QUE NO ES NULLABLE ═══
 *
 * Mismo argumento que la migracion del 8-ago para `tasa_interes_anual`: un
 * apartado no tiene tasa porque no genera interes, y eso no es un dato
 * faltante sino un dato conocido que vale cero. Con NULL, cada lectura
 * tendria que decidir que significa el vacio.
 */
return new class extends Migration
{
    public function up(): void
    {
        $maxima = TasaDeInteres::MAXIMA;

        Schema::table('compromisos', function (Blueprint $table): void {
            $table->decimal('tasa_interes_lista', 6, 3)->nullable()->after('tasa_interes_anual');
            $table->text('motivo_tasa')->nullable()->after('motivo_descuento');
        });

        /*
         * Lo ya firmado se firmo a la tasa de lista: no habia otra forma de
         * hacerlo. Igual que el 5-ago con `precio_vara_lista`.
         */
        DB::statement('UPDATE compromisos SET tasa_interes_lista = tasa_interes_anual WHERE tasa_interes_lista IS NULL');

        // SQL crudo y no ->change(): una sola instruccion, sin depender de
        // como el grammar de turno reconstruya la columna.
        DB::statement('ALTER TABLE compromisos ALTER COLUMN tasa_interes_lista SET DEFAULT 0');
        DB::statement('ALTER TABLE compromisos ALTER COLUMN tasa_interes_lista SET NOT NULL');

        DB::statement(<<<SQL
            ALTER TABLE compromisos
                ADD CONSTRAINT compromisos_tasa_lista_razonable_chk
                CHECK (tasa_interes_lista >= 0 AND tasa_interes_lista <= {$maxima}),

                ADD CONSTRAINT compromisos_tasa_rebajada_con_motivo_chk
                CHECK (
                    tasa_interes_anual >= tasa_interes_lista
                    OR (motivo_tasa IS NOT NULL AND btrim(motivo_tasa) <> '')
                )
        SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE compromisos
                DROP CONSTRAINT IF EXISTS compromisos_tasa_lista_razonable_chk,
                DROP CONSTRAINT IF EXISTS compromisos_tasa_rebajada_con_motivo_chk
        SQL);

        Schema::table('compromisos', function (Blueprint $table): void {
            $table->dropColumn(['tasa_interes_lista', 'motivo_tasa']);
        });
    }
};
