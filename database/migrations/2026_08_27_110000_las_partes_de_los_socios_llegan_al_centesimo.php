<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Las partes de los socios llegan al centésimo — 27-ago-2026.
 *
 * Pedido de Mauricio: «que me permita como 66.67 y 33.33, que sea obligatorio
 * completar el 100%».
 *
 * El 13-ago la regla era «enteros o medios», y tenía su razón: con partes de
 * medio punto tres socios se acomodan en 33.5 + 33.5 + 33 y no hace falta un
 * tercio periódico. Pero eso resuelve el reparto de a TRES y traba el de a
 * DOS. Dos tercios y un tercio es 66.67 + 33.33; con medios no existe, y lo
 * más cerca —66.5 + 33.5— es OTRO reparto: le mueve medio punto de todo lo que
 * el proyecto produzca a la persona equivocada, para siempre.
 *
 * Así que la columna pasa a numeric(5,2) y el CHECK de enteros o medios se va.
 *
 * ⚠️ Lo que NO se afloja es que las partes sumen 100. Eso no lo puede mirar un
 * CHECK —mira UNA fila y esto es la suma de todas— y lo sigue exigiendo el
 * formulario, que es donde se cargan.
 *
 * ⚠️ Los centésimos tampoco cierran de a tres: 33.33 × 3 = 99.99. Se acomodan
 * igual que los centavos de cualquier reparto —33.34 + 33.33 + 33.33— y por eso
 * el aviso de la pantalla dice cuánto falta con el número puesto.
 *
 * 🟢 No hay nada que rellenar: ampliar la escala no toca un solo dato. Lo que
 * hoy vale 50.0 pasa a valer 50.00, que es el mismo número.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Primero el CHECK: si no, Postgres lo revalida contra el tipo nuevo.
        DB::statement('ALTER TABLE socios DROP CONSTRAINT IF EXISTS socios_porcentaje_entero_o_medio_chk');

        DB::statement('ALTER TABLE socios ALTER COLUMN porcentaje TYPE numeric(5,2)');
    }

    /**
     * ⚠️ **Falla si mientras tanto alguien cargó una parte con centésimos**, y
     * es correcto que falle: volver a numeric(5,1) las redondearía en silencio
     * —66.67 pasaría a 66.7— y eso le mueve dinero a alguien. Hay que
     * reacomodar el reparto a mano primero.
     */
    public function down(): void
    {
        $conCentesimos = DB::table('socios')
            ->whereRaw('porcentaje * 2 <> trunc(porcentaje * 2)')
            ->count();

        if ($conCentesimos > 0) {
            throw new RuntimeException(sprintf(
                'Hay %d socio(s) con una parte que no es entero ni medio. Volver atrás las '.
                'redondearía y le movería dinero a alguien: reacomodá el reparto primero.',
                $conCentesimos,
            ));
        }

        DB::statement('ALTER TABLE socios ALTER COLUMN porcentaje TYPE numeric(5,1)');

        DB::statement(<<<'SQL'
            ALTER TABLE socios
                ADD CONSTRAINT socios_porcentaje_entero_o_medio_chk
                CHECK (porcentaje * 2 = trunc(porcentaje * 2))
        SQL);
    }
};
