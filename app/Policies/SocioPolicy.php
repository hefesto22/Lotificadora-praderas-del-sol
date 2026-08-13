<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Socio;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class SocioPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Socio');
    }

    public function view(AuthUser $authUser, Socio $socio): bool
    {
        return $authUser->can('View:Socio');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Socio');
    }

    public function update(AuthUser $authUser, Socio $socio): bool
    {
        return $authUser->can('Update:Socio');
    }

    public function delete(AuthUser $authUser, Socio $socio): bool
    {
        return $authUser->can('Delete:Socio');
    }

    public function restore(AuthUser $authUser, Socio $socio): bool
    {
        return $authUser->can('Restore:Socio');
    }

    public function forceDelete(AuthUser $authUser, Socio $socio): bool
    {
        return $authUser->can('ForceDelete:Socio');
    }
}
