<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrupador de lotes dentro de un proyecto.
 *
 * `area_total_varas` y `lotes_planificados` son DATOS DECLARADOS DEL
 * PLANO, no un caché de lo que hay cargado. La cantidad real de lotes se
 * deriva siempre con withCount('lotes'): una columna que guardara el
 * conteo se desincronizaría en silencio.
 *
 * Tenerlos declarados permite conciliar: "el plano dice 42 lotes, hay 40
 * cargados". Eso es una herramienta, no un bug esperando.
 *
 * El índice único (id, proyecto_id) parece redundante —id ya es PK— pero
 * es lo que habilita la FK compuesta desde `lotes`, que garantiza a nivel
 * de base que un lote nunca apunte a un bloque de otro proyecto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bloques', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('proyecto_id')->constrained('proyectos')->restrictOnDelete();
            $table->string('nombre', 30);
            $table->decimal('area_total_varas', 12, 4)->nullable();
            $table->unsignedInteger('lotes_planificados')->nullable();
            $table->unsignedSmallInteger('orden')->default(0);
            $table->text('observaciones')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['proyecto_id', 'nombre']);
            $table->unique(['id', 'proyecto_id']);
            $table->index(['proyecto_id', 'orden']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bloques');
    }
};
