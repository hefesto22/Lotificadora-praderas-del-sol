<?php

declare(strict_types=1);

use App\Domain\Enums\ConceptoDeRecibo;
use App\Domain\Enums\FormaDePago;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El dinero que entra, y a qué se aplicó.
 *
 * ═══ DOS TABLAS, NO UNA ═══
 *
 * `recibos` es el DOCUMENTO: un número, una fecha, una forma de pago, un monto
 * y quién pagó. Es lo que se le entrega al cliente y lo que se reimprime.
 *
 * `aplicaciones_de_pago` es a QUÉ se le puso ese dinero: un renglón por cuota
 * tocada, con cuánto le tocó. Un pago de L 100,000.00 puede cubrir la cuota 3
 * entera, la 4 entera y la mitad de la 5 — tres renglones, un solo recibo.
 *
 * Guardarlo en una sola tabla obligaría a un recibo por cuota, y entonces un
 * cliente que paga tres meses atrasados se llevaría tres papeles. O a no
 * guardar el detalle, y entonces «¿por qué la cuota 5 aparece a medias?» no
 * tendría respuesta.
 *
 * ═══ R13: NO SE COBRA SIN COMPROMISO ═══
 *
 * Todo recibo cuelga de una venta o de un apartado. No existe el «saldo a
 * favor sin aplicar» ni el cliente con dinero flotando — eso es donde estos
 * sistemas se ensucian con los años. Lo garantiza un CHECK, no una costumbre.
 *
 * ═══ R12: UNA SOLA NUMERACION ═══
 *
 * No hay series por receptor. Don Elder y don Edwin sacan números de la misma
 * secuencia, así que el correlativo se consume con bloqueo de fila dentro de
 * la transacción (§8.3.6): dos personas cobrando al mismo tiempo desde lugares
 * distintos no pueden sacar el mismo número. El índice único es la red.
 *
 * ═══ R10: LA PUERTA AL CAI, SIN CONSTRUIR NADA ═══
 *
 * `tipo_documento` existe desde hoy con un solo valor posible en la práctica.
 * El día que aparezca un talonario autorizado por el SAR se agrega el tipo sin
 * migrar los recibos ya emitidos.
 */
return new class extends Migration
{
    public function up(): void
    {
        $formas = "'".implode("', '", FormaDePago::valores())."'";
        $conceptos = "'".implode("', '", ConceptoDeRecibo::valores())."'";

        Schema::create('recibos', function (Blueprint $table): void {
            $table->id();

            // R12. Único en toda la lotificadora, no por proyecto.
            $table->unsignedBigInteger('numero')->unique();
            $table->string('tipo_documento', 20)->default('recibo_interno');

            /*
             * restrictOnDelete y no cascade: un recibo entregado en papel no
             * desaparece porque alguien borre la venta. Si hay que borrarla,
             * primero hay que decidir qué pasa con la plata.
             */
            $table->foreignId('venta_id')->nullable()->constrained('ventas')->restrictOnDelete();
            $table->foreignId('compromiso_id')->nullable()->constrained('compromisos')->restrictOnDelete();
            $table->foreignId('cliente_id')->constrained('clientes')->restrictOnDelete();

            $table->string('concepto', 20);
            $table->string('forma_pago', 20);
            $table->string('referencia', 60)->nullable();

            $table->decimal('monto', 14, 2);
            $table->date('fecha');
            $table->text('observaciones')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['venta_id', 'fecha']);
            $table->index(['cliente_id', 'fecha']);
        });

        DB::statement(<<<SQL
            ALTER TABLE recibos
                ADD CONSTRAINT recibos_monto_positivo_chk
                CHECK (monto > 0),

                ADD CONSTRAINT recibos_forma_valida_chk
                CHECK (forma_pago IN ({$formas})),

                ADD CONSTRAINT recibos_concepto_valido_chk
                CHECK (concepto IN ({$conceptos})),

                -- R11: en transferencia y depósito la referencia es obligatoria.
                -- Sin ella no hay cómo cruzar el recibo contra el banco.
                ADD CONSTRAINT recibos_referencia_cuando_hace_falta_chk
                CHECK (
                    forma_pago = 'efectivo'
                    OR (referencia IS NOT NULL AND btrim(referencia) <> '')
                ),

                -- R13: todo pago cuelga de una venta o de un apartado.
                ADD CONSTRAINT recibos_cuelgan_de_un_compromiso_chk
                CHECK (venta_id IS NOT NULL OR compromiso_id IS NOT NULL)
        SQL);

        Schema::create('aplicaciones_de_pago', function (Blueprint $table): void {
            $table->id();

            // Cascade: el detalle no existe sin su recibo. Anular un recibo
            // borra su aplicación y devuelve el saldo a las cuotas, todo
            // dentro de la misma transacción.
            $table->foreignId('recibo_id')->constrained('recibos')->cascadeOnDelete();
            $table->foreignId('cuota_id')->constrained('cuotas')->restrictOnDelete();

            $table->decimal('monto', 14, 2);
            $table->timestamps();

            // Un recibo toca cada cuota UNA vez: si cubre media y después el
            // resto, son dos recibos distintos.
            $table->unique(['recibo_id', 'cuota_id']);
            $table->index('cuota_id');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE aplicaciones_de_pago
                ADD CONSTRAINT aplicaciones_monto_positivo_chk
                CHECK (monto > 0)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('aplicaciones_de_pago');
        Schema::dropIfExists('recibos');
    }
};
