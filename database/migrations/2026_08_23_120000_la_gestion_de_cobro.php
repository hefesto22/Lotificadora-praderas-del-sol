<?php

declare(strict_types=1);

use App\Domain\Enums\ResultadoDeGestion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A quién hay que llamar hoy, y qué pasó cuando se llamó — 23-ago-2026
 *
 * Mauricio: «que ahí se vean las personas que llevan cuota atrasada o les
 * toca pago ese día, así evitamos las notificaciones y se van listando ahí…
 * le llaman de que le toca cuota y marcan que ya se contactaron con él».
 *
 * ═══ POR QUE UNA TABLA Y NO UNA COLUMNA EN `ventas` ═══
 *
 * Un `contactado_el` en la venta contestaría «¿ya lo llamaron?» y nada más.
 * A la semana la pregunta real es otra: **cuántas veces se lo llamó, quién,
 * y qué contestó cada vez**. Un cliente que prometió tres veces y no pagó
 * ninguna es un caso distinto al que no atiende el teléfono, y con una
 * columna que se pisa a sí misma los dos se ven iguales.
 *
 * Es un historial, no un estado. Por eso son filas.
 *
 * ═══ POR QUE CUELGA DE LA VENTA Y NO DE LA CUOTA ═══
 *
 * Porque **la llamada es una sola**. Un expediente con tres cuotas vencidas
 * no se llama tres veces, y colgar la gestión de la cuota obligaría a
 * marcarlas de a una para que el cliente desaparezca de la lista.
 *
 * Y hay una razón dura además de la humana: un abono a capital (R21)
 * **borra el plan de cuotas viejo** y escribe otro. Toda gestión colgada de
 * una cuota se iría con el `cascadeOnDelete` de la reprogramación, y el
 * historial de cobranza se borraría porque el cliente pagó de más.
 *
 * ═══ `vuelve_el`: CUANDO REAPARECE EN LA LISTA ═══
 *
 * Es columna GENERADA, no un dato que alguien escribe:
 *
 *     COALESCE(promesa_el, contactado_el + 1)
 *
 *  - Prometió pagar el 25 → vuelve el 25. Ni antes —ya se lo llamó— ni
 *    después, que es donde se pierden los que prometen y no cumplen.
 *  - No prometió nada → vuelve mañana.
 *
 * Generada y no calculada en PHP porque de esta fecha depende que un cliente
 * SE VEA o NO SE VEA en la pantalla de cobranza. Un cálculo que vive en el
 * código se puede olvidar en la consulta de al lado; una columna generada la
 * mantiene Postgres en cada INSERT y ninguna consulta la puede contradecir.
 *
 * ⚠️ Lo que NO hace: sacar al cliente de la lista cuando paga. Eso no hace
 * falta — la lista se arma de las cuotas que deben, así que un pago la vacía
 * solo, sin que nadie tenga que desmarcar nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gestiones_de_cobro', function (Blueprint $table): void {
            $table->id();

            // Cascade: el historial de cobranza de un expediente que se borra
            // no le sirve a nadie. Borrar ventas no existe hoy (se anulan),
            // pero si algún día existe, esto no queda huérfano.
            $table->foreignId('venta_id')->constrained('ventas')->cascadeOnDelete();

            /*
             * Quién llamó. `restrictOnDelete` a propósito y al revés que
             * arriba: un usuario que se va no puede borrar la constancia de
             * las llamadas que hizo. Si hay que sacarlo, se le quita el
             * acceso; la historia queda.
             */
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();

            $table->string('resultado', 20);

            // El día de la llamada, no un timestamp: la cobranza se organiza
            // por día, y la hora exacta en que sonó el teléfono no cambia
            // ninguna decisión. `created_at` la guarda igual si algún día hace
            // falta.
            $table->date('contactado_el');
            $table->date('promesa_el')->nullable();

            $table->text('nota')->nullable();

            $table->timestamps();

            // La consulta de la pantalla busca la ÚLTIMA gestión de cada
            // venta: este índice es exactamente ese orden.
            $table->index(['venta_id', 'contactado_el', 'id']);
        });

        /*
         * Los valores salen del enum: la lista es corta, cerrada y el CHECK y
         * el `match()` de PHP tienen que decir lo mismo. A diferencia de una
         * migración de datos, acá la definición se lee UNA vez y queda escrita
         * en el esquema, así que no hay riesgo de que el enum se mude después.
         */
        $resultados = implode(', ', array_map(
            static fn (string $valor): string => "'{$valor}'",
            ResultadoDeGestion::valores(),
        ));

        DB::statement(<<<SQL
            ALTER TABLE gestiones_de_cobro
                ADD CONSTRAINT gestiones_resultado_valido_chk
                CHECK (resultado IN ({$resultados}))
        SQL);

        /*
         * La promesa y el «prometió» van juntas o no van. Las dos mitades
         * importan:
         *
         *  - «Prometió pagar» sin fecha no dice nada, y peor: haría que el
         *    cliente vuelva mañana como si no hubiera prometido.
         *  - Una fecha con «no contesta» es una promesa que nadie hizo, y
         *    escondería al cliente hasta un día que se inventó el formulario.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE gestiones_de_cobro
                ADD CONSTRAINT gestiones_promesa_solo_si_prometio_chk
                CHECK ((resultado = 'prometio') = (promesa_el IS NOT NULL))
        SQL);

        // Prometer para ayer esconde al cliente en el pasado: la fecha
        // vencería antes de existir y la fila sería un contacto que no sirvió.
        DB::statement(<<<'SQL'
            ALTER TABLE gestiones_de_cobro
                ADD CONSTRAINT gestiones_promesa_no_es_pasado_chk
                CHECK (promesa_el IS NULL OR promesa_el >= contactado_el)
        SQL);

        /*
         * `STORED` y no una vista: se consulta en el WHERE de la pantalla que
         * se abre todas las mañanas, y calcularla por fila en cada consulta
         * sería pagarlo trescientas veces por una cuenta que no cambia nunca
         * después del INSERT.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE gestiones_de_cobro
                ADD COLUMN vuelve_el date
                GENERATED ALWAYS AS (COALESCE(promesa_el, contactado_el + 1)) STORED
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('gestiones_de_cobro');
    }
};
