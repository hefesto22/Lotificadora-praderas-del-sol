<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Los duenos del expediente (R8).
 *
 * La contratante confirmo que un lote puede tener dos duenos: marido y
 * mujer o socios aparecen los dos en el contrato. Por eso la venta no
 * tiene `cliente_id`, tiene clientes.
 *
 * ═══ UN SOLO TITULAR, GARANTIZADO POR LA BASE ═══
 *
 * El indice unico PARCIAL sobre `venta_id WHERE titular` hace imposible que
 * una venta termine con dos titulares. Es el mismo truco del indice de
 * `compromisos`, y por la misma razon: una validacion de formulario no
 * sobrevive a dos pestanas abiertas ni a un import.
 *
 * Lo que la base NO puede exigir es que haya AL MENOS un titular —una
 * restriccion sobre "existe alguna fila" no cabe en un CHECK—. Eso lo
 * impone el Service al activar la venta, y tiene su test.
 *
 * ═══ QUE PUEDE HACER CADA UNO ═══
 *
 * Mientras la contratante no diga otra cosa (quedo como pregunta abierta en
 * `docs/dominio.md`), el criterio es el mas conservador: **cualquiera de los
 * dos puede pagar**, el recibo sale a nombre de quien paga, y el estado de
 * cuenta sale a nombre del titular con los demas listados. Cambiarlo es una
 * regla de presentacion, no una migracion.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venta_cliente', function (Blueprint $table): void {
            $table->id();

            // Cascade: si una venta se elimina de verdad —solo pasa en
            // tests, porque el negocio las anula— sus duenos se van con
            // ella. La fila del pivot no significa nada sin la venta.
            $table->foreignId('venta_id')->constrained('ventas')->cascadeOnDelete();

            // Restrict: un cliente con expedientes a su nombre no se borra.
            $table->foreignId('cliente_id')->constrained('clientes')->restrictOnDelete();

            $table->boolean('titular')->default(false);

            // El orden en que van impresos en el contrato.
            $table->unsignedSmallInteger('orden')->default(1);

            $table->timestamps();

            $table->unique(['venta_id', 'cliente_id']);
            $table->index('cliente_id');
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX venta_cliente_un_titular_uq
                ON venta_cliente (venta_id)
                WHERE titular
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE venta_cliente
                ADD CONSTRAINT venta_cliente_orden_positivo_chk
                CHECK (orden > 0)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('venta_cliente');
    }
};
