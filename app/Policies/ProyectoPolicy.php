<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Proyecto;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class ProyectoPolicy
{
    use HandlesAuthorization;

    /**
     * 🔴 Cargar el plano de un desarrollo — 23-ago-2026.
     *
     * Los dos son de Mauricio por ahora («solo yo cargaré proyectos») y se
     * mueven desde Roles sin tocar código, que es de lo que se trata.
     *
     * §9.E3: se nombran uno por uno en `RoleSeeder`, nunca por patrón.
     *
     * Sin argumento de modelo: se consultan con
     * `can('importarPlano', Proyecto::class)`.
     */
    public function importarPlano(AuthUser $authUser): bool
    {
        return $authUser->can('ImportarPlano:Proyecto');
    }

    public function acomodarPlano(AuthUser $authUser): bool
    {
        return $authUser->can('AcomodarPlano:Proyecto');
    }

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Proyecto');
    }

    public function view(AuthUser $authUser, Proyecto $proyecto): bool
    {
        return $authUser->can('View:Proyecto');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Proyecto');
    }

    public function update(AuthUser $authUser, Proyecto $proyecto): bool
    {
        return $authUser->can('Update:Proyecto');
    }

    public function delete(AuthUser $authUser, Proyecto $proyecto): bool
    {
        return $authUser->can('Delete:Proyecto');
    }

    public function restore(AuthUser $authUser, Proyecto $proyecto): bool
    {
        return $authUser->can('Restore:Proyecto');
    }

    public function forceDelete(AuthUser $authUser, Proyecto $proyecto): bool
    {
        return $authUser->can('ForceDelete:Proyecto');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Proyecto');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Proyecto');
    }

    public function replicate(AuthUser $authUser, Proyecto $proyecto): bool
    {
        return $authUser->can('Replicate:Proyecto');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Proyecto');
    }
}
