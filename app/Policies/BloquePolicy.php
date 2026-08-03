<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Bloque;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class BloquePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Bloque');
    }

    public function view(AuthUser $authUser, Bloque $bloque): bool
    {
        return $authUser->can('View:Bloque');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Bloque');
    }

    public function update(AuthUser $authUser, Bloque $bloque): bool
    {
        return $authUser->can('Update:Bloque');
    }

    public function delete(AuthUser $authUser, Bloque $bloque): bool
    {
        return $authUser->can('Delete:Bloque');
    }

    public function restore(AuthUser $authUser, Bloque $bloque): bool
    {
        return $authUser->can('Restore:Bloque');
    }

    public function forceDelete(AuthUser $authUser, Bloque $bloque): bool
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

    public function replicate(AuthUser $authUser, Bloque $bloque): bool
    {
        return $authUser->can('Replicate:Bloque');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Bloque');
    }
}
