<?php

declare(strict_types=1);

use App\Domain\Enums\FormaDePago;
use App\Domain\Enums\TipoCorrelativo;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El primer EGRESO del sistema (R14).
 *
 * ═══ POR QUE HACIA FALTA ═══
 *
 * Hasta hoy Olympo solo sabia de dinero ENTRANDO. Habia recibos y no habia
 * comprobantes de salida — hasta el docblock de `RegistroDePagos::anular()`
 * lo decia: «no devuelve dinero… eso es un egreso, y no existe todavia».
 *
 * Y la seña de un apartado que se cae ES dinero que sale. R14 lo pide con
 * todas las letras: «la devolucion del apartado vencido genera un movimiento
 * de salida **con su documento**, no una fila que se borra. El dinero entro
 * con un recibo; tiene que salir con un respaldo».
 *
 * Lo que existia era `compromisos.senia_devuelta_el`: una fecha que sacaba el
 * pendiente de la lista. No decia cuanto salio, no admitia devolver una parte
 * y no dejaba papel. Servia para no olvidarse; no para cuadrar una caja.
 *
 * ═══ POR QUE UNA TABLA Y NO COLUMNAS EN `compromisos` ═══
 *
 * Porque un egreso es una cosa con nombre propio, y porque la rescision por
 * lote (R22) va a colgar de esta misma tabla: ahi tambien se pregunta cuanto
 * se le devolvio al cliente, y la respuesta tambien puede ser cero. Meterlo
 * como tres columnas del apartado obligaria a repetirlas en `ventas` el mes
 * que viene.
 *
 * Por eso `compromiso_id` y `venta_id` son las dos nullable: hoy nace de un
 * apartado, mañana de una venta rescindida.
 *
 * ═══ 🔴 LOS TRES MONTOS SE GUARDAN, NO SE DERIVAN ═══
 *
 * `retenido` es `recibido − devuelto` y podria calcularse. Se guarda igual, y
 * un CHECK obliga a que cuadre, por la misma razon que
 * `reprogramaciones_saldo_cuadra_chk`: el comprobante que el cliente firmo
 * dice tres numeros, y esos tres numeros tienen que seguir diciendo lo mismo
 * dentro de cinco años aunque alguien cambie una formula.
 */
