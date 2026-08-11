<?php

declare(strict_types=1);

use App\Domain\Enums\CategoriaDeGasto;
use App\Domain\Enums\FormaDePago;
use App\Domain\Enums\TipoCorrelativo;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que el desarrollo CUESTA (11-ago-2026).
 *
 * ═══ POR QUE HACIA FALTA ═══
 *
 * Olympo sabia contar el dinero que entra: recibos, cuotas, estado de cuenta.
 * Del que sale sabia una sola cosa —la devolucion de una seña (R14)—, que es
 * un egreso chico y excepcional. Lo que un desarrollo gasta de verdad
 * —terraceria, calles, agua, el abogado, la planilla— no estaba en ningun
 * lado: vive en un cuaderno y en un folder de facturas.
 *
 * Sin eso el sistema contesta «cuanto he cobrado» y no contesta «cuanto me ha
 * costado», que es la mitad de la pregunta que un dueño se hace. La pidio
 * Mauricio el 11-ago-2026: registrar el gasto por proyecto, con su total y con
 * el motivo de en que se gasto.
 *
 * ═══ CUELGA DEL PROYECTO, Y NO DEL LOTE ═══
 *
 * Porque asi se gasta. La retroexcavadora no entra a un lote: abre la calle de
 * un bloque entero, y repartir ese costo entre los lotes que toca es un
 * prorrateo —una decision de contabilidad— no un dato que alguien tenga
 * enfrente al pagar la factura. El detalle escrito dice a que parte del
 * desarrollo fue; el dia que haga falta costo por lote, se calcula desde aca.
 *
 * `restrictOnDelete` sobre el proyecto por lo mismo que en `recibos`: borrar
 * un proyecto no puede llevarse en silencio la historia de lo que costo.
 *
 * ═══ CATEGORIA **Y** DETALLE, LAS DOS OBLIGATORIAS ═══
 *
 * La categoria sale de `CategoriaDeGasto` y es la que se puede sumar y
 * filtrar. El detalle es texto libre y tambien es obligatorio: sin el, dentro
 * de un año «Materiales — L 48,000» no le dice nada a nadie. Un CHECK obliga a
 * que tenga texto, igual que el motivo del descuento (R4) y el del abono (R21).
 *
 * ═══ EL COMPROBANTE VA AL DISCO PRIVADO ═══
 *
 * La foto de la factura puede traer datos del proveedor y montos que no son
 * publicos. Mismo tratamiento que `documentos` (el expediente del cliente):
 * disco privado, y se descarga por una accion que pasa por la politica. Es
 * OPCIONAL —hay gastos de campo sin papel— pero si viene, viene de verdad: el
 * CHECK impide guardar una ruta vacia, que seria una fila diciendo que hay un
 * comprobante que no existe.
 *
 * ═══ 🔴 LA TRAMPA DE `correlativos`, OTRA VEZ ═══
 *
 * Igual que en la migracion de `devoluciones`: los dos CHECKs de la tabla
 * `correlativos` tienen la lista de tipos CONGELADA en su propia migracion.
 * Agregar el caso `gasto` al enum no los toca, y en una instalacion que ya
 * migro el primer gasto reventaria contra la base. Se recrean aca.
 */
