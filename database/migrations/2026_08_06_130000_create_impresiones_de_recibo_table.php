<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cada vez que un recibo salio impreso.
 *
 * ═══ POR QUE HACE FALTA GUARDARLO ═══
 *
 * Un recibo NO se edita: se anula y se emite otro (R12). Pero reimprimirlo es
 * otra cosa, y es legitima —se moja, se pierde, el cliente vuelve a pedirlo—.
 * El problema es que dos papeles con el mismo numero pueden hacerse pasar por
 * dos cobros distintos, que es exactamente lo que un correlativo viene a
 * evitar. Por eso el original sale limpio, la segunda vez en adelante el papel
 * dice COPIA, y de las dos queda quien la imprimio y cuando.
 *
 * ═══ POR QUE UNA TABLA Y NO DOS COLUMNAS EN `recibos` ═══
 *
 * Un par de columnas (`veces_impreso`, `ultima_impresion`) contesta «se
 * imprimio 3 veces, la ultima Rosa el 8-ago». Esta tabla contesta la pregunta
 * que de verdad aparece: «el original lo imprimio don Elder el 6-ago a las
 * 10:12, y la copia la imprimio Rosa el 8-ago a las 15:40». Cuando hay dos
 * papeles sobre un mostrador, la historia completa es el desempate.
 *
 * Ademas `recibos` no se toca: un documento entregado que cambia de fila cada
 * vez que alguien lo mira es un documento del que no se puede decir nada.
 *
 * ═══ EL UNICO DE VERDAD ═══
 *
 * `(recibo_id, numero_de_impresion)` es unico, asi que dos filas no pueden
 * decir las dos que son el original. El Service ademas bloquea el recibo
 * dentro de la transaccion: sin eso, dos personas imprimiendo al mismo tiempo
 * leerian «0 impresiones» y las dos se creerian la primera.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('impresiones_de_recibo', function (Blueprint $table): void {
            $table->id();

            // Cascade: el historial de impresion no existe sin su recibo. Y un
            // recibo no se borra —`ReciboPolicy` lo niega y `recibos` cuelga de
            // la venta con restrictOnDelete—, asi que en la practica nunca pasa.
            $table->foreignId('recibo_id')->constrained('recibos')->cascadeOnDelete();

            // 1 es el original. De 2 en adelante el papel dice COPIA.
            $table->unsignedSmallInteger('numero_de_impresion');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['recibo_id', 'numero_de_impresion']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE impresiones_de_recibo
                ADD CONSTRAINT impresiones_numero_positivo_chk
                CHECK (numero_de_impresion > 0)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('impresiones_de_recibo');
    }
};
