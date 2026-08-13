<?php

declare(strict_types=1);

use App\Domain\Enums\EstadoLote;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El quinto estado de un lote: RESERVADO.
 *
 * ═══ QUE LO PIDIO ═══
 *
 * El cuaderno de la cartera vieja. El exp. 0080 dice «Herederos — Bloque B
 * lotes 1 al 16» y nada más: sin fecha, sin valor, sin pagos. Esos dieciséis
 * lotes no están a la venta, pero como no estaban apartados ni vendidos ni
 * cancelados, figuraban como DISPONIBLES — y el plano público los estaba
 * ofreciendo. El riesgo no es teórico: es venderle a alguien un lote que ya
 * tiene destino.
 *
 * Autorizado por Mauricio el 12-ago-2026. El enum lo pide por escrito porque
 * el estado del lote sale en los reportes con los que la contratante decide.
 *
 * ═══ POR QUE NO ALCANZABA CON LOS CUATRO QUE HABIA ═══
 *
 * `Apartado` es el de R14: lleva seña, vencimiento y prórroga. Meter ahí a los
 * herederos sería inventar un apartado que nadie firmó y que vence en quince
 * días. `Cancelado` es algo que se cayó — un apartado vencido, una venta
 * rescindida—, y esto no se cayó: nunca estuvo a la venta.
 *
 * ═══ EL CHECK SE REESCRIBE ENTERO ═══
 *
 * `lotes_estado_valido_chk` congela la lista de estados en la base, y esa es
 * la razón de que exista: que un seeder, un import o un tinker no puedan meter
 * un estado que el código no conoce. Agregar un caso al enum NO lo actualiza
 * solo — hay que soltarlo y volverlo a crear con la lista nueva, que es lo que
 * hace esta migración.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->reescribirElCheck(EstadoLote::valores());
    }

    public function down(): void
    {
        /*
         * Antes de volver a los cuatro estados hay que sacar de en medio a los
         * lotes que estén reservados, o el CHECK viejo no se puede crear. Van a
         * `disponible`, que es de donde salieron.
         */
        DB::table('lotes')
            ->where('estado', EstadoLote::Reservado->value)
            ->update(['estado' => EstadoLote::Disponible->value, 'updated_at' => now()]);

        $this->reescribirElCheck(['disponible', 'apartado', 'vendido', 'cancelado']);
    }

    /**
     * @param list<string> $estados
     */
    private function reescribirElCheck(array $estados): void
    {
        $lista = implode(', ', array_map(
            static fn (string $estado): string => "'{$estado}'",
            $estados,
        ));

        DB::statement('ALTER TABLE lotes DROP CONSTRAINT IF EXISTS lotes_estado_valido_chk');

        DB::statement(
            'ALTER TABLE lotes ADD CONSTRAINT lotes_estado_valido_chk CHECK (estado IN ('.$lista.'))'
        );
    }
};