return new class extends Migration
{
    public function up(): void
    {
        $categorias = "'".implode("', '", CategoriaDeGasto::valores())."'";
        $formas = "'".implode("', '", FormaDePago::valores())."'";

        Schema::create('gastos', function (Blueprint $table): void {
            $table->id();

            // La serie propia: `G-000001`. Unico, que es para lo que existe.
            $table->unsignedBigInteger('numero')->unique();

            $table->foreignId('proyecto_id')->constrained('proyectos')->restrictOnDelete();

            $table->string('categoria', 20);
            $table->text('descripcion');

            // A quien se le pago. Opcional: hay gastos sin contraparte con
            // nombre (una tasa municipal, un peaje).
            $table->string('beneficiario', 120)->nullable();

            // El numero de la factura o el recibo QUE DIO EL PROVEEDOR. No se
            // confunde con `referencia`, que es la del banco: uno cruza contra
            // el folder de facturas y el otro contra el estado de cuenta.
            $table->string('factura', 60)->nullable();

            $table->decimal('monto', 14, 2);

            $table->string('forma_pago', 20);
            $table->string('referencia', 60)->nullable();

            $table->date('fecha');

            // El comprobante escaneado, en el disco privado.
            $table->string('archivo', 255)->nullable();
            $table->unsignedInteger('archivo_bytes')->default(0);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // La pestaña del proyecto ordena por fecha y filtra por categoria.
            $table->index(['proyecto_id', 'fecha']);
            $table->index(['proyecto_id', 'categoria']);

            // El corte de caja del dia busca los egresos en efectivo de hoy.
            $table->index(['fecha', 'forma_pago']);
        });

        DB::statement(<<<SQL
            ALTER TABLE gastos
                ADD CONSTRAINT gastos_categoria_valida_chk
                CHECK (categoria IN ({$categorias})),

                ADD CONSTRAINT gastos_forma_valida_chk
                CHECK (forma_pago IN ({$formas})),

                -- R11, igual que en recibos y devoluciones: en todo lo que no
                -- es efectivo la referencia es lo unico que despues permite
                -- cruzar el movimiento contra el banco.
                ADD CONSTRAINT gastos_referencia_segun_forma_chk
                CHECK (
                    forma_pago = 'efectivo'
                    OR (referencia IS NOT NULL AND btrim(referencia) <> '')
                ),

                -- Un gasto sin detalle es una fila que no explica nada. Lo
                -- hace cumplir la base, no el formulario.
                ADD CONSTRAINT gastos_descripcion_con_texto_chk
                CHECK (btrim(descripcion) <> ''),

                -- Un gasto de cero no es un gasto. Si se quiere dejar
                -- constancia de algo que no costo, va en el detalle de otro.
                ADD CONSTRAINT gastos_monto_positivo_chk
                CHECK (monto > 0),

                -- El adjunto es opcional; una ruta vacia no. Esa fila diria
                -- que hay un comprobante guardado y no habria nada que abrir.
                ADD CONSTRAINT gastos_archivo_no_vacio_chk
                CHECK (archivo IS NULL OR btrim(archivo) <> '')
        SQL);

        /*
         * Los CHECKs de `correlativos`, recreados con la lista nueva. Ver el
         * docblock de la clase y el de `TipoCorrelativo`.
         */
        $tipos = "'".implode("', '", TipoCorrelativo::valores())."'";
        $globales = "'".implode("', '", TipoCorrelativo::valoresGlobales())."'";
        $porProyecto = "'".implode("', '", TipoCorrelativo::valoresPorProyecto())."'";

        DB::statement(<<<SQL
            ALTER TABLE correlativos
                DROP CONSTRAINT IF EXISTS correlativos_tipo_valido_chk,
                DROP CONSTRAINT IF EXISTS correlativos_alcance_segun_tipo_chk,

                ADD CONSTRAINT correlativos_tipo_valido_chk
                CHECK (tipo IN ({$tipos})),

                ADD CONSTRAINT correlativos_alcance_segun_tipo_chk
                CHECK (
                    (tipo IN ({$porProyecto}) AND proyecto_id IS NOT NULL)
                    OR (tipo IN ({$globales}) AND proyecto_id IS NULL)
                )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('gastos');

        /*
         * Los CHECKs de `correlativos` NO se revierten, por lo mismo que
         * explico la migracion de `devoluciones`: si quedara una fila de la
         * serie de gastos, el CHECK viejo no la admitiria y el rollback se
         * caeria. Una lista mas amplia no rompe nada.
         */
    }
};
