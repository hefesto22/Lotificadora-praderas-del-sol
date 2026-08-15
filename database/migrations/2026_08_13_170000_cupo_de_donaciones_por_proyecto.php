<?php

declare(strict_types=1);

use App\Domain\Enums\EstadoLote;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cuántos lotes se van a donar en este desarrollo, y si se dona del todo.
 *
 * Lo pidió Mauricio el 13-ago-2026: «al crear el proyecto que esté la
 * opción de donaciones, y si está activo marque cuántos lotes se donarán;
 * que el botón donar aparezca hasta que esos lotes se hayan donado».
 *
 * ═══ POR QUÉ UN CUPO Y NO UN PERMISO ═══
 *
 * Donar saca un lote del inventario sin que entre un lempira, y es el
 * único compromiso que no deja rastro de plata. Un permiso de sí/no deja
 * la puerta abierta para siempre; un cupo la cierra sola cuando se cumplió
 * lo que la lotificadora decidió regalar. El día que alguien done el lote
 * de más, el número tiene que haber sido una decisión escrita antes, no
 * un clic.
 *
 * ═══ EL RELLENO: POR QUÉ NACE CUMPLIDO ═══
 *
 * Praderas del Sol YA tiene lotes donados —vienen de la cartera anterior
 * al sistema— y hoy el botón está siempre disponible. Si esta migración
 * dejara las donaciones apagadas, el botón le desaparecería de un día para
 * otro a un proyecto en producción.
 *
 * Por eso cada proyecto nace con `dona_lotes = true` y el cupo IGUAL a los
 * lotes que ya tiene donados: el botón queda oculto porque el cupo está
 * lleno —que es la verdad— y para donar uno más hay que subir el número a
 * mano, que es exactamente la decisión que este cambio viene a pedir. Un
 * proyecto sin donaciones queda en cupo cero, que es lo mismo que apagado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proyectos', function (Blueprint $table): void {
            $table->boolean('dona_lotes')->default(false)->after('unidad_area');
            $table->unsignedSmallInteger('lotes_a_donar')->default(0)->after('dona_lotes');
        });

        // El cupo de cada proyecto = lo que ya tiene donado. Ver el
        // docblock: es lo unico que no le cambia la pantalla a Praderas.
        DB::statement(<<<SQL
            UPDATE proyectos
               SET lotes_a_donar = COALESCE(donados.cuantos, 0),
                   dona_lotes    = COALESCE(donados.cuantos, 0) > 0
              FROM (
                    SELECT proyecto_id, COUNT(*) AS cuantos
                      FROM lotes
                     WHERE estado = '{$this->donado()}'
                     GROUP BY proyecto_id
                   ) AS donados
             WHERE donados.proyecto_id = proyectos.id
        SQL);
    }

    public function down(): void
    {
        Schema::table('proyectos', function (Blueprint $table): void {
            $table->dropColumn(['dona_lotes', 'lotes_a_donar']);
        });
    }

    /**
     * El valor del estado, leido del enum y no escrito a mano: si algun
     * dia cambia, esta migracion no puede quedar apuntando a un string
     * que ya no existe.
     */
    private function donado(): string
    {
        return EstadoLote::Donado->value;
    }
};
