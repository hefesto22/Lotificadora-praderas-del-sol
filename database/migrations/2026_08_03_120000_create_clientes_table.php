<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Módulo a) del contrato: clientes.
 *
 * `dni` y `rtn` se guardan LIMPIOS, solo dígitos. El formato visual
 * (0801-1985-01234) se arma al mostrar. Guardar los guiones dejaría entrar
 * dos veces a la misma persona —una con y otra sin— porque para el índice
 * único son cadenas distintas.
 *
 * Soft deletes SÍ (§12): un cliente con historial de pagos no se borra. Y
 * por eso los índices únicos son PARCIALES sobre tres condiciones, no dos:
 * `IS NOT NULL` porque el DNI es opcional —al apartar a veces solo se tiene
 * el nombre y un teléfono— y `deleted_at IS NULL` porque si no, un cliente
 * borrado sigue ocupando su DNI para siempre y nadie puede volver a darlo de
 * alta, con un mensaje de error que no explica nada porque el registro que
 * estorba es invisible.
 *
 * Los CHECK de formato son el cinturón: la validación de Filament y el
 * mutator del modelo son los tirantes. Un import, un seeder o un tinker no
 * pasan por ninguno de los dos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clientes', function (Blueprint $table): void {
            $table->id();

            $table->string('nombre', 150);
            $table->string('dni', 13)->nullable();
            $table->string('rtn', 14)->nullable();
            $table->string('telefono', 8)->nullable();
            $table->string('correo', 150)->nullable();
            $table->text('direccion')->nullable();
            $table->boolean('activo')->default(true);
            $table->text('observaciones')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index('activo');
        });

        // §9.A.9: la búsqueda de tablas en Postgres envuelve la columna en
        // lower(). Sin índice funcional, buscar un cliente entre miles es un
        // seq scan en cada tecla que escribe doña Marina.
        DB::statement('CREATE INDEX clientes_nombre_lower_idx ON clientes (lower(nombre))');

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX clientes_dni_unico
                ON clientes (dni)
                WHERE dni IS NOT NULL AND deleted_at IS NULL
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX clientes_rtn_unico
                ON clientes (rtn)
                WHERE rtn IS NOT NULL AND deleted_at IS NULL
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE clientes
                ADD CONSTRAINT clientes_dni_formato_chk
                CHECK (dni IS NULL OR dni ~ '^[0-9]{13}$')
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE clientes
                ADD CONSTRAINT clientes_rtn_formato_chk
                CHECK (rtn IS NULL OR rtn ~ '^[0-9]{14}$')
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE clientes
                ADD CONSTRAINT clientes_telefono_formato_chk
                CHECK (telefono IS NULL OR telefono ~ '^[239][0-9]{7}$')
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE clientes
                ADD CONSTRAINT clientes_nombre_no_vacio_chk
                CHECK (length(btrim(nombre)) > 0)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('clientes');
    }
};
