<?php

declare(strict_types=1);

use App\Domain\Enums\ModalidadDeMora;
use App\Domain\Ventas\CondicionesDeMora;
use App\Domain\Ventas\TasaDeInteres;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Interes y mora, configurables por plan de pago y apagados de fabrica (§8.5).
 *
 * ═══ POR QUE ESTO ENTRA AHORA Y NO DESPUES DEL 20-AGO ═══
 *
 * Olympo dejo de ser un sistema a medida y paso a ser un producto (Ley L0):
 * otras lotificadoras SI cobran interes y SI cobran mora, y ninguna va a
 * cambiar su contrato porque el sistema no sepa. Praderas del Sol no lo usa
 * —R1 y R2 son respuestas de la contratante del 3-ago-2026— y por eso todo
 * lo que agrega esta migracion **nace apagado**: tasa 0, mora `ninguna`.
 *
 * Y hay una razon de costo: tocar el esquema del dinero no vale lo mismo hoy,
 * sin una sola fila de produccion, que en octubre con cartera cargada.
 *
 * ═══ NADA ES NULLABLE, Y ES A PROPOSITO ═══
 *
 * `compromisos.plazo_meses` y `compromisos.prima` son NULL en un apartado
 * porque ahi **no se sabe** cual es el plazo: todavia no se vendio. La tasa
 * es distinta: un apartado no genera interes, y eso no es un dato faltante
 * sino un dato conocido que vale cero. Ponerlo NULL obligaria a que cada
 * lectura decida que significa el vacio, que es como nacen los bugs de dinero.
 *
 * ═══ EL NUMERO NUNCA SIGNIFICA DOS COSAS ═══
 *
 * Dos modalidades de mora se configuran con un MONTO en lempiras y dos con un
 * PORCENTAJE. Una sola columna `mora_valor` obligaria a mirar la modalidad
 * para saber si «200» son doscientos lempiras o doscientos por ciento. Van
 * dos columnas y un CHECK que exige que la que no corresponde este en cero:
 * asi la base no puede guardar una fila que mienta, ni siquiera por un import.
 *
 * ═══ LA CUOTA SE PARTE; LA MORA NO ES UNA CUOTA ═══
 *
 * `cuotas` gana `monto_capital` y `monto_interes`, que **suman exacto**
 * `monto` —lo dice un CHECK—. Las filas que ya existen se rellenan con todo
 * el monto en capital, que es la verdad de un plan sin interes.
 *
 * La mora NO entra ahi. Es un derivado del tiempo: guardarla como fila
 * obligaria a recalcularla todas las noches y esa tarea falla justo el dia
 * que el cliente llega a pagar (§9.D5). Se calcula al vuelo y se congela en
 * el recibo. Lo unico que `cuotas` guarda es cuanta mora YA se cobro o se
 * perdono, para no cobrarla dos veces.
 *
 * ═══ 🔴 EL TOPE DE LA TASA ES DE CORDURA, NO LEGAL ═══
 *
 * El CHECK frena un 1200 donde iba 12.00. **No dice que 120 % sea legal.** La
 * Ley de Creditos Usurarios de Honduras (Decreto 100-62) no fija un numero en
 * su texto —delega en la Secretaria de Finanzas el maximo no bancario— y
 * ademas habla de contratos de PRESTAMO, no de compraventa a plazo. Antes de
 * que una lotificadora ofrezca una tasa hay que verificarlo con un abogado:
 * ponerlo mal no es un bug, es una clausula impugnable.
 */
