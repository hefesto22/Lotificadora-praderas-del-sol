<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Quién recibe dinero, y en qué proyecto — 24-oct-2026.
 *
 * «Hay que hacer un toggle para marcar si ese usuario aparece como persona que
 * recibe dinero o no, y si no está marcado como permitido en X proyecto,
 * entonces no aparece en ese proyecto» — Mauricio, mirando a la
 * administradora en la lista «¿Quién recibió el dinero?» de un cobro.
 *
 * Hasta hoy esa lista salía del permiso `Create:Recibo`: todo el que podía
 * cobrar aparecía en todos los proyectos. Desde hoy sale de esta tabla.
 *
 * ═══ 🔴 LA SIEMBRA: NADIE DESAPARECE EL DÍA DEL DESPLIEGUE ═══
 *
 * Una tabla vacía dejaría la lista vacía, y con la lista vacía el campo deja
 * de ser obligatorio (`QuienRecibeElDinero::campo()`): los recibos volverían a
 * salir a nombre de quien teclea. Así que se siembra con EXACTAMENTE quien
 * aparece hoy —el que tiene `Create:Recibo`, por rol o directo, menos el
 * super-admin— en TODOS los proyectos. Después se desmarca desde la ficha del
 * usuario.
 *
 * ⚠️ En una base recién creada todavía no existe el permiso (lo siembra
 * `RoleSeeder` después de migrar): ahí no hay a quién sembrar y no se siembra.
 * Nada de esto asume los datos de una instalación (§9.F9).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proyecto_receptores', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->foreignId('proyecto_id')->constrained('proyectos')->cascadeOnDelete();
            $tabla->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $tabla->timestamps();

            $tabla->unique(['proyecto_id', 'user_id']);
            $tabla->index('user_id');
        });

        $this->sembrarLosDeHoy();
    }

    public function down(): void
    {
        Schema::dropIfExists('proyecto_receptores');
    }

    private function sembrarLosDeHoy(): void
    {
        $permiso = DB::table('permissions')->where('name', 'Create:Recibo')->value('id');

        if ($permiso === null) {
            return;
        }

        $usuario = new User;
        $tipo = $usuario->getMorphClass();
        $superAdmin = config('filament-shield.super_admin.name');
        $superAdmin = is_string($superAdmin) && $superAdmin !== '' ? $superAdmin : 'super_admin';

        $porRol = DB::table('model_has_roles')
            ->join('role_has_permissions', 'role_has_permissions.role_id', '=', 'model_has_roles.role_id')
            ->where('role_has_permissions.permission_id', $permiso)
            ->where('model_has_roles.model_type', $tipo)
            ->select('model_has_roles.model_id');

        $directo = DB::table('model_has_permissions')
            ->where('permission_id', $permiso)
            ->where('model_type', $tipo)
            ->select('model_id');

        $superAdmins = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('roles.name', $superAdmin)
            ->where('model_has_roles.model_type', $tipo)
            ->select('model_has_roles.model_id');

        $usuarios = DB::table('users')
            ->whereNull('deleted_at')
            ->where(static function (Builder $quien) use ($porRol, $directo): void {
                $quien->whereIn('id', $porRol)->orWhereIn('id', $directo);
            })
            ->whereNotIn('id', $superAdmins)
            ->orderBy('id')
            ->pluck('id');

        $proyectos = DB::table('proyectos')->orderBy('id')->pluck('id');
        $ahora = now();
        $filas = [];

        foreach ($proyectos as $proyecto) {
            foreach ($usuarios as $id) {
                $filas[] = [
                    'proyecto_id' => $proyecto,
                    'user_id'     => $id,
                    'created_at'  => $ahora,
                    'updated_at'  => $ahora,
                ];
            }
        }

        foreach (array_chunk($filas, 500) as $tanda) {
            DB::table('proyecto_receptores')->insert($tanda);
        }
    }
};
