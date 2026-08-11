<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El precio por vara² pasa de 2 a 6 decimales (11-ago-2026).
 *
 * ═══ POR QUE, CON LOS NUMEROS DE PRADERAS ═══
 *
 * Al cruzar la cartera vieja contra el plano quedó claro que **la lotificadora
 * no cobra por vara²: cobra un precio por LOTE**, redondeado a una cifra
 * vendible. El lote A-1 lo dejó sin discusión: mide 252 vr² —dos más que los
 * normales— y se cobró exactamente lo mismo, L 250,000.00.
 *
 * El sistema modela el precio por vara² y deriva el valor multiplicando por el
 * área. Con dos decimales, el precio que se cobra de verdad **no se puede
 * escribir**:
 *
 *   área        precio real     2 dec           4 dec           6 dec
 *   250.0000    250,000.00      250,000.00 ✓    250,000.00 ✓    250,000.00 ✓
 *   252.0000    250,000.00      249,999.12 ✗    250,000.00 ✓    250,000.00 ✓
 *   337.5000    325,000.00      324,999.00 ✗    325,000.01 ✗    325,000.00 ✓
 *
 * Con cuatro no alcanza —los nueve lotes de 337.50 vr² del bloque H quedan un
 * centavo arriba—. Con seis cierran los tres, y por eso son seis.
 *
 * ═══ LO QUE **NO** CAMBIA ═══
 *
 * - **El dinero sigue con dos decimales.** `lotes.valor`, `compromisos.valor`,
 *   `ventas.valor_total`, `recibos.monto` y las cuotas no se tocan: un lempira
 *   tiene dos decimales y punto. Lo que gana precisión es el FACTOR con el que
 *   se calcula, no el resultado.
 * - **La pantalla.** Nadie va a leer «L 962.962963»: lo que se ve en el plano y
 *   en el contrato es el valor del lote, L 325,000.00, que es lo que la señora
 *   cobra. El precio por vara² es aritmética interna.
 * - **`Monto`.** Ya trabaja con escala 12 y redondea una sola vez al exponer
 *   (§8.3.1). No hace falta tocar una línea del motor.
 *
 * ═══ POR QUE `ALTER COLUMN ... TYPE` Y NO `->change()` ═══
 *
 * `change()` de Laravel reconstruye la definición entera de la columna, y lo
 * que no se le repita —el `nullable` de `precio_vara_lista`, por ejemplo— se
 * pierde en silencio. El ALTER de Postgres toca el tipo y nada más.
 *
 * Ampliar de 2 a 6 decimales no pierde datos: los valores existentes se
 * rellenan con ceros a la derecha.
 */
return new class extends Migration
{
    /**
     * Las cuatro columnas que llevan un PRECIO POR VARA², que es un factor.
     * Ninguna de ellas guarda dinero final.
     *
     * @var list<array{tabla: string, columna: string}>
     */
    private const array COLUMNAS = [
        ['tabla' => 'lotes',           'columna' => 'precio_vara'],
        ['tabla' => 'compromisos',     'columna' => 'precio_vara'],
        ['tabla' => 'compromisos',     'columna' => 'precio_vara_lista'],
        ['tabla' => 'planes_de_pago',  'columna' => 'precio_vara'],
    ];

    public function up(): void
    {
        $this->escalar(6);
    }

    /**
     * Volver a dos decimales TRUNCA: un precio de 962.962963 se convierte en
     * 962.96 y el valor del lote cambia. Se puede revertir solo mientras
     * ningún precio use los decimales nuevos.
     */
    public function down(): void
    {
        $this->escalar(2);
    }

    private function escalar(int $decimales): void
    {
        foreach (self::COLUMNAS as $columna) {
            DB::statement(sprintf(
                'ALTER TABLE %s ALTER COLUMN %s TYPE numeric(14, %d)',
                $columna['tabla'],
                $columna['columna'],
                $decimales,
            ));
        }
    }
};
