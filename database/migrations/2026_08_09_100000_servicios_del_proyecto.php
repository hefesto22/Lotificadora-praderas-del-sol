<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que el desarrollo ya tiene: agua, luz, escriturado, financiamiento.
 *
 * ═══ JSONB Y NO UNA TABLA ═══
 *
 * Es una LISTA CERRADA de etiquetas (`ServicioDelProyecto`) que nunca se
 * consulta ni se cruza con nada: solo se lee entera para dibujarla en el
 * plano publico. Una tabla pivote pediria un modelo, una relacion y un
 * seeder para contestar la unica pregunta que se hace —«¿cuales tiene este
 * proyecto?»— que en jsonb es leer una columna.
 *
 * El dia que un servicio necesite fecha, costo o comprobante, ahi si va
 * tabla. Hoy seria estructura sin ninguna pregunta que la justifique.
 *
 * Nullable: un proyecto sin servicios marcados simplemente no muestra la
 * seccion. Una lista vacia con el titulo puesto se ve peor que no tenerla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proyectos', function (Blueprint $table): void {
            $table->jsonb('servicios')->nullable()->after('whatsapp');
        });
    }

    public function down(): void
    {
        Schema::table('proyectos', function (Blueprint $table): void {
            $table->dropColumn('servicios');
        });
    }
};
