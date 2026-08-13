<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Quién vendió el lote.
 *
 * ═══ POR QUE UNA TABLA Y NO UN CAMPO DE TEXTO ═══
 *
 * Lo destapó la cartera vieja (11-ago-2026): el cuaderno anota «Vendido por»
 * en seis expedientes y escribe el mismo nombre de cuatro formas —«Jony Gerson
 * García Melgar», «Jony Gerson García», «Jony García» y «Yoni García»—. Con un
 * `string` en `ventas` eso queda así para siempre: cuatro vendedores donde hay
 * uno, y ninguna forma de sumar cuánto vendió cada quien.
 *
 * Que es justamente para lo que sirve el dato. Si nadie va a poder preguntar
 * «¿cuánto vendió fulano?», no vale la pena guardarlo.
 *
 * ═══ LO QUE ESTA TABLA NO HACE ═══
 *
 * ⚠️ **No calcula comisiones.** Deliberado. Antes de escribir una línea de eso
 * hay que saber: ¿la comisión es sobre el valor del contrato o sobre lo que se
 * cobra? ¿Se devenga al firmar o mes a mes? ¿Se revierte si el cliente se cae?
 * ¿La paga la lotificadora o sale del lote? Son preguntas para la contratante,
 * y contestarlas mal cuesta plata de verdad.
 *
 * La tabla queda lista para que la comisión se le cuelgue después sin migrar
 * un solo dato: hoy se registra QUIEN vendió, que es lo que hoy se pierde.
 *
 * ═══ VENDEDOR NULO NO ES UN HUECO ═══
 *
 * `ventas.vendedor_id` es nullable a propósito: la mayoría de los contratos
 * dice «Residencial Praderas del Sol», o sea que vendió la lotificadora
 * misma. Eso no es un vendedor, es la ausencia de uno. Inventarle una fila
 * «la casa» ensuciaría cualquier reporte de comisiones el día que exista.
 *
 * ═══ Y NO SE BORRA ═══
 *
 * `restrictOnDelete` y soft deletes: un vendedor con ventas a su nombre no
 * desaparece porque alguien lo borre de la lista. Se marca inactivo y deja de
 * aparecer al elegir, pero sus contratos siguen diciendo quién los cerró.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendedores', function (Blueprint $table): void {
            $table->id();

            $table->string('nombre', 150);
            $table->string('dni', 13)->nullable();
            $table->string('telefono', 8)->nullable();
            $table->boolean('activo')->default(true);
            $table->text('observaciones')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index('activo');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE vendedores
            ADD CONSTRAINT vendedores_nombre_no_vacio_chk
            CHECK (length(btrim(nombre)) > 0)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE vendedores
            ADD CONSTRAINT vendedores_dni_formato_chk
            CHECK (dni IS NULL OR dni ~ '^[0-9]{13}$')
        SQL);

        /*
         * El prefijo lleva los cinco dígitos que Honduras usa de verdad —2, 3,
         * 7, 8 y 9—, no los tres que tenía `clientes` hasta esta misma tarde.
         * Ver la migración de los teléfonos de Digicel y Hondutel.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE vendedores
            ADD CONSTRAINT vendedores_telefono_formato_chk
            CHECK (telefono IS NULL OR telefono ~ '^[23789][0-9]{7}$')
        SQL);

        /*
         * Un vendedor por nombre, sin importar mayúsculas ni espacios de más.
         * Es el índice el que impide que «Jony García» y «jony  garcia» entren
         * como dos personas — no la buena voluntad de quien carga.
         *
         * Parcial sobre `deleted_at IS NULL` para que un vendedor borrado no
         * bloquee el alta de otro con el mismo nombre, igual que en `clientes`.
         */
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX vendedores_nombre_unico
            ON vendedores (lower(btrim(nombre)))
            WHERE deleted_at IS NULL
        SQL);

        Schema::table('ventas', function (Blueprint $table): void {
            $table->foreignId('vendedor_id')
                ->nullable()
                ->after('proyecto_id')
                ->constrained('vendedores')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ventas', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('vendedor_id');
        });

        Schema::dropIfExists('vendedores');
    }
};
