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

    /**
     * R14: la prorroga la autoriza la administracion, no la da el mostrador.
     *
     * Se nombra sola y NO sale del cruce acciones x recursos (§9.E3). Ver un
     * apartado y estirarlo son cosas distintas: el receptor hace lo primero
     * todo el dia, y un `Update:Compromiso` generico le daria las dos.
     */
    public function prorrogar(AuthUser $authUser, Compromiso $compromiso): bool
    {
        return $authUser->can('Prorrogar:Compromiso');
    }

    /**
     * Marcar que la seña de un apartado caido ya se devolvio.
     *
     * Es una afirmacion sobre plata que salio de caja. Mientras no exista el
     * modulo de egresos con su comprobante, lo unico que la respalda es quien
     * la marco — asi que la marca alguien de administracion.
     */
    public function devolverSenia(AuthUser $authUser, Compromiso $compromiso): bool
    {
        return $authUser->can('DevolverSenia:Compromiso');
    }
}
