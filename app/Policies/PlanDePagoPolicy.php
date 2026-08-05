<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PlanDePago;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Quién puede tocar el precio de la vara² por plazo.
 *
 * ⚠️ Esta clase no es opcional. Filament resuelve la autorización con
 * `get_authorization_response()`, y cuando **no encuentra política** para un
 * modelo devuelve `Response::allow()`. O sea: sin este archivo, el precio de
 * lista de todo el proyecto lo puede editar cualquiera que entre al panel,
 * receptores incluidos, y sin que nada se vea roto.
 *
 * Los permisos no los genera `shield:generate` —PlanDePago no es un Resource,
 * se administra como relation manager del proyecto— así que los nombra el
 * RoleSeeder uno por uno, igual que el resto (§9.E3).
 */
class PlanDePagoPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:PlanDePago');
    }

    public function view(AuthUser $authUser, PlanDePago $planDePago): bool
    {
        return $authUser->can('View:PlanDePago');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:PlanDePago');
    }

    public function update(AuthUser $authUser, PlanDePago $planDePago): bool
    {
        return $authUser->can('Update:PlanDePago');
    }

    public function delete(AuthUser $authUser, PlanDePago $planDePago): bool
    {
        return $authUser->can('Delete:PlanDePago');
    }

    public function restore(AuthUser $authUser, PlanDePago $planDePago): bool
    {
        return $authUser->can('Restore:PlanDePago');
    }

    public function forceDelete(AuthUser $authUser, PlanDePago $planDePago): bool
    {
        return $authUser->can('ForceDelete:PlanDePago');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:PlanDePago');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:PlanDePago');
    }

    public function replicate(AuthUser $authUser, PlanDePago $planDePago): bool
    {
        return $authUser->can('Replicate:PlanDePago');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:PlanDePago');
    }
}
