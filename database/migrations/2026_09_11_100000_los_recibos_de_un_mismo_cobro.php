<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los recibos que salieron de un mismo cobro saben que salieron juntos.
 *
 * ═══ QUE LO PIDIO ═══
 *
 * Mauricio, el 11-sep-2026: «cuando tiene más de un titular de recibo y a cada
 * uno se le hizo un abono o pago de cuota y generó varios recibos, ¿cómo se
 * maneja eso?».
 *
 * Un cobro sale en un recibo POR TITULAR (`RegistroDePagos::agruparPorNombre()`),
 * así que el contrato con cuatro representados emite cuatro papeles de un solo
 * pago. Si ese cobro estuvo mal, hay que anular los cuatro.
 *
 * ═══ POR QUE HACE FALTA UNA COLUMNA ═══
 *
 * Porque hasta hoy esos cuatro papeles no tenían NADA que dijera que salieron
 * juntos: compartían contrato, fecha y quién los emitió, y eso es exactamente
 * lo que también comparten un cobro de la mañana y otro de la tarde. Agruparlos
 * por ahí es adivinar, y adivinar mal significa anular un recibo que estaba
 * bien.
 *
 * Con esto, «anular todo el cobro» es una pregunta que la base contesta sola.
 *
 * ⚠️ NULLABLE, y se queda así. Los recibos de antes de hoy no tienen emisión y
 * no se les puede inventar una — justamente porque no hay forma de saber cuáles
 * salieron juntos—. Esos se siguen anulando de a uno, que es lo que se hizo
 * siempre. Rellenarla a ojo sería escribir un dato falso en la base para que
 * una pantalla se vea más completa.
 *
 * ⚠️ Y solo se llena cuando el cobro sale en VARIOS papeles. Un recibo solo no
 * salió «junto» con nadie, y darle una emisión propia haría que la pantalla
 * ofrezca «anular todo el cobro» para anular exactamente uno.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recibos', function (Blueprint $tabla): void {
            $tabla->uuid('emision_id')->nullable()->after('venta_id');

            /*
             * El índice es el punto: la consulta que importa es «dame los
             * hermanos de este recibo», y sin él recorrería la tabla entera
             * cada vez que alguien abre el menú de un recibo.
             */
            $tabla->index('emision_id');
        });
    }

    public function down(): void
    {
        Schema::table('recibos', function (Blueprint $tabla): void {
            $tabla->dropIndex(['emision_id']);
            $tabla->dropColumn('emision_id');
        });
    }
};
