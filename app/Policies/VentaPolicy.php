<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Venta;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class VentaPolicy
{
    use HandlesAuthorization;

    /**
     * Reescribir el plan de cuotas de un lote (R21).
     *
     * ═══ POR QUE NO ALCANZA CON `Create:Recibo` ═══
     *
     * El receptor cobra: esa es su escritura y su trabajo. Un abono a capital
     * emite un recibo TAMBIEN, pero además borra las cuotas pendientes del
     * lote y escribe otras — reescribe el contrato que el cliente firmó. Es la
     * misma frontera que ya separa cobrar de firmar una venta: congelar un
     * plan es de la administradora.
     *
     * §9.E3: el permiso se nombra uno por uno en `RoleSeeder`. Nunca por
     * patrón, o el día que aparezca otra acción se le regala a quien no debía
     * tenerla.
     *
     * Sin argumento de modelo a propósito: se consulta con
     * `can('reprogramar', Venta::class)` y el Gate saca el nombre de clase del
     * arreglo de argumentos, igual que hace `ReciboPolicy::create()`.
     */
    public function reprogramar(AuthUser $authUser): bool
    {
        return $authUser->can('Reprogramar:Venta');
    }

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Venta');
    }

    public function view(AuthUser $authUser, Venta $venta): bool
    {
        return $authUser->can('View:Venta');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Venta');
    }

    public function update(AuthUser $authUser, Venta $venta): bool
    {
        return $authUser->can('Update:Venta');
    }

    public function delete(AuthUser $authUser, Venta $venta): bool
    {
        return $authUser->can('Delete:Venta');
    }

    public function restore(AuthUser $authUser, Venta $venta): bool
    {
        return $authUser->can('Restore:Venta');
    }

    public function forceDelete(AuthUser $authUser, Venta $venta): bool
    {
        return $authUser->can('ForceDelete:Venta');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Venta');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Venta');
    }

    public function replicate(AuthUser $authUser, Venta $venta): bool
    {
        return $authUser->can('Replicate:Venta');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Venta');
    }
}