return new class extends Migration
{
    public function up(): void
    {
        $modalidades = "'".implode("', '", ModalidadDeMora::valores())."'";
        $tasaMaxima = TasaDeInteres::MAXIMA;
        $graciaMaxima = CondicionesDeMora::GRACIA_MAXIMA;
        $sinMora = ModalidadDeMora::Ninguna->value;

        // ─── El precio del dinero, donde se decide ───────────────────

        Schema::table('planes_de_pago', function (Blueprint $table) use ($sinMora): void {
            $table->decimal('tasa_interes_anual', 6, 3)->default(0)->after('precio_vara');
            $table->string('mora_modalidad', 20)->default($sinMora)->after('tasa_interes_anual');
            $table->decimal('mora_monto', 14, 2)->default(0)->after('mora_modalidad');
            $table->decimal('mora_porcentaje', 6, 3)->default(0)->after('mora_monto');
            $table->unsignedSmallInteger('mora_dias_gracia')->default(0)->after('mora_porcentaje');
        });

        DB::statement(<<<SQL
            ALTER TABLE planes_de_pago
                ADD CONSTRAINT planes_de_pago_tasa_razonable_chk
                CHECK (tasa_interes_anual >= 0 AND tasa_interes_anual <= {$tasaMaxima}),

                -- Un plan de CONTADO no financia nada, asi que no puede
                -- devengar interes. Sin esto, cargar 12 % en la fila de
                -- contado no rompe nada visible y desaparece en silencio.
                ADD CONSTRAINT planes_de_pago_contado_sin_interes_chk
                CHECK (meses > 0 OR tasa_interes_anual = 0),

                ADD CONSTRAINT planes_de_pago_mora_modalidad_valida_chk
                CHECK (mora_modalidad IN ({$modalidades})),

                ADD CONSTRAINT planes_de_pago_gracia_razonable_chk
                CHECK (mora_dias_gracia >= 0 AND mora_dias_gracia <= {$graciaMaxima}),

                -- La misma regla que `CondicionesDeMora::verificarCoherencia()`.
                -- Se escribe en los dos lados a proposito: la base impide que
                -- una fila mienta, el value object da el mensaje que Postgres
                -- no puede dar.
                ADD CONSTRAINT planes_de_pago_mora_coherente_chk
                CHECK (
                    (mora_modalidad = 'ninguna'
                        AND mora_monto = 0 AND mora_porcentaje = 0)
                 OR (mora_modalidad IN ('fija_por_cuota', 'fija_por_mes')
                        AND mora_monto > 0 AND mora_porcentaje = 0)
                 OR (mora_modalidad IN ('porcentaje_mensual', 'tasa_anual')
                        AND mora_porcentaje > 0 AND mora_monto = 0)
                )
        SQL);

        // ─── Lo mismo, congelado en el renglon del contrato ──────────

        /*
         * Congelar es lo que impide que subir la tasa del proyecto reescriba
         * en silencio contratos ya firmados. Es el mismo criterio con el que
         * ya se congelan area, precio de lista, precio pactado, plazo y prima:
         * el compromiso ES el renglon del contrato, y un contrato no cambia
         * porque cambie la lista de precios.
         */
        Schema::table('compromisos', function (Blueprint $table) use ($sinMora): void {
            $table->decimal('tasa_interes_anual', 6, 3)->default(0)->after('prima');
            $table->string('mora_modalidad', 20)->default($sinMora)->after('tasa_interes_anual');
            $table->decimal('mora_monto', 14, 2)->default(0)->after('mora_modalidad');
            $table->decimal('mora_porcentaje', 6, 3)->default(0)->after('mora_monto');
            $table->unsignedSmallInteger('mora_dias_gracia')->default(0)->after('mora_porcentaje');
        });

        DB::statement(<<<SQL
            ALTER TABLE compromisos
                ADD CONSTRAINT compromisos_tasa_razonable_chk
                CHECK (tasa_interes_anual >= 0 AND tasa_interes_anual <= {$tasaMaxima}),

                ADD CONSTRAINT compromisos_mora_modalidad_valida_chk
                CHECK (mora_modalidad IN ({$modalidades})),

                ADD CONSTRAINT compromisos_gracia_razonable_chk
                CHECK (mora_dias_gracia >= 0 AND mora_dias_gracia <= {$graciaMaxima}),

                ADD CONSTRAINT compromisos_mora_coherente_chk
                CHECK (
                    (mora_modalidad = 'ninguna'
                        AND mora_monto = 0 AND mora_porcentaje = 0)
                 OR (mora_modalidad IN ('fija_por_cuota', 'fija_por_mes')
                        AND mora_monto > 0 AND mora_porcentaje = 0)
                 OR (mora_modalidad IN ('porcentaje_mensual', 'tasa_anual')
                        AND mora_porcentaje > 0 AND mora_monto = 0)
                )
        SQL);

        // ─── La cuota, partida en dos ────────────────────────────────

        Schema::table('cuotas', function (Blueprint $table): void {
            // Nullable en este paso: se rellenan abajo y recien despues se
            // exige NOT NULL. Con filas ya escritas no hay otra forma.
            $table->decimal('monto_capital', 14, 2)->nullable()->after('monto');
            $table->decimal('monto_interes', 14, 2)->nullable()->after('monto_capital');

            // Cuanta mora de ESTA cuota ya se cobro o se perdono. Derivable de
            // las aplicaciones y guardado igual, por la misma razon que
            // `monto_pagado`: el estado de cuenta lo consulta lote por lote.
            $table->decimal('mora_pagada', 14, 2)->default(0)->after('monto_pagado');
            $table->decimal('mora_condonada', 14, 2)->default(0)->after('mora_pagada');
        });

        // Todo lo que existe hoy es un plan sin interes (R1): el monto entero
        // es capital. No es un default de conveniencia — es la verdad de esas
        // filas, y despues de esto el CHECK las cubre igual que a las nuevas.
        DB::statement('UPDATE cuotas SET monto_capital = monto, monto_interes = 0 WHERE monto_capital IS NULL');

        Schema::table('cuotas', function (Blueprint $table): void {
            $table->decimal('monto_capital', 14, 2)->nullable(false)->change();
            $table->decimal('monto_interes', 14, 2)->nullable(false)->change();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE cuotas
                ADD CONSTRAINT cuotas_partes_no_negativas_chk
                CHECK (monto_capital >= 0 AND monto_interes >= 0),

                -- La igualdad que sostiene el estado de cuenta. Sin esto, un
                -- import o un UPDATE a mano puede dejar una cuota cuyas partes
                -- no suman lo que se cobra, y eso no se descubre hasta que un
                -- cliente saca la calculadora.
                ADD CONSTRAINT cuotas_partes_suman_el_monto_chk
                CHECK (monto_capital + monto_interes = monto),

                ADD CONSTRAINT cuotas_mora_no_negativa_chk
                CHECK (mora_pagada >= 0 AND mora_condonada >= 0)
        SQL);

        // ─── El reparto del pago, renglon por renglon ────────────────

        Schema::table('aplicaciones_de_pago', function (Blueprint $table): void {
            $table->decimal('monto_mora', 14, 2)->default(0)->after('monto');
            $table->decimal('monto_interes', 14, 2)->default(0)->after('monto_mora');
            $table->decimal('monto_capital', 14, 2)->nullable()->after('monto_interes');

            /*
             * FUERA de `monto` a proposito: lo condonado no es dinero que
             * entro por la puerta, asi que no puede sumar al renglon del
             * recibo. Vive acá igual porque es lo unico que le permite a
             * `anular()` deshacer el perdon de ESTE recibo sin borrar el de
             * otro: una cuota puede arrastrar condonaciones de dos recibos.
             */
            $table->decimal('mora_condonada', 14, 2)->default(0)->after('monto_capital');
        });

        DB::statement('UPDATE aplicaciones_de_pago SET monto_capital = monto WHERE monto_capital IS NULL');

        Schema::table('aplicaciones_de_pago', function (Blueprint $table): void {
            $table->decimal('monto_capital', 14, 2)->nullable(false)->change();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE aplicaciones_de_pago
                ADD CONSTRAINT aplicaciones_partes_no_negativas_chk
                CHECK (
                    monto_mora >= 0 AND monto_interes >= 0
                    AND monto_capital >= 0 AND mora_condonada >= 0
                ),

                -- Con esto, «¿a que se aplico este pago?» tiene una sola
                -- respuesta posible y cuadra contra el recibo impreso.
                ADD CONSTRAINT aplicaciones_partes_suman_el_monto_chk
                CHECK (monto_mora + monto_interes + monto_capital = monto)
        SQL);

        // ─── La mora congelada en el papel ───────────────────────────

        Schema::table('recibos', function (Blueprint $table): void {
            $table->decimal('monto_mora', 14, 2)->default(0)->after('monto');
            $table->decimal('mora_condonada', 14, 2)->default(0)->after('monto_mora');
            $table->text('motivo_condonacion')->nullable()->after('mora_condonada');
            $table->foreignId('condonada_por')->nullable()->after('motivo_condonacion')
                ->constrained('users')->nullOnDelete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE recibos
                ADD CONSTRAINT recibos_mora_no_negativa_chk
                CHECK (monto_mora >= 0 AND mora_condonada >= 0),

                -- Perdonar mora es un tramite, no un campo que se deja en
                -- cero: sin motivo escrito no hay condonacion, igual que el
                -- descuento de R4 y la anulacion de un recibo.
                ADD CONSTRAINT recibos_condonacion_con_motivo_chk
                CHECK (
                    mora_condonada = 0
                    OR (motivo_condonacion IS NOT NULL AND btrim(motivo_condonacion) <> '')
                ),

                -- La mora cobrada no puede superar lo que entro por la puerta.
                ADD CONSTRAINT recibos_mora_cabe_en_el_monto_chk
                CHECK (monto_mora <= monto)
        SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE recibos
                DROP CONSTRAINT IF EXISTS recibos_mora_no_negativa_chk,
                DROP CONSTRAINT IF EXISTS recibos_condonacion_con_motivo_chk,
                DROP CONSTRAINT IF EXISTS recibos_mora_cabe_en_el_monto_chk
        SQL);

        Schema::table('recibos', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('condonada_por');
            $table->dropColumn(['monto_mora', 'mora_condonada', 'motivo_condonacion']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE aplicaciones_de_pago
                DROP CONSTRAINT IF EXISTS aplicaciones_partes_no_negativas_chk,
                DROP CONSTRAINT IF EXISTS aplicaciones_partes_suman_el_monto_chk
        SQL);

        Schema::table('aplicaciones_de_pago', function (Blueprint $table): void {
            $table->dropColumn(['monto_mora', 'monto_interes', 'monto_capital', 'mora_condonada']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE cuotas
                DROP CONSTRAINT IF EXISTS cuotas_partes_no_negativas_chk,
                DROP CONSTRAINT IF EXISTS cuotas_partes_suman_el_monto_chk,
                DROP CONSTRAINT IF EXISTS cuotas_mora_no_negativa_chk
        SQL);

        Schema::table('cuotas', function (Blueprint $table): void {
            $table->dropColumn(['monto_capital', 'monto_interes', 'mora_pagada', 'mora_condonada']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE compromisos
                DROP CONSTRAINT IF EXISTS compromisos_tasa_razonable_chk,
                DROP CONSTRAINT IF EXISTS compromisos_mora_modalidad_valida_chk,
                DROP CONSTRAINT IF EXISTS compromisos_gracia_razonable_chk,
                DROP CONSTRAINT IF EXISTS compromisos_mora_coherente_chk
        SQL);

        Schema::table('compromisos', function (Blueprint $table): void {
            $table->dropColumn([
                'tasa_interes_anual',
                'mora_modalidad',
                'mora_monto',
                'mora_porcentaje',
                'mora_dias_gracia',
            ]);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE planes_de_pago
                DROP CONSTRAINT IF EXISTS planes_de_pago_tasa_razonable_chk,
                DROP CONSTRAINT IF EXISTS planes_de_pago_contado_sin_interes_chk,
                DROP CONSTRAINT IF EXISTS planes_de_pago_mora_modalidad_valida_chk,
                DROP CONSTRAINT IF EXISTS planes_de_pago_gracia_razonable_chk,
                DROP CONSTRAINT IF EXISTS planes_de_pago_mora_coherente_chk
        SQL);

        Schema::table('planes_de_pago', function (Blueprint $table): void {
            $table->dropColumn([
                'tasa_interes_anual',
                'mora_modalidad',
                'mora_monto',
                'mora_porcentaje',
                'mora_dias_gracia',
            ]);
        });
    }
};
