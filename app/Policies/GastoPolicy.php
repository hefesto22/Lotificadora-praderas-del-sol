<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Gasto;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Quién ve lo que la lotificadora gasta.
 *
 * ⚠️ Esta clase no es opcional. Filament resuelve la autorización con
 * `get_authorization_response()` y cuando **no encuentra política** devuelve
 * `Response::allow()`: sin este archivo, la pestaña de gastos se la ve
 * cualquiera que entre al panel —receptores incluidos— y sin que nada se vea
 * roto. Es la misma cicatriz que dejó `PlanDePagoPolicy`.
 *
 * ═══ EL RECEPTOR NO ENTRA, Y NO ES DESCONFIANZA ═══
 *
 * Decidido con Mauricio el 11-ago-2026. Lo que el desarrollo cuesta es
 * información del dueño: márgenes, a quién se le paga y cuánto. Quien atiende
 * el mostrador no necesita nada de eso para cobrar una cuota, y es la misma
 * línea con la que hoy no ve los prospectos ni puede anular un recibo.
 *
 * Como `Gasto` NO es un Resource —se administra como pestaña del proyecto—,
 * `shield:generate` no lo ve y sus permisos los nombra el `RoleSeeder` uno por
 * uno (§9.E3).
 *
 * ═══ POR QUE SI HAY `update` Y `delete` ═══
 *
 * Un gasto no es un papel que el cliente se llevó firmado: es un asiento
 * interno cuyo respaldo es la factura del proveedor. Corregir un monto mal
 * tecleado tiene que poder hacerse. Lo que lo vuelve auditable no es que sea
 * inmutable sino que `Gasto` escribe en la bitácora todo lo que cambia.
 */
class GastoPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Gasto');
    }

    public function view(AuthUser $authUser, Gasto $gasto): bool
    {
        return $authUser->can('View:Gasto');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Gasto');
    }

    public function update(AuthUser $authUser, Gasto $gasto): bool
    {
        return $authUser->can('Update:Gasto');
    }

    public function delete(AuthUser $authUser, Gasto $gasto): bool
    {
        return $authUser->can('Delete:Gasto');
    }

    /**
     * El borrado definitivo queda para super_admin, igual que en el resto:
     * `ForceDelete` destruye la fila y no deja rastro.
     */
    public function forceDelete(AuthUser $authUser, Gasto $gasto): bool
    {
        return $authUser->can('ForceDelete:Gasto');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Gasto');
    }

    public function restore(AuthUser $authUser, Gasto $gasto): bool
    {
        return $authUser->can('Restore:Gasto');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Gasto');
    }

    public function replicate(AuthUser $authUser, Gasto $gasto): bool
    {
        return $authUser->can('Replicate:Gasto');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Gasto');
    }
}
