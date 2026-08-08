<?php

declare(strict_types=1);

use App\Domain\Enums\FormaDePago;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tarjeta, la cuarta forma de pago (8-ago-2026).
 *
 * ═══ POR QUE HACE FALTA UNA MIGRACION PARA AGREGAR UN CASE ═══
 *
 * `recibos_forma_valida_chk` guarda la lista de formas DENTRO de la base. La
 * migración que creó `recibos` la arma con `FormaDePago::valores()`, así que
 * una instalación nueva ya nace con las cuatro; una base que ya migró, no. Sin
 * este ALTER, el primer cobro con tarjeta se cae con un CHECK violation que no
 * menciona ni el enum ni la forma nueva.
 *
 * ⚠️ **La próxima forma de pago necesita otra migración igual a esta.**
 * Agregar el `case` al enum NO alcanza. Es el precio de tener la lista también
 * en la base, y el precio vale: sin el CHECK, un `forma_pago` con basura entra
 * sin que nadie se entere hasta que alguien filtre por él.
 *
 * ═══ LO QUE ESTA MIGRACION NO HACE ═══
 *
 * No toca comisiones. Decisión de Mauricio del 8-ago-2026: el recibo se emite
 * por el monto entero y lo que el POS descuenta se arregla fuera del sistema.
 * Si algún día la comisión tiene que salir en el papel, es otra columna y otra
 * conversación con la contratante — R11 no contempla tarjeta.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->reescribirElCheck(FormaDePago::valores());
    }

    /**
     * Las tres de R11, que es lo que había antes.
     */
    public function down(): void
    {
        $this->reescribirElCheck(['efectivo', 'transferencia', 'deposito']);
    }

    /**
     * @param list<string> $formas
     */
    private function reescribirElCheck(array $formas): void
    {
        $lista = "'".implode("', '", $formas)."'";

        // IF EXISTS: en una base recién creada el CHECK ya nació con las cuatro
        // —la migración de `recibos` lo arma del mismo enum— y esto lo reescribe
        // idéntico. En una que ya migró, lo reemplaza.
        DB::statement('ALTER TABLE recibos DROP CONSTRAINT IF EXISTS recibos_forma_valida_chk');

        DB::statement(<<<SQL
            ALTER TABLE recibos
                ADD CONSTRAINT recibos_forma_valida_chk
                CHECK (forma_pago IN ({$lista}))
        SQL);
    }
};
