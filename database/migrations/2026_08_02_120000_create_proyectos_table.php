<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Raíz de la jerarquía proyectos → bloques → lotes (ADR-0002).
 *
 * Aunque hoy solo existe Praderas del Sol, `proyecto_id` está desde la
 * primera migración: el contrato reconoce que la contratante administra
 * desarrollos, y agregarlo después implicaría migrar datos con dinero de
 * por medio.
 *
 * `codigo` es el prefijo de los correlativos de contrato (RPS-2026-0065).
 *
 * NO se usan soft deletes: un proyecto con ventas no se borra, se
 * desactiva. Un `deleted_at` conviviendo con `activo` sería justo el tipo
 * de ambigüedad que rompe reportes de dinero.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proyectos', function (Blueprint $table): void {
            $table->id();

            $table->string('nombre', 150);
            $table->string('codigo', 10);
            $table->string('municipio', 100)->nullable();
            $table->string('departamento', 2)->nullable();
            $table->text('direccion')->nullable();
            $table->boolean('activo')->default(true);
            $table->text('observaciones')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique('nombre');
            $table->unique('codigo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proyectos');
    }
};