return new class extends Migration
{
    public function up(): void
    {
        $formas = "'".implode("', '", FormaDePago::valores())."'";

        Schema::create('devoluciones', function (Blueprint $table): void {
            $table->id();

            /*
             * La serie propia de R12-bis. Unico: dos comprobantes con el
             * mismo numero es exactamente lo que una serie existe para
             * impedir.
             */
            $table->unsignedBigInteger('numero')->unique();

            /*
             * Las dos nullable y ninguna de las dos de adorno: hoy toda
             * devolucion nace de un apartado liberado; la rescision (R22) va
             * a nacer de una venta. Un CHECK obliga a que haya exactamente
             * una de las dos.
             */
            $table->foreignId('compromiso_id')->nullable()->constrained('compromisos')->restrictOnDelete();
            $table->foreignId('venta_id')->nullable()->constrained('ventas')->restrictOnDelete();

            $table->foreignId('cliente_id')->constrained('clientes')->restrictOnDelete();

            /*
             * El recibo por el que ese dinero habia entrado. La traza que
             * contesta «¿de donde salieron estos L 5,000?».
             *
             * `restrictOnDelete` por lo mismo que en `recibos`: un papel
             * entregado no desaparece porque alguien borre una fila.
             */
            $table->foreignId('recibo_id')->nullable()->constrained('recibos')->restrictOnDelete();

            $table->decimal('monto_recibido', 14, 2);
            $table->decimal('monto_devuelto', 14, 2);
            $table->decimal('monto_retenido', 14, 2);

            // Como salio el dinero (R11). En efectivo no hay referencia.
            $table->string('forma_pago', 20);
            $table->string('referencia', 60)->nullable();

            $table->text('motivo');
            $table->date('fecha');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // El corte de caja del dia filtra por fecha y forma.
            $table->index(['fecha', 'forma_pago']);
            $table->index(['compromiso_id', 'created_at']);
        });

        DB::statement(<<<SQL
            ALTER TABLE devoluciones
                ADD CONSTRAINT devoluciones_forma_valida_chk
                CHECK (forma_pago IN ({$formas})),

                -- R11: en transferencia y deposito la referencia es lo unico
                -- que despues permite cruzar esta salida contra el banco.
                ADD CONSTRAINT devoluciones_referencia_segun_forma_chk
                CHECK (
                    forma_pago = 'efectivo'
                    OR (referencia IS NOT NULL AND btrim(referencia) <> '')
                ),

                -- Igual que el descuento de R4 y el abono de R21: sin motivo
                -- escrito no hay tramite. Lo hace cumplir la base.
                ADD CONSTRAINT devoluciones_motivo_con_texto_chk
                CHECK (btrim(motivo) <> ''),

                ADD CONSTRAINT devoluciones_montos_no_negativos_chk
                CHECK (monto_recibido > 0 AND monto_devuelto >= 0 AND monto_retenido >= 0),

                -- No se puede devolver mas de lo que entro.
                ADD CONSTRAINT devoluciones_no_devuelve_de_mas_chk
                CHECK (monto_devuelto <= monto_recibido),

                -- La aritmetica del comprobante, impuesta por la base. Un
                -- centavo de diferencia tumba la transaccion entera.
                ADD CONSTRAINT devoluciones_cuadra_chk
                CHECK (monto_retenido = monto_recibido - monto_devuelto),

                -- Cuelga de un apartado O de una venta, nunca de los dos ni
                -- de ninguno. Mismo espiritu que
                -- `recibos_cuelgan_de_un_compromiso_chk` (R13).
                ADD CONSTRAINT devoluciones_cuelgan_de_algo_chk
                CHECK (
                    (compromiso_id IS NOT NULL AND venta_id IS NULL)
                    OR (compromiso_id IS NULL AND venta_id IS NOT NULL)
                )
        SQL);

        /*
         * ⚠️ 🔴 LA TRAMPA QUE CASI SE COLA
         *
         * `correlativos` tiene DOS CHECKs armados con la lista de
         * `TipoCorrelativo` **congelada en su propia migracion**. Agregar el
         * caso `devolucion` al enum no los toca: en produccion, el primer
         * intento de consumir la serie nueva rebotaria contra la base con un
         * error de constraint — y lo haria adentro de la transaccion que
         * libera un apartado, con un cliente enfrente.
         *
         * Por eso se recrean acá con la lista nueva. Cualquier serie que se
         * agregue en el futuro tiene que hacer lo mismo; esta escrito tambien
         * en el docblock del enum.
         */
        $tipos = "'".implode("', '", TipoCorrelativo::valores())."'";
        $globales = "'".implode("', '", TipoCorrelativo::valoresGlobales())."'";
        $porProyecto = "'".implode("', '", TipoCorrelativo::valoresPorProyecto())."'";

        DB::statement(<<<SQL
            ALTER TABLE correlativos
                DROP CONSTRAINT IF EXISTS correlativos_tipo_valido_chk,
                DROP CONSTRAINT IF EXISTS correlativos_alcance_segun_tipo_chk,

                ADD CONSTRAINT correlativos_tipo_valido_chk
                CHECK (tipo IN ({$tipos})),

                ADD CONSTRAINT correlativos_alcance_segun_tipo_chk
                CHECK (
                    (tipo IN ({$porProyecto}) AND proyecto_id IS NOT NULL)
                    OR (tipo IN ({$globales}) AND proyecto_id IS NULL)
                )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('devoluciones');

        /*
         * Los CHECKs de `correlativos` NO se revierten a la lista vieja: si
         * quedara alguna fila de la serie de devoluciones, el CHECK viejo no
         * la admitiria y el rollback se caeria. Dejar la lista amplia no
         * rompe nada — solo admite un tipo que ya nadie consume.
         */
    }
};
