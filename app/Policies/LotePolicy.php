<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Lote;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class LotePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Lote');
    }

    public function view(AuthUser $authUser, Lote $lote): bool
    {
        return $authUser->can('View:Lote');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Lote');
    }

    public function update(AuthUser $authUser, Lote $lote): bool
    {
        return $authUser->can('Update:Lote');
    }

    public function delete(AuthUser $authUser, Lote $lote): bool
    {
        return $authUser->can('Delete:Lote');
    }

    public function restore(AuthUser $authUser, Lote $lote): bool
    {
        return $authUser->can('Restore:Lote');
    }

    public function forceDelete(AuthUser $authUser, Lote $lote): bool
    {
        return $authUser->can('ForceDelete:Lote');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Lote');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Lote');
    }

    public function replicate(AuthUser $authUser, Lote $lote): bool
    {
        return $authUser->can('Replicate:Lote');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Lote');
    }
}
