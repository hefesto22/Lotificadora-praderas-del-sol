<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El logo de cada desarrollo, para el papel que se lleva el cliente.
 *
 * Lo pidió Mauricio el 14-ago-2026 con los tres logos en la mano:
 * Inmobiliaria Maya —la empresa— y las dos urbanizaciones, El Bambú y
 * Altamira, cada una con su marca propia y sus colores.
 *
 * ═══ POR QUE DOS LOGOS Y NO UNO ═══
 *
 * Porque son dos cosas distintas. El de la instalación —el que ya vive en
 * `BrandingSetting` y se ve arriba del panel— es el de la EMPRESA, y es el
 * que responde «¿quién me cobró?». El de acá es el del DESARROLLO, y
 * responde «¿qué compré?». En una factura los dos hacen falta: el cliente
 * reconoce la marca del residencial donde compró su lote, y el documento
 * dice que quien emite es Inmobiliaria Maya.
 *
 * ⚠️ El logo NO es un requisito fiscal. El Acuerdo 481-2017 lista lo que
 * tiene que ir impreso —razón social, RTN, CAI, número, dirección del
 * establecimiento, fecha límite— y el logo no está en esa lista, ni
 * exigido ni prohibido. Por eso una factura puede llevar el logo verde de
 * El Bambú mientras el encabezado dice INMOBILIARIA MAYA: la identidad
 * fiscal vive en el texto, no en la imagen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proyectos', function (Blueprint $table): void {
            /*
             * La RUTA en el disco `public`, no la URL: el dominio cambia
             * entre la Mac de Mauricio y el VPS, y una URL guardada
             * apuntaria al lugar equivocado el dia del despliegue.
             */
            $table->string('logo_path')->nullable()->after('codigo');
        });
    }

    public function down(): void
    {
        Schema::table('proyectos', function (Blueprint $table): void {
            $table->dropColumn('logo_path');
        });
    }
};
