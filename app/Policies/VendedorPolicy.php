<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Vendedor;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class VendedorPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Vendedor');
    }

    public function view(AuthUser $authUser, Vendedor $vendedor): bool
    {
        return $authUser->can('View:Vendedor');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Vendedor');
    }

    public function update(AuthUser $authUser, Vendedor $vendedor): bool
    {
        return $authUser->can('Update:Vendedor');
    }

    public function delete(AuthUser $authUser, Vendedor $vendedor): bool
    {
        return $authUser->can('Delete:Vendedor');
    }

    public function restore(AuthUser $authUser, Vendedor $vendedor): bool
    {
        return $authUser->can('Restore:Vendedor');
    }

    public function forceDelete(AuthUser $authUser, Vendedor $vendedor): bool
    {
        return $authUser->can('ForceDelete:Vendedor');
    }
}
