<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Calle;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class CallePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Calle');
    }

    public function view(AuthUser $authUser, Calle $calle): bool
    {
        return $authUser->can('View:Calle');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Calle');
    }

    public function update(AuthUser $authUser, Calle $calle): bool
    {
        return $authUser->can('Update:Calle');
    }

    public function delete(AuthUser $authUser, Calle $calle): bool
    {
        return $authUser->can('Delete:Calle');
    }

    public function restore(AuthUser $authUser, Calle $calle): bool
    {
        return $authUser->can('Restore:Calle');
    }

    public function forceDelete(AuthUser $authUser, Calle $calle): bool
    {
        return $authUser->can('ForceDelete:Calle');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Calle');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Calle');
    }

    public function replicate(AuthUser $authUser, Calle $calle): bool
    {
        return $authUser->can('Replicate:Calle');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Calle');
    }
}
