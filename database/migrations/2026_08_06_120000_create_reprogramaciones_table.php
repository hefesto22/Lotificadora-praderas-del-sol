<?php

declare(strict_types=1);

use App\Domain\Enums\ModalidadDeReprogramacion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Por que el numero cambio (R21).
 *
 * ═══ EL PROBLEMA QUE RESUELVE ═══
 *
 * Un abono a capital reescribe el plan pendiente de un lote: las cuotas que
 * nadie toco todavia se borran y se escriben otras. Sin esta tabla, el mes
 * que viene el cliente pregunta «¿por que mi cuota bajo de L 25,000.00 a
 * L 18,400.00?» y la unica respuesta disponible seria «porque si» — las
 * filas viejas ya no existen.
 *
 * ═══ EL PLAN VIEJO VA EN JSONB, NO EN FILAS ═══
 *
 * Se evaluaron las dos alternativas y las dos costaban mas:
 *
 *  - **Archivar las cuotas viejas en `cuotas`** (soft delete + marca)
 *    obligaria a tocar el indice unico `cuotas_numero_por_lote_uidx`, el FIFO
 *    de pagos, `Venta::saldoPendiente()` y todo scope que hoy lee `cuotas`
 *    sin filtro. Una sola consulta que se olvide del filtro devuelve un saldo
 *    que no existe.
 *  - **Solo la bitacora de activitylog** deja el plan viejo como propiedades
 *    sueltas, sin forma declarada, imposibles de auditar contra el contrato.
 *
 * Asi `cuotas` sigue significando exactamente una cosa —el contrato de hoy— y
 * la historia vive completa en una fila que se lee de un vistazo.
 *
 * ═══ LOS DOS INVARIANTES QUE IMPONE LA BASE ═══
 *
 * `saldo_nuevo = saldo_anterior − abono_capital` no es un comentario: es un
 * CHECK. Si el Service se equivoca en un centavo, la transaccion se cae
 * entera y no queda un expediente que no cuadra.
 *
 * Y el motivo es obligatorio de verdad, como en el descuento de R4: lo exige
 * `reprogramaciones_motivo_con_texto_chk`, no una validacion de formulario
 * que un comando de consola puede saltear.
 */
return new class extends Migration
{
    public function up(): void
    {
        $modalidades = "'".implode("', '", ModalidadDeReprogramacion::valores())."'";

        Schema::create('reprogramaciones', function (Blueprint $table): void {
            $table->id();

            // Cascade igual que `cuotas`: la historia del plan no existe sin
            // el renglon del contrato al que pertenece.
            $table->foreignId('venta_id')->constrained('ventas')->cascadeOnDelete();
            $table->foreignId('compromiso_id')->constrained('compromisos')->cascadeOnDelete();

            /*
             * Nullable a proposito. Hoy toda reprogramacion nace de un abono y
             * siempre trae su recibo; la rescision por lote (R22) va a
             * reprogramar SIN dinero entrando, y ahi no hay recibo que apuntar.
             *
             * restrictOnDelete por la misma razon que en `recibos`: un papel
             * entregado no desaparece porque alguien borre una fila.
             */
            $table->foreignId('recibo_id')->nullable()->constrained('recibos')->restrictOnDelete();

            $table->string('modalidad', 20);
            $table->text('motivo');

            // El dinero. `abono_capital` es SOLO lo que bajo el capital: lo que
            // el mismo recibo uso para poner al dia las cuotas vencidas no
            // entra aca, porque eso no reprogramo nada.
            $table->decimal('abono_capital', 14, 2);
            $table->decimal('saldo_anterior', 14, 2);
            $table->decimal('saldo_nuevo', 14, 2);

            // El antes y el despues que el cliente va a preguntar. Nullable
            // los dos: un plan puede quedar vacio si el abono lo cancelo.
            $table->decimal('cuota_anterior', 14, 2)->nullable();
            $table->decimal('cuota_nueva', 14, 2)->nullable();
            $table->unsignedSmallInteger('cuotas_antes');
            $table->unsignedSmallInteger('cuotas_despues');

            // Desde que numero se reescribio. Todo lo anterior quedo intacto,
            // incluida la cuota pagada a medias.
            $table->unsignedSmallInteger('desde_numero');

            /*
             * El plan que se reemplazo, cuota por cuota:
             * [{"numero": 6, "vence": "2027-02-05", "monto": "25000.00"}, ...]
             *
             * jsonb y no json: se consulta con operadores, no se lee como
             * texto, y Postgres lo guarda ya parseado.
             */
            $table->jsonb('plan_anterior');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['compromiso_id', 'created_at']);
            $table->index(['venta_id', 'created_at']);
        });

        DB::statement(<<<SQL
            ALTER TABLE reprogramaciones
                ADD CONSTRAINT reprogramaciones_modalidad_valida_chk
                CHECK (modalidad IN ({$modalidades})),

                -- R21 lo pide con todas las letras: «queda registrado que hubo
                -- una reprogramacion, con su motivo». Igual que el descuento de
                -- R4, lo hace cumplir la base y no un formulario.
                ADD CONSTRAINT reprogramaciones_motivo_con_texto_chk
                CHECK (btrim(motivo) <> ''),

                ADD CONSTRAINT reprogramaciones_abono_positivo_chk
                CHECK (abono_capital > 0),

                ADD CONSTRAINT reprogramaciones_saldos_no_negativos_chk
                CHECK (saldo_anterior >= 0 AND saldo_nuevo >= 0),

                -- La aritmetica de R21, impuesta por la base. Un centavo de
                -- diferencia tumba la transaccion entera.
                ADD CONSTRAINT reprogramaciones_saldo_cuadra_chk
                CHECK (saldo_nuevo = saldo_anterior - abono_capital),

                ADD CONSTRAINT reprogramaciones_desde_numero_positivo_chk
                CHECK (desde_numero > 0),

                -- Se reemplazo algo. Una reprogramacion de cero cuotas no
                -- reprogramo nada y no deberia existir como fila.
                ADD CONSTRAINT reprogramaciones_reemplazo_algo_chk
                CHECK (cuotas_antes > 0),

                -- El plan viejo es una lista, y no vacia.
                ADD CONSTRAINT reprogramaciones_plan_anterior_es_lista_chk
                CHECK (jsonb_typeof(plan_anterior) = 'array' AND jsonb_array_length(plan_anterior) > 0)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('reprogramaciones');
    }
};
