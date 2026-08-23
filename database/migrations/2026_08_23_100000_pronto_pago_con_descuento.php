<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El pronto pago: saldar un lote con un descuento — 23-ago-2026.
 *
 * ═══ QUE PIDIO MAURICIO, TEXTUAL ═══
 *
 * «Digamos tiene 1, 2 o más lotes y quiere pagar el restante de uno y solo ha
 * dado una cuota y quiere pagar todo el lote 2 pero pide un descuento: se le
 * coloca cuánto se le dio de descuento en ese lote y que pague el resto, y ya
 * quedaría pagado. Esto es algo que sucede en casos reales.»
 *
 * Sin tope —quién decide cuánto descontar es la lotificadora, no el sistema—
 * pero con motivo obligatorio, igual que el descuento al vender (R4).
 *
 * ═══ 🔴 POR QUE EL DESCUENTO ENTRA EN `monto_pagado` ═══
 *
 * La forma obvia sería una columna `capital_condonado` que se RESTA aparte,
 * como se hizo con `mora_condonada`. **Y sería un error grave acá**, por una
 * razón que solo se ve buscando: en este repo hay CATORCE lugares que
 * calculan lo que falta pagar con SQL crudo —`SUM(monto - monto_pagado)` o
 * `monto_pagado < monto`—. Están en el saldo del expediente, la columna
 * «Saldo» de la tabla de ventas, el contador de vencidos del menú, el
 * Escritorio, el saldo del cliente, el plano y el modal de cobro.
 *
 * Con el descuento por fuera, esos catorce seguirían diciendo que el lote
 * debe. El expediente mostraría «saldo L 24,383.33» sobre un lote que la
 * lotificadora dio por saldado, el menú lo contaría como atrasado y el
 * Escritorio lo sumaría a la cartera. No revienta nada: **miente en catorce
 * pantallas a la vez**, que es peor.
 *
 * Por eso `monto_pagado` significa **lo que resuelve la cuota** —dinero más
 * perdón—, y `capital_condonado` dice qué PARTE de eso no fue dinero. Los
 * catorce lugares siguen siendo correctos sin tocar una línea, y el CHECK de
 * abajo garantiza que lo condonado nunca exceda lo resuelto.
 *
 * La mora es distinta y por eso se guardó distinto: `mora_pagada` y
 * `mora_condonada` viven FUERA de `monto`, así que ningún SQL las calcula
 * como resta. No hay contradicción entre las dos decisiones — hay dos ejes.
 *
 * ═══ LA CAJA NO SE TOCA ═══
 *
 * `recibos.monto` sigue siendo, exactamente, lo que el cliente entregó. El
 * descuento no pasa por ahí ni por el corte de caja: se perdona, no se cobra.
 * Se lee sumando `capital_condonado` de las aplicaciones del recibo — un dato
 * derivado que no se puede desincronizar, en vez de una columna más.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cuotas', function (Blueprint $table): void {
            $table->decimal('capital_condonado', 14, 2)->default(0)->after('monto_pagado');
        });

        Schema::table('aplicaciones_de_pago', function (Blueprint $table): void {
            $table->decimal('capital_condonado', 14, 2)->default(0)->after('mora_condonada');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE cuotas
                ADD CONSTRAINT cuotas_capital_condonado_no_negativo_chk
                CHECK (capital_condonado >= 0),

                -- La invariante que sostiene todo lo de arriba: lo condonado
                -- es una PARTE de lo pagado, nunca algo aparte. Si alguien
                -- vuelve a la idea de restarlo por fuera, este CHECK se cae
                -- antes que las pantallas.
                ADD CONSTRAINT cuotas_condonado_cabe_en_lo_pagado_chk
                CHECK (capital_condonado <= monto_pagado)
        SQL);

        /*
         * El renglón de un pronto pago puede ser SOLO perdón: la última cuota
         * de un lote donde el dinero del cliente ya se agotó. Sin esto, el
         * CHECK viejo lo rechazaría y no habría dónde anotar ese perdón — que
         * es lo único que le permitiría a `anular()` deshacerlo algún día.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE aplicaciones_de_pago
                DROP CONSTRAINT IF EXISTS aplicaciones_monto_positivo_chk,

                ADD CONSTRAINT aplicaciones_capital_condonado_no_negativo_chk
                CHECK (capital_condonado >= 0),

                ADD CONSTRAINT aplicaciones_algo_que_anotar_chk
                CHECK (monto > 0 OR capital_condonado > 0)
        SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE aplicaciones_de_pago
                DROP CONSTRAINT IF EXISTS aplicaciones_algo_que_anotar_chk,
                DROP CONSTRAINT IF EXISTS aplicaciones_capital_condonado_no_negativo_chk,

                ADD CONSTRAINT aplicaciones_monto_positivo_chk
                CHECK (monto > 0)
        SQL);

        Schema::table('aplicaciones_de_pago', function (Blueprint $table): void {
            $table->dropColumn('capital_condonado');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE cuotas
                DROP CONSTRAINT IF EXISTS cuotas_capital_condonado_no_negativo_chk,
                DROP CONSTRAINT IF EXISTS cuotas_condonado_cabe_en_lo_pagado_chk
        SQL);

        Schema::table('cuotas', function (Blueprint $table): void {
            $table->dropColumn('capital_condonado');
        });
    }
};
