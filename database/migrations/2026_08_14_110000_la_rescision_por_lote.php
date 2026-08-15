<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * R22: el cliente dio la prima, pagó dos meses y ya no quiere el lote.
 *
 * ═══ POR QUÉ NO HAY TABLA NUEVA ═══
 *
 * Porque `devoluciones` se diseñó el 10-ago-2026 para esto. Su propia
 * migración lo dice: «`compromiso_id` y `venta_id` son las dos nullable:
 * hoy toda devolución nace de un apartado liberado; la rescisión (R22) va a
 * nacer de una venta». Este es ese día.
 *
 * Y sobre todo: las dos cosas son **el mismo hecho contable**. Entró plata
 * por un lote, el trato se cayó, se devuelve una parte y el resto queda a
 * favor de la lotificadora. Las tres columnas que ya existen
 * —`monto_recibido`, `monto_devuelto`, `monto_retenido`, con el CHECK que
 * obliga a que resten exacto— contestan la pregunta de Mauricio del 14-ago
 * («cuánto se le devolvió y cuánto quedó a favor») sin agregar una columna.
 *
 * Partirlo en dos tablas obligaría a preguntar en dos lados «¿cuánto salió
 * de caja hoy?», que es exactamente el error que no se cometió con las
 * facturas esta misma tarde.
 *
 * ═══ QUÉ CAMBIA ═══
 *
 * 1. `tipo`, para que el papel sepa cómo se llama. Una devolución de seña y
 *    una rescisión se liquidan igual pero **no son lo mismo**: la primera
 *    suelta un apartado de quince días, la segunda deshace un contrato
 *    firmado. Se podría deducir de si `venta_id` viene lleno; se guarda
 *    explícito porque el título de un documento que el cliente firma no
 *    debería depender de una inferencia.
 *
 * 2. El CHECK de a qué cuelga cada fila. Hasta hoy exigía **exactamente
 *    uno** de los dos. Una rescisión necesita LOS DOS: el compromiso dice
 *    QUÉ LOTE se cayó —que es lo que R22 rescinde— y la venta dice de qué
 *    expediente salió. Sin el compromiso no se sabría cuál de los tres
 *    lotes del contrato volvió al plano.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devoluciones', function (Blueprint $table): void {
            /*
             * Default `senia` y no null: las filas que ya existen son todas
             * devoluciones de seña —es lo único que el sistema sabía hacer— y
             * quedan rotuladas bien sin un UPDATE de respaldo.
             */
            $table->string('tipo', 20)->default('senia')->after('numero');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE devoluciones
            ADD CONSTRAINT devoluciones_tipo_valido_chk
            CHECK (tipo IN ('senia', 'rescision'))
        SQL);

        /*
         * 🔴 El viejo exigía exactamente uno de los dos. Se va entero: un
         * ALTER que agrega no sirve, porque el que manda es el más
         * restrictivo y el viejo prohibiría toda rescisión.
         */
        DB::statement('ALTER TABLE devoluciones DROP CONSTRAINT IF EXISTS devoluciones_cuelgan_de_algo_chk');

        /*
         * El compromiso es obligatorio SIEMPRE, y eso es más estricto que
         * antes: la plata siempre entró por un lote, nunca por un expediente
         * en abstracto. La venta acompaña solo cuando hay contrato detrás.
         *
         * Los valores van escritos a mano y no salen de `TipoDeDevolucion`:
         * una migración aplicada no se vuelve a correr y no tiene por qué
         * romperse el día que el enum se mude de namespace.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE devoluciones
            ADD CONSTRAINT devoluciones_cuelgan_de_algo_chk
            CHECK (
                (tipo = 'senia' AND compromiso_id IS NOT NULL AND venta_id IS NULL)
                OR
                (tipo = 'rescision' AND compromiso_id IS NOT NULL AND venta_id IS NOT NULL)
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE devoluciones DROP CONSTRAINT IF EXISTS devoluciones_cuelgan_de_algo_chk');
        DB::statement('ALTER TABLE devoluciones DROP CONSTRAINT IF EXISTS devoluciones_tipo_valido_chk');

        DB::statement(<<<'SQL'
            ALTER TABLE devoluciones
            ADD CONSTRAINT devoluciones_cuelgan_de_algo_chk
            CHECK (
                (compromiso_id IS NOT NULL AND venta_id IS NULL)
                OR (compromiso_id IS NULL AND venta_id IS NOT NULL)
            )
        SQL);

        Schema::table('devoluciones', function (Blueprint $table): void {
            $table->dropColumn('tipo');
        });
    }
};
