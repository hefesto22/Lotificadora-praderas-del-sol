<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Cuota;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Quién puede ver el plan de cuotas — y nadie puede editarlo.
 *
 * ⚠️ Esta clase no es opcional. Filament resuelve la autorización con
 * `get_authorization_response()`, y cuando NO encuentra política devuelve
 * `Response::allow()`. Sin este archivo, la tabla de cuotas del expediente
 * saldría con botones de editar y borrar para cualquiera que entre al panel.
 *
 * ═══ NO HAY PERMISOS PROPIOS, Y ES A PROPOSITO ═══
 *
 * Una cuota no se administra: es parte del expediente. Quien puede ver la
 * venta ve su plan, y por eso las lecturas se apoyan en `View:Venta` en vez
 * de inventar `ViewAny:Cuota` — un permiso más que alguien tendría que
 * acordarse de dar, para exactamente la misma decisión.
 *
 * ═══ Y NO SE ESCRIBE DESDE EL PANEL ═══
 *
 * El plan es un snapshot inmutable (§9.D6): se congela al firmar y solo lo
 * mueve el Service de pagos, dentro de su transacción. Cambiar el monto o la
 * fecha de una cuota a mano es reescribir un contrato que alguien firmó en
 * papel; eso será una reprogramación, con su motivo y su bitácora, y tendrá
 * su propia acción con nombre. Hasta entonces, todo `false`.
 */
class CuotaPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Venta');
    }

    public function view(AuthUser $authUser, Cuota $cuota): bool
    {
        return $authUser->can('View:Venta');
    }

    public function create(AuthUser $authUser): bool
    {
        return false;
    }

    public function update(AuthUser $authUser, Cuota $cuota): bool
    {
        return false;
    }

    public function delete(AuthUser $authUser, Cuota $cuota): bool
    {
        return false;
    }

    public function restore(AuthUser $authUser, Cuota $cuota): bool
    {
        return false;
    }

    public function forceDelete(AuthUser $authUser, Cuota $cuota): bool
    {
        return false;
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return false;
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return false;
    }

    public function replicate(AuthUser $authUser, Cuota $cuota): bool
    {
        return false;
    }

    public function reorder(AuthUser $authUser): bool
    {
        return false;
    }
}
