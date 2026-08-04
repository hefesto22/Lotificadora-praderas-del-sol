<?php

declare(strict_types=1);

use App\Domain\Enums\EstadoVenta;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Modulos c) y d) del contrato: la venta, que es tambien el expediente.
 *
 * ═══ EL CORRELATIVO NO SE QUEMA HASTA QUE LA VENTA ES REAL ═══
 *
 * R5, contestada por la contratante: la prima se paga COMPLETA y ahi se
 * firma el contrato. No existe la venta a medias. Por eso `numero_contrato`,
 * `numero_expediente` y `fecha_contrato` son NULOS mientras la venta esta en
 * borrador, y obligatorios en cuanto deja de estarlo — con un CHECK que lo
 * impone en los dos sentidos.
 *
 * Un correlativo consumido por una venta que no se concreto es un hueco en
 * la serie que despues hay que explicarle a alguien.
 *
 * ═══ EL SALDO NO PUEDE MENTIR ═══
 *
 * `saldo_financiar = valor_total − prima` es un CHECK, no una costumbre.
 * Es exacto porque NUMERIC es exacto (§8.3.1), y es la columna de la que
 * cuelga todo el plan de cuotas. Si alguna vez esas tres columnas dejan de
 * cuadrar, la base lo rechaza antes de que el estado de cuenta lo muestre.
 *
 * ═══ LO QUE NO LLEVA, Y POR QUE ═══
 *
 * - **`cliente_id` singular no existe.** Un lote puede tener dos duenos
 *   (R8): marido y mujer o socios van los dos en el contrato. Los clientes
 *   cuelgan del pivot `venta_cliente`, con uno marcado titular.
 * - **`venta_lote` tampoco existe.** Los lotes de la venta son sus
 *   `compromisos` de tipo venta, que ya congelan area, precio y valor.
 *   Dos tablas congelando el mismo dinero terminan discrepando, y el dia
 *   que discrepen nadie va a saber cual manda.
 * - **`vendedor_id` no esta.** Comisiones nunca se preguntaron y no
 *   figuran en la Clausula Segunda; construirlo seria alcance regalado.
 * - **Sin soft deletes** (§12): una venta no se borra. Se anula desde
 *   borrador, o se rescinde, y las dos cosas son historia consultable.
 * - **Sin columna de mora ni de interes**: R1 y R2. Que no exista la
 *   columna es la forma mas barata de garantizar que nadie la calcule.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ventas', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('proyecto_id')->constrained('proyectos')->restrictOnDelete();

            // Nulos mientras es borrador. El expediente es el secuencial
            // pelado; el contrato es ese mismo secuencial con el codigo del
            // proyecto y el anio adelante (R7).
            $table->unsignedInteger('numero_expediente')->nullable();
            $table->string('numero_contrato', 30)->nullable();
            $table->date('fecha_contrato')->nullable();

            $table->string('estado', 20)->default(EstadoVenta::Borrador->value);

            // Suma de las areas y los valores CONGELADOS de los compromisos
            // de la venta. Es derivada y almacenada: se recalcula dentro de
            // la misma transaccion y hay un test que la reconstruye desde
            // cero y compara al centimo (§8.3.4).
            $table->decimal('area_total', 12, 4)->default(0);
            $table->decimal('valor_total', 14, 2)->default(0);

            $table->decimal('prima', 14, 2)->default(0);
            $table->decimal('saldo_financiar', 14, 2)->default(0);

            // Nula en una venta de contado: sin saldo no hay cuota.
            $table->decimal('cuota_mensual', 14, 2)->nullable();
            $table->unsignedSmallInteger('plazo_meses')->default(0);

            // Sin default en la base a proposito: el default del negocio
            // vive en config/lotificadora.php, y un default de Postgres no
            // llega al modelo en memoria tras create() (§9.C6).
            $table->unsignedSmallInteger('dia_pago');

            $table->text('observaciones')->nullable();

            // Cierre: liquidada, rescindida o anulada.
            $table->date('cerrada_el')->nullable();
            $table->text('motivo')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['proyecto_id', 'estado']);
            $table->index('fecha_contrato');
        });

        /*
         * Unicos PARCIALES sobre columnas nulas. En Postgres NULL ≠ NULL,
         * asi que los borradores —todos con numero nulo— no se estorban
         * entre si; el `WHERE … IS NOT NULL` lo deja explicito en vez de
         * depender de que quien lea sepa esa regla (§9.D8).
         */
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX ventas_numero_contrato_uq
                ON ventas (numero_contrato)
                WHERE numero_contrato IS NOT NULL
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX ventas_expediente_por_proyecto_uq
                ON ventas (proyecto_id, numero_expediente)
                WHERE numero_expediente IS NOT NULL
        SQL);

        $estados = self::comoLista(EstadoVenta::valores());
        $conNumero = self::comoLista(EstadoVenta::valoresConNumero());
        $cerrados = self::comoLista(EstadoVenta::valoresCerrados());

        DB::statement(<<<SQL
            ALTER TABLE ventas
                ADD CONSTRAINT ventas_estado_valido_chk CHECK (estado IN ({$estados}))
        SQL);

        // R5 en la base: el borrador NO tiene numero, y la venta que dejo
        // de ser borrador NO puede no tenerlo.
        DB::statement(<<<SQL
            ALTER TABLE ventas
                ADD CONSTRAINT ventas_numeracion_segun_estado_chk
                CHECK (
                    (
                        estado = 'borrador'
                        AND numero_contrato IS NULL
                        AND numero_expediente IS NULL
                        AND fecha_contrato IS NULL
                    )
                    OR (
                        estado IN ({$conNumero})
                        AND numero_contrato IS NOT NULL
                        AND numero_expediente IS NOT NULL
                        AND fecha_contrato IS NOT NULL
                    )
                )
        SQL);

        DB::statement(<<<SQL
            ALTER TABLE ventas
                ADD CONSTRAINT ventas_cierre_segun_estado_chk
                CHECK (
                    (estado IN ({$cerrados}) AND cerrada_el IS NOT NULL)
                    OR (estado NOT IN ({$cerrados}) AND cerrada_el IS NULL)
                )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE ventas
                ADD CONSTRAINT ventas_montos_no_negativos_chk
                CHECK (
                    area_total >= 0
                    AND valor_total >= 0
                    AND prima >= 0
                    AND saldo_financiar >= 0
                    AND (cuota_mensual IS NULL OR cuota_mensual > 0)
                ),
                ADD CONSTRAINT ventas_prima_no_supera_el_valor_chk
                CHECK (prima <= valor_total)
        SQL);

        // La igualdad que sostiene todo el plan de cuotas.
        DB::statement(<<<'SQL'
            ALTER TABLE ventas
                ADD CONSTRAINT ventas_saldo_cuadra_chk
                CHECK (saldo_financiar = valor_total - prima)
        SQL);

        // Sin plazo no hay cuota, y con plazo tiene que haberla. Una venta
        // de contado va con plazo 0 y cuota nula.
        DB::statement(<<<'SQL'
            ALTER TABLE ventas
                ADD CONSTRAINT ventas_cuota_segun_plazo_chk
                CHECK (
                    (plazo_meses = 0 AND cuota_mensual IS NULL)
                    OR (plazo_meses > 0 AND cuota_mensual IS NOT NULL)
                ),
                ADD CONSTRAINT ventas_plazo_razonable_chk
                CHECK (plazo_meses <= 600),
                ADD CONSTRAINT ventas_dia_pago_valido_chk
                CHECK (dia_pago BETWEEN 1 AND 31)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE ventas
                ADD CONSTRAINT ventas_cierre_posterior_al_contrato_chk
                CHECK (cerrada_el IS NULL OR fecha_contrato IS NULL OR cerrada_el >= fecha_contrato),
                ADD CONSTRAINT ventas_expediente_positivo_chk
                CHECK (numero_expediente IS NULL OR numero_expediente > 0)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('ventas');
    }

    /**
     * @param list<string> $valores
     */
    private static function comoLista(array $valores): string
    {
        return implode(', ', array_map(static fn (string $v): string => "'{$v}'", $valores));
    }
};
