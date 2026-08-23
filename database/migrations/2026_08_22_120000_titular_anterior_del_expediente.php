<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El expediente cambia de dueño: quién lo era antes, y desde cuándo no.
 *
 * ═══ EL CASO ═══
 *
 * Mauricio, 22-ago-2026: «se hizo la promesa de venta, pero después quieren
 * cambiar la persona titular; el registro de los pagos queda y solo se
 * cambia el nombre del cliente, y que quede registro de que se cambió ese
 * nombre y la fecha».
 *
 * En lo legal eso es una cesión de derechos. En la base es una sola cosa:
 * la marca de titular pasa de una fila del expediente a otra.
 *
 * ═══ POR QUE UNA FECHA Y NO UN BORRADO ═══
 *
 * Porque el que sale **se queda listado** (decisión de Mauricio, 22-ago).
 * Si se borrara su fila, el expediente desaparecería de su ficha de cliente
 * de un día para otro —y sus recibos, que siguen apuntándolo por
 * `recibos.cliente_id`, quedarían colgando de una venta en la que ya no
 * figura—. Con la fecha, la ficha cuenta la historia sola: «fue titular
 * hasta el 22/08/2026».
 *
 * Es la misma idea de `compromisos.cerrada_el`: el estado se lee de la
 * fecha, no de un booleano aparte que alguien puede dejar desincronizado.
 *
 * ═══ LOS DOS INDICES QUE SE COMPLEMENTAN ═══
 *
 * `venta_cliente_un_titular_uq` (la migración original) ya impide que haya
 * dos titulares a la vez. Este CHECK cierra el otro lado: **el titular de
 * hoy no puede tener fecha de salida**. Sin él, un cambio hecho en el orden
 * equivocado dejaría una fila que dice «es titular y dejó de serlo», y
 * ninguna pantalla sabría cuál de las dos cosas creerle.
 *
 * Ojo al escribir: los dos índices juntos obligan a APAGAR la marca vieja
 * antes de prender la nueva, en la misma transacción. Postgres valida por
 * fila, no al final.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venta_cliente', function (Blueprint $table): void {
            // Sin ->after(): Postgres lo ignora en silencio (§9.D13).
            // DATE y no TIMESTAMP, como `ventas.cerrada_el`: la hora exacta
            // ya vive en la bitacora y el papel solo muestra d/m/Y (§7.5.2).
            $table->date('titular_hasta')->nullable();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE venta_cliente
                ADD CONSTRAINT venta_cliente_titular_sin_salida_chk
                CHECK (NOT (titular AND titular_hasta IS NOT NULL))
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE venta_cliente DROP CONSTRAINT IF EXISTS venta_cliente_titular_sin_salida_chk');

        Schema::table('venta_cliente', function (Blueprint $table): void {
            $table->dropColumn('titular_hasta');
        });
    }
};
