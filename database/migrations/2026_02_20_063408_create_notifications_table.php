<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->numericMorphs('notifiable');
            // JSON estándar de Laravel — abajo lo convertimos a JSONB
            // si la conexión es PostgreSQL para aprovechar índices GIN.
            $table->json('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            // Filament filtra por notifiable + read_at NULL en sidebar
            $table->index(['notifiable_type', 'notifiable_id', 'read_at']);
        });

        // Postgres: convierte data a JSONB y agrega índice GIN para queries
        // sobre el contenido del JSON (ej: por tipo de notificación interna).
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE notifications ALTER COLUMN data TYPE JSONB USING data::jsonb');
            DB::statement('CREATE INDEX notifications_data_gin_idx ON notifications USING GIN (data)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
