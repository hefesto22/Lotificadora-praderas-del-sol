<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Documento;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Quién puede ver y guardar los papeles del expediente.
 *
 * ⚠️ Filament PERMITE lo que no tiene política, así que sin este archivo
 * cualquiera con acceso al panel podría descargar una copia de identidad o
 * borrar la promesa de venta firmada.
 *
 * El archivo NO se sirve por URL: la descarga pasa por una acción del panel
 * que consulta `view`. Un documento con datos personales no puede depender de
 * que la URL no se filtre.
 */
class DocumentoPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Documento');
    }

    public function view(AuthUser $authUser, Documento $documento): bool
    {
        return $authUser->can('View:Documento');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Documento');
    }

    public function update(AuthUser $authUser, Documento $documento): bool
    {
        return $authUser->can('Update:Documento');
    }

    public function delete(AuthUser $authUser, Documento $documento): bool
    {
        return $authUser->can('Delete:Documento');
    }

    public function restore(AuthUser $authUser, Documento $documento): bool
    {
        return $authUser->can('Restore:Documento');
    }

    public function forceDelete(AuthUser $authUser, Documento $documento): bool
    {
        return $authUser->can('ForceDelete:Documento');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Documento');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Documento');
    }

    public function replicate(AuthUser $authUser, Documento $documento): bool
    {
        return $authUser->can('Replicate:Documento');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Documento');
    }
}
