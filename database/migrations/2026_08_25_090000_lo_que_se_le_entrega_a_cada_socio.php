<?php

declare(strict_types=1);

use App\Domain\Enums\FormaDePago;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que efectivamente se le entregó a cada socio.
 *
 * ═══ QUE LO PIDIO ═══
 *
 * Mauricio, el 24-ago-2026: reportes mensuales «con porcentajes dependiendo de
 * cuánto tengan los socios, todo detallado para que mes a mes lleven todo al
 * detalle», y sobre el reparto eligió **cuenta por socio**: no alcanza con
 * decir cuánto le toca, hay que anotar cuánto se le dio.
 *
 * ═══ ESTO CONTESTA LA PREGUNTA QUE DEJO ABIERTA `socios` ═══
 *
 * La migración del 13-ago decía, con todas las letras: «no reparte nada
 * todavía; antes de escribirlo hay que saber sobre QUÉ se reparte». La
 * respuesta quedó escrita en `App\Domain\Reportes\CierreDelMes`, y es:
 *
 *   **sobre la utilidad de caja ACUMULADA del proyecto** — todo lo cobrado
 *   menos todo lo gastado y lo devuelto, desde el día uno—, y a cada socio se
 *   le acredita su porcentaje de ESO, menos lo que ya se le entregó.
 *
 * Y el porqué de acumulada y no del mes: **con una cuenta por socio, repartir
 * sobre el mes miente.** Un mes bueno reparte de más y el siguiente, malo, no
 * tiene de dónde descontarlo — no hay forma de pedir plata de vuelta. Sobre el
 * acumulado, «le tocaba X, se le entregó Y, queda Z» sigue siendo cierto en el
 * mes doce. El número del mes igual sale impreso en el reporte, porque es el
 * que la gente mira; simplemente no es la base del reparto.
 *
 * ═══ ES UNA SALIDA DE CAJA, NO UN GASTO ═══
 *
 * ⚠️ Y por eso NO va a `gastos`. Un gasto es lo que el desarrollo costó —la
 * retroexcavadora, el registro, la publicidad— y se resta ANTES de saber qué
 * hay para repartir. Lo que se le entrega a un socio sale de esa utilidad ya
 * calculada: meterlo entre los gastos lo restaría dos veces y haría que el
 * proyecto pareciera menos rentable cada vez que alguien retira su parte.
 *
 * ═══ LO QUE ESTA TABLA NO TRAE, Y ES A PROPOSITO ═══
 *
 * **No tiene correlativo propio.** Los recibos, los gastos y las devoluciones
 * lo llevan porque son papeles que se entregan y se numeran. Una entrega a un
 * socio hoy se registra para que cuadre la cuenta, no para imprimirse. El día
 * que haga falta un comprobante firmado, se le agrega su serie en
 * `correlativos` como se hizo con las otras tres — y no antes, porque un
 * número que nadie usa igual hay que mantenerlo.
 */
return new class extends Migration
{
    public function up(): void
    {
        $formas = "'".implode("', '", FormaDePago::valores())."'";

        Schema::create('entregas_a_socios', function (Blueprint $table): void {
            $table->id();

            /*
             * Los DOS: el socio ya sabe de qué proyecto es, pero la columna
             * propia deja que el reporte de un proyecto pida sus entregas sin
             * pasar por `socios`, y que un índice las agrupe por mes.
             */
            $table->foreignId('proyecto_id')->constrained('proyectos')->restrictOnDelete();
            $table->foreignId('socio_id')->constrained('socios')->restrictOnDelete();

            $table->decimal('monto', 14, 2);

            $table->string('forma_pago', 20);
            $table->string('referencia', 60)->nullable();

            $table->date('fecha');

            /*
             * A qué mes se imputa, que NO siempre es el de la fecha: se puede
             * entregar el 3 de septiembre lo que corresponde a agosto. Sin
             * esto, el reporte de agosto no vería su propia entrega y el de
             * septiembre mostraría una que no le toca.
             *
             * Es el primer día del mes: una fecha se compara e indexa sin
             * inventar un formato de texto.
             */
            $table->date('mes');

            $table->text('observaciones')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // El reporte del mes pide las entregas de ESE mes, y la cuenta de
            // un socio las suya en orden.
            $table->index(['proyecto_id', 'mes']);
            $table->index(['socio_id', 'fecha']);
        });

        DB::statement(<<<SQL
            ALTER TABLE entregas_a_socios
                ADD CONSTRAINT entregas_forma_valida_chk
                CHECK (forma_pago IN ({$formas})),

                -- R11, igual que en recibos, gastos y devoluciones: en todo lo
                -- que no es efectivo la referencia es lo unico que despues
                -- permite cruzar el movimiento contra el banco.
                ADD CONSTRAINT entregas_referencia_segun_forma_chk
                CHECK (
                    forma_pago = 'efectivo'
                    OR (referencia IS NOT NULL AND btrim(referencia) <> '')
                ),

                -- Una entrega de cero no es una entrega. Y una negativa seria
                -- «el socio devolvio plata», que es otro tramite y todavia no
                -- existe: se rechaza en vez de dejar entrar un signo que
                -- despues nadie sabe leer.
                ADD CONSTRAINT entregas_monto_positivo_chk
                CHECK (monto > 0),

                -- El mes se guarda como su primer dia. Con cualquier otro, dos
                -- entregas del mismo mes se agruparian en dos meses distintos.
                ADD CONSTRAINT entregas_mes_es_dia_uno_chk
                CHECK (EXTRACT(DAY FROM mes) = 1)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('entregas_a_socios');
    }
};
