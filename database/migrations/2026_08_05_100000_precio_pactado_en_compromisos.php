<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El precio PACTADO, separado del precio de LISTA.
 *
 * ═══ POR QUE ACA Y NO EN UNA TABLA venta_lote ═══
 *
 * R9 en docs/dominio.md dice `venta_lote`. `Venta.php` dice lo contrario, y
 * tiene razon: «los lotes de una venta son sus compromisos de tipo venta,
 * que ya congelan area, precio y valor. Una sola tabla congelando el
 * dinero, no dos discrepando». Esta migracion sigue esa decision —la
 * posterior y la mejor— y el documento se corrige para que digan lo mismo.
 *
 * ═══ QUE PROBLEMA RESUELVE ═══
 *
 * Hasta hoy el compromiso copiaba `lotes.precio_vara` tal cual y no habia
 * forma de vender a otro precio. Pero el precio es negociable —lo dijo la
 * contratante en la pregunta 4: «se negocia caso por caso»— y ademas
 * dependera del plazo, que a 4 anos no cuesta lo mismo que de contado.
 *
 * Quedan entonces DOS precios congelados por compromiso:
 *
 *   precio_vara_lista → lo que el lote valia en la lista ese dia
 *   precio_vara       → lo que efectivamente se firmo
 *
 * Sin los dos no se puede contestar «¿cuanto se descontó este mes?» sin
 * adivinar, porque el precio de lista del lote cambia con el tiempo.
 *
 * ═══ R4 ES UN CHECK, NO UNA COSTUMBRE ═══
 *
 * «El sistema deja registrar un descuento a mano, y para eso exige motivo
 * escrito y guarda que usuario lo aplico y cuando. Un descuento sin motivo
 * no se graba.» Eso ultimo es literal: si el precio pactado baja del de
 * lista y el motivo viene vacio, Postgres rechaza la fila. El quien y el
 * cuando ya los traen `created_by` y `created_at`.
 *
 * El motivo se valida con btrim: una cadena de espacios no es un motivo.
 *
 * ═══ EL VALOR TIENE QUE SER SU PROPIA MULTIPLICACION ═══
 *
 * `valor = ROUND(area_varas * precio_vara, 2)` no existia como invariante y
 * es el corazon del §8.3.1. Postgres redondea numeric half-up igual que
 * bcmath en Monto::redondeado(), asi que los dos lados dan el mismo numero.
 *
 * Si esta migracion falla en una base con datos, no es un problema de la
 * migracion: hay un compromiso cuyo valor no coincide con su propio precio
 * por su propia area, y eso hay que mirarlo antes de seguir vendiendo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compromisos', function (Blueprint $table): void {
            $table->decimal('precio_vara_lista', 14, 2)->nullable()->after('precio_vara');
            $table->text('motivo_descuento')->nullable()->after('valor');
        });

        // Lo ya registrado se vendio al precio de lista: no habia otra forma.
        DB::statement('UPDATE compromisos SET precio_vara_lista = precio_vara WHERE precio_vara_lista IS NULL');

        // SQL crudo y no ->change(): una sola instruccion, sin depender de
        // como el grammar de turno reconstruya la columna.
        DB::statement('ALTER TABLE compromisos ALTER COLUMN precio_vara_lista SET NOT NULL');

        DB::statement(<<<'SQL'
            ALTER TABLE compromisos
                ADD CONSTRAINT compromisos_precio_lista_no_negativo_chk
                CHECK (precio_vara_lista >= 0),

                ADD CONSTRAINT compromisos_descuento_con_motivo_chk
                CHECK (
                    precio_vara >= precio_vara_lista
                    OR (motivo_descuento IS NOT NULL AND btrim(motivo_descuento) <> '')
                ),

                ADD CONSTRAINT compromisos_valor_es_area_por_precio_chk
                CHECK (valor = ROUND(area_varas * precio_vara, 2))
        SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE compromisos
                DROP CONSTRAINT IF EXISTS compromisos_precio_lista_no_negativo_chk,
                DROP CONSTRAINT IF EXISTS compromisos_descuento_con_motivo_chk,
                DROP CONSTRAINT IF EXISTS compromisos_valor_es_area_por_precio_chk
        SQL);

        Schema::table('compromisos', function (Blueprint $table): void {
            $table->dropColumn(['precio_vara_lista', 'motivo_descuento']);
        });
    }
};
