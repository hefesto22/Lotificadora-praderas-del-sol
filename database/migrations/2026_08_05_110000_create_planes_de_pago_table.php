<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El precio por vara² según el plazo.
 *
 * ═══ POR QUE UNA TABLA Y NO UN ARCHIVO DE CONFIGURACION ═══
 *
 * Primero lo puse en `config/lotificadora.php`, y estaba mal: el precio de
 * lista lo decide la administracion, no un programador. Quien lo cambia
 * tiene que poder hacerlo desde el panel, sin tocar un archivo PHP ni
 * esperar un despliegue. Vive por PROYECTO —igual que todo lo demas— y no
 * suelto: dos desarrollos no tienen por que cobrar lo mismo.
 *
 * ═══ ESTO NO ES INTERES ═══
 *
 * R1 sigue en pie: el saldo financiado no devenga nada y la cuota sigue
 * siendo (valor − prima) ÷ meses. Lo que cambia con el plazo es el PRECIO
 * DE LISTA de la vara: «no es el mismo precio de vara a 1 año que a 4»
 * —Mauricio, 5-ago-2026—. Elegido el plazo, el precio queda fijo y de ahi
 * en adelante el plan de cuotas es el de siempre.
 *
 * La diferencia no es semantica: con interes, cada cuota se parte en
 * capital e interes y el estado de cuenta tiene que mostrarlos separados.
 * Aca no hay nada que separar.
 *
 * ═══ meses = 0 ES CONTADO ═══
 *
 * Misma convencion que `ventas.plazo_meses`. Si el proyecto no carga la
 * fila de contado, el plano cotiza el contado al precio propio del lote.
 *
 * El unico (proyecto_id, meses) es lo que impide dos precios distintos
 * para el mismo plazo, que es la unica forma de que este cuadro mienta.
 */
return new class extends Migration
{
    /** El mismo tope que PlanDeCuotas::PLAZO_MAXIMO_MESES. */
    private const int PLAZO_MAXIMO = 600;

    public function up(): void
    {
        Schema::create('planes_de_pago', function (Blueprint $table): void {
            $table->id();

            // Cascade: un plan de pago no significa nada sin su proyecto.
            $table->foreignId('proyecto_id')->constrained('proyectos')->cascadeOnDelete();

            $table->unsignedSmallInteger('meses');
            $table->decimal('precio_vara', 14, 2);

            // Un plan que se deja de ofrecer se apaga, no se borra: las
            // ventas que se firmaron con el siguen existiendo.
            $table->boolean('activo')->default(true);

            $table->string('etiqueta', 60)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['proyecto_id', 'meses']);
        });

        $tope = self::PLAZO_MAXIMO;

        DB::statement(<<<SQL
            ALTER TABLE planes_de_pago
                ADD CONSTRAINT planes_de_pago_precio_no_negativo_chk CHECK (precio_vara >= 0),
                ADD CONSTRAINT planes_de_pago_plazo_razonable_chk CHECK (meses <= {$tope})
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('planes_de_pago');
    }
};
