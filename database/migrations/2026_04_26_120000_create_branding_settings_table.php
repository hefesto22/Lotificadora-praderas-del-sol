<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branding_settings', function (Blueprint $table): void {
            $table->id();

            // Rutas relativas a storage/app/public — accesibles en /storage/{path}
            $table->string('logo_path')->nullable()
                ->comment('Logo visible en la barra superior del panel');
            $table->string('favicon_path')->nullable()
                ->comment('Icono de la pestaña del navegador');

            // HEX color del botón primario y acentos del panel.
            // Filament genera la paleta de 11 tonos automáticamente.
            $table->string('primary_color', 7)->default('#f59e0b')
                ->comment('Color primario en formato HEX (#rrggbb). Default: amber-500');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branding_settings');
    }
};
