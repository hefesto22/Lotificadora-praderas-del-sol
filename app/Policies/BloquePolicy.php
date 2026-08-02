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
class BloquePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Bloque');
    }

    public function view(AuthUser $authUser): bool
    {
        return $authUser->can('View:Bloque');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Bloque');
    }

    public function update(AuthUser $authUser): bool
    {
        return $authUser->can('Update:Bloque');
    }

    public function delete(AuthUser $authUser): bool
    {
        return $authUser->can('Delete:Bloque');
    }

    public function restore(AuthUser $authUser): bool
    {
        return $authUser->can('Restore:Bloque');
    }

    public function forceDelete(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDelete:Bloque');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Bloque');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Bloque');
    }

    public function replicate(AuthUser $authUser): bool
    {
        return $authUser->can('Replicate:Bloque');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Bloque');
    }
}
