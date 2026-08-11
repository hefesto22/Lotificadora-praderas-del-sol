<?php

declare(strict_types=1);

use App\Domain\Enums\FormaDePago;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Remesa, la quinta forma de pago (11-ago-2026).
 *
 * ═══ DE DONDE SALIO ═══
 *
 * De la cartera vieja. El exp. 0025 pagó su cuota de julio por **remesa** y el
 * sistema no la conocía: R11 contestó tres formas, Mauricio agregó tarjeta
 * pensando en las demás lotificadoras, y la remesa no estaba en ninguna lista.
 *
 * No es un caso raro. En el occidente de Honduras la remesa familiar es una de
 * las formas normales de pagar, y una lotificadora que le vende a gente con
 * parientes afuera la va a recibir seguido.
 *
 * ═══ POR QUE NO ES «DEPOSITO» ═══
 *
 * Porque el dinero no entra por el banco de la lotificadora: entra por una casa
 * de cambio o un corresponsal, y alguien tiene que ir a retirarlo. Anotarla
 * como depósito haría que el cuadre contra el estado de cuenta bancario **nunca
 * cierre**, y quien lo revise va a pasar horas buscando un movimiento que el
 * banco no tiene.
 *
 * ═══ ⚠️ SON CUATRO TABLAS, NO UNA ═══
 *
 * Cuando se agregó tarjeta solo existía `recibos`. Hoy la lista de formas está
 * congelada en el CHECK de **cuatro** tablas: `recibos`, `devoluciones`,
 * `gastos` y otra vez `recibos` por la migración de tarjeta. Olvidar una
 * significa que el primer egreso o la primera devolución por remesa rebota
 * contra la base, adentro de la transacción que la estaba registrando.
 *
 * La próxima forma de pago necesita otra migración igual a esta, y con las
 * mismas cuatro tablas. Es el precio de tener la lista también en la base, y
 * vale: sin el CHECK, un `forma_pago` con basura entra sin que nadie se entere
 * hasta que alguien filtre por él.
 */
return new class extends Migration
{
    /**
     * Las tablas que guardan una forma de pago, con el nombre de su CHECK.
     *
     * @var array<string, string>
     */
    private const array TABLAS = [
        'recibos'      => 'recibos_forma_valida_chk',
        'devoluciones' => 'devoluciones_forma_valida_chk',
        'gastos'       => 'gastos_forma_valida_chk',
    ];

    public function up(): void
    {
        $this->reescribir(FormaDePago::valores());
    }

    /**
     * Las cuatro de antes. Revertir falla si ya hay un movimiento por remesa,
     * y está bien que falle: primero hay que decidir qué se hace con él.
     */
    public function down(): void
    {
        $this->reescribir(['efectivo', 'transferencia', 'deposito', 'tarjeta']);
    }

    /**
     * @param list<string> $formas
     */
    private function reescribir(array $formas): void
    {
        $lista = "'".implode("', '", $formas)."'";

        foreach (self::TABLAS as $tabla => $constraint) {
            // IF EXISTS: en una base recién creada el CHECK ya nació con las
            // cinco —cada migración lo arma del mismo enum— y esto lo reescribe
            // idéntico. En una que ya migró, lo reemplaza.
            DB::statement("ALTER TABLE {$tabla} DROP CONSTRAINT IF EXISTS {$constraint}");

            DB::statement(<<<SQL
                ALTER TABLE {$tabla}
                    ADD CONSTRAINT {$constraint}
                    CHECK (forma_pago IN ({$lista}))
            SQL);
        }
    }
};
