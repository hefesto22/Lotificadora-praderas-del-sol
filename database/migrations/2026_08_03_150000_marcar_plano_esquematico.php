<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca si el dibujo de un proyecto es un ESQUEMA o el plano de verdad.
 *
 * Un plano acomodado por el sistema y un plano trazado del documento del
 * topografo se ven identicos en pantalla, y de eso vive el problema: sin
 * esta bandera, en seis meses nadie sabe cual esta mirando, y alguien
 * termina mostrandole a un cliente un dibujo aproximado como si fuera la
 * ubicacion exacta de su lote.
 *
 * Se marca sola cuando el acomodador dibuja, y se quita a mano desde el
 * formulario del proyecto cuando la geometria pasa a venir del plano
 * legal. Default false: un proyecto sin dibujar no es esquematico, es
 * que no tiene plano.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proyectos', function (Blueprint $table): void {
            $table->boolean('plano_esquematico')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('proyectos', function (Blueprint $table): void {
            $table->dropColumn('plano_esquematico');
        });
    }
};
