<?php

declare(strict_types=1);

use App\Domain\Enums\EstadoLote;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cuántos lotes de este desarrollo se guardan para la familia.
 *
 * Lo pidió Mauricio el 13-ago-2026, con el cupo de donaciones recién
 * entregado: «para los reservados, estos son para lotes heredados, así que
 * también hay que colocarlo; como reservados o herencia, que se configuran
 * y se marcan al inicio del proyecto».
 *
 * ═══ POR QUÉ EL MISMO TRATO QUE LAS DONACIONES ═══
 *
 * Porque es el mismo problema. Un lote reservado sale del mercado por
 * decisión de la lotificadora y nunca va a generar un lempira: es la otra
 * forma —además de donar— de que el inventario se achique sin una venta
 * atrás. Cuántos se guardan para la familia es una decisión que se toma
 * cuando se arma el desarrollo, no un botón que quede encendido para
 * siempre esperando a que alguien se equivoque.
 *
 * ⚠️ LA COLUMNA SE LLAMA `reserva_lotes` Y LA PANTALLA DICE «HERENCIA».
 * No es un descuido: el estado del lote es `reservado` —así se llama en la
 * base, en el enum y en la leyenda del plano público, donde «reservado» es
 * una palabra que el comprador entiende sola—. «Herencia» es lo que ES, y
 * por eso es lo que leen adentro la señora de la oficina y quien configura
 * el proyecto. Decisión de Mauricio: «herencia adentro, reservado afuera».
 *
 * ═══ EL RELLENO: POR QUÉ NACE CUMPLIDO ═══
 *
 * Igual que el de donaciones, y por la misma razón. Praderas del Sol ya
 * tiene lotes reservados —los dieciséis del bloque B, guardados para los
 * herederos— y hoy nadie declaró ese número en ningún lado. Cada proyecto
 * nace con el cupo IGUAL a lo que ya tiene reservado: el número queda
 * escrito por primera vez, dice la verdad, y para guardar uno más hay que
 * subirlo a mano, que es justamente la decisión que este cambio viene a
 * pedir. Un proyecto sin lotes reservados queda en cupo cero, que es lo
 * mismo que apagado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proyectos', function (Blueprint $table): void {
            $table->boolean('reserva_lotes')->default(false)->after('lotes_a_donar');
            $table->unsignedSmallInteger('lotes_a_reservar')->default(0)->after('reserva_lotes');
        });

        // El cupo de cada proyecto = lo que ya tiene guardado. Ver el
        // docblock: es lo unico que no le cambia la pantalla a Praderas.
        DB::statement(<<<SQL
            UPDATE proyectos
               SET lotes_a_reservar = COALESCE(guardados.cuantos, 0),
                   reserva_lotes    = COALESCE(guardados.cuantos, 0) > 0
              FROM (
                    SELECT proyecto_id, COUNT(*) AS cuantos
                      FROM lotes
                     WHERE estado = '{$this->reservado()}'
                     GROUP BY proyecto_id
                   ) AS guardados
             WHERE guardados.proyecto_id = proyectos.id
        SQL);
    }

    public function down(): void
    {
        Schema::table('proyectos', function (Blueprint $table): void {
            $table->dropColumn(['reserva_lotes', 'lotes_a_reservar']);
        });
    }

    /**
     * El valor del estado, leido del enum y no escrito a mano: si algun
     * dia cambia, esta migracion no puede quedar apuntando a un string
     * que ya no existe.
     */
    private function reservado(): string
    {
        return EstadoLote::Reservado->value;
    }
};
