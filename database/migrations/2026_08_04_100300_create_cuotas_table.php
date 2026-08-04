<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El plan de pagos congelado (§8.2).
 *
 * Las cuotas las calcula `App\Domain\Ventas\PlanDeCuotas` y se escriben una
 * sola vez, al activar la venta. Despues son un snapshot inmutable: si
 * manana cambia el precio de lista de los lotes, este plan no se entera.
 * Regenerarlo requiere la accion explicita "Reprogramar plan", auditada y
 * con motivo, conservando el anterior.
 *
 * ═══ LO QUE ESTA TABLA NO GUARDA, Y POR QUE ═══
 *
 * **No hay columna `estado`.** Todo lo que se querria guardar ahi es
 * derivable y se calcula (§9.D5):
 *
 *     pagada  = monto_pagado >= monto
 *     vencida = fecha_vencimiento < hoy AND monto_pagado < monto
 *
 * Almacenar `vencida` obligaria a una tarea nocturna que la recalcule, y
 * esa tarea siempre falla el dia que importa: el cliente llega a pagar, el
 * sistema dice que no debe nada, y quien atiende queda mal parado.
 *
 * **No hay columna de mora ni de interes.** R1 y R2: el saldo no genera
 * interes y el atraso no genera cargo. Un cliente atrasado debe exactamente
 * lo mismo que debia el dia del vencimiento. El estado de cuenta muestra
 * los dias de atraso —eso la administracion lo necesita— pero no cobra por
 * ellos.
 *
 * ═══ EL INDICE QUE HACE RAPIDO EL ESTADO DE CUENTA ═══
 *
 * `cuotas_pendientes_idx` es PARCIAL: solo indexa lo que todavia se debe.
 * La pregunta que el panel hace todos los dias —"¿que vence esta semana y
 * quien esta atrasado?"— toca solo esas filas, y con los anios las pagadas
 * van a ser la enorme mayoria.
 *
 * ═══ UNA VALIDACION QUE NO CABE EN UN CHECK ═══
 *
 * El §9.D7 pide `fecha_vencimiento >= fecha_contrato`. No se puede escribir
 * como CHECK: la fecha del contrato vive en `ventas` y Postgres no admite
 * CHECKs que miren otra tabla. Se podria con un trigger, pero costaria una
 * consulta extra por cada una de las 72 filas que se insertan de golpe.
 * Queda en el Service que genera el plan, con su test.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cuotas', function (Blueprint $table): void {
            $table->id();

            // Cascade: el plan no existe sin su venta. Reprogramar borra el
            // plan viejo (tras archivarlo) y escribe el nuevo.
            $table->foreignId('venta_id')->constrained('ventas')->cascadeOnDelete();

            $table->unsignedSmallInteger('numero');
            $table->date('fecha_vencimiento');

            $table->decimal('monto', 14, 2);
            $table->decimal('monto_pagado', 14, 2)->default(0);

            $table->timestamps();

            $table->unique(['venta_id', 'numero']);
            $table->index(['venta_id', 'fecha_vencimiento']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE cuotas
                ADD CONSTRAINT cuotas_numero_positivo_chk CHECK (numero > 0),
                ADD CONSTRAINT cuotas_monto_positivo_chk CHECK (monto > 0),
                ADD CONSTRAINT cuotas_pagado_no_negativo_chk CHECK (monto_pagado >= 0),
                ADD CONSTRAINT cuotas_pagado_no_supera_el_monto_chk CHECK (monto_pagado <= monto)
        SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX cuotas_pendientes_idx
                ON cuotas (fecha_vencimiento)
                WHERE monto_pagado < monto
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('cuotas');
    }
};
