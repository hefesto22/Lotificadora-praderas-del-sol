<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Compromiso;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class CompromisoPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Compromiso');
    }

    public function view(AuthUser $authUser, Compromiso $compromiso): bool
    {
        return $authUser->can('View:Compromiso');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Compromiso');
    }

    public function update(AuthUser $authUser, Compromiso $compromiso): bool
    {
        return $authUser->can('Update:Compromiso');
    }

    public function delete(AuthUser $authUser, Compromiso $compromiso): bool
    {
        return $authUser->can('Delete:Compromiso');
    }

    public function restore(AuthUser $authUser, Compromiso $compromiso): bool
    {
        return $authUser->can('Restore:Compromiso');
    }

    public function forceDelete(AuthUser $authUser, Compromiso $compromiso): bool
    {
        return $authUser->can('ForceDelete:Compromiso');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Compromiso');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Compromiso');
    }

    public function replicate(AuthUser $authUser, Compromiso $compromiso): bool
    {
        return $authUser->can('Replicate:Compromiso');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Compromiso');
    }
}
