<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * §9.E.1: todo Resource nace con su Policy. Sin ella, el Resource queda
 * visible para cualquier usuario autenticado.
 *
 * Los permisos siguen la convención de config/filament-shield.php:
 * separator ':' y case 'pascal'. Los genera shield:generate; nunca se
 * escriben a mano en un seeder (§9.E.2).
 */
class ProyectoPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Proyecto');
    }

    public function view(AuthUser $authUser): bool
    {
        return $authUser->can('View:Proyecto');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Proyecto');
    }

    public function update(AuthUser $authUser): bool
    {
        return $authUser->can('Update:Proyecto');
    }

    public function delete(AuthUser $authUser): bool
    {
        return $authUser->can('Delete:Proyecto');
    }

    public function restore(AuthUser $authUser): bool
    {
        return $authUser->can('Restore:Proyecto');
    }

    public function forceDelete(AuthUser $authUser): bool
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

    public function replicate(AuthUser $authUser): bool
    {
        return $authUser->can('Replicate:Proyecto');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Proyecto');
    }
}
