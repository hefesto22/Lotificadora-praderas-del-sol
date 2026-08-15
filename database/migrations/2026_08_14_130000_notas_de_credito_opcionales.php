<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Facturar y emitir notas de crédito son dos permisos distintos del SAR.
 *
 * ═══ POR QUÉ ESTO EXISTE ═══
 *
 * Lo preguntó Mauricio el 14-ago-2026: «¿y si factura pero no hacen notas de
 * crédito?». Es el caso NORMAL, no la excepción. La autorización de notas de
 * crédito se tramita aparte de la de facturas —CAI propio, rango propio, su
 * propio código de documento— y muchísimos negocios nunca la pidieron porque
 * nunca les hizo falta.
 *
 * Sin este interruptor solo había dos salidas y las dos malas: obligar a
 * emitir una nota de crédito que no pueden emitir —y trabarles la rescisión
 * por un papel inexistente— o callarse, y que el ingreso les quede
 * sobredeclarado sin que nadie se entere hasta la fiscalización.
 *
 * ═══ APAGADO POR DEFECTO, COMO EL INTERÉS Y LA MORA (§8.5) ═══
 *
 * Misma línea de producto: lo que no todas las lotificadoras hacen nace
 * apagado y lo enciende quien pueda. Con el interruptor apagado el sistema no
 * bloquea nada; el acta de la rescisión imprime una línea para que el contador
 * decida, que es exactamente lo que el sistema puede aportar honestamente
 * mientras no exista el módulo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facturaciones', function (Blueprint $table): void {
            /*
             * `false` y no `true`: encenderlo obliga a cargar una
             * autorización de notas de crédito que hoy no existe en el
             * sistema. Que nazca encendido prometería algo que no se cumple.
             */
            $table->boolean('emite_notas_credito')->default(false)->after('activa');
        });
    }

    public function down(): void
    {
        Schema::table('facturaciones', function (Blueprint $table): void {
            $table->dropColumn('emite_notas_credito');
        });
    }
};
