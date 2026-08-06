<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Reprogramacion;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Quien puede ver por que cambio el plan — y nadie puede tocarlo.
 *
 * ⚠️ Filament PERMITE lo que no tiene politica: `get_authorization_response()`
 * devuelve `Response::allow()` cuando no encuentra ninguna. Sin este archivo,
 * la pestaña de reprogramaciones del expediente saldria con botones de editar
 * y borrar para cualquiera que entre al panel.
 *
 * ═══ CREAR TAMPOCO ═══
 *
 * A diferencia de `Recibo`, aca ni siquiera hay `create`. Una reprogramacion
 * no se registra a mano: nace dentro de la transaccion del abono, junto al
 * recibo y al plan nuevo. Un formulario que la creara suelta produciria una
 * fila que dice que el plan cambio sin que ninguna cuota se haya movido.
 *
 * ═══ Y NO SE CORRIGE ═══
 *
 * Si una reprogramacion se hizo mal, la correccion es OTRA reprogramacion con
 * su motivo. Editarle el motivo a esta seria reescribir la explicacion que se
 * le dio a un cliente.
 */
class ReprogramacionPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Reprogramacion');
    }

    public function view(AuthUser $authUser, Reprogramacion $reprogramacion): bool
    {
        return $authUser->can('View:Reprogramacion');
    }

    public function create(AuthUser $authUser): bool
    {
        return false;
    }

    public function update(AuthUser $authUser, Reprogramacion $reprogramacion): bool
    {
        return false;
    }

    public function delete(AuthUser $authUser, Reprogramacion $reprogramacion): bool
    {
        return false;
    }

    public function restore(AuthUser $authUser, Reprogramacion $reprogramacion): bool
    {
        return false;
    }

    public function forceDelete(AuthUser $authUser, Reprogramacion $reprogramacion): bool
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

    public function replicate(AuthUser $authUser, Reprogramacion $reprogramacion): bool
    {
        return false;
    }

    public function reorder(AuthUser $authUser): bool
    {
        return false;
    }
}
