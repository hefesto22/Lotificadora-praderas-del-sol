<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Recibo;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Quién puede cobrar, y por qué nadie puede corregir un recibo.
 *
 * ⚠️ Filament PERMITE lo que no tiene política —`get_authorization_response()`
 * devuelve `Response::allow()` cuando no encuentra ninguna—, así que sin este
 * archivo cualquiera que entrara al panel podría emitir recibos.
 *
 * ═══ CREAR SI, EDITAR NO ═══
 *
 * El receptor cobra: es su trabajo y su única escritura en el sistema. Pero
 * un recibo entregado en papel NO se corrige. Cambiarle el monto deja el papel
 * del cliente diciendo una cosa y la base diciendo otra, que es exactamente el
 * problema que un correlativo (R12) viene a evitar.
 *
 * ═══ ANULAR SI, Y SOLO LA ADMINISTRADORA (8-ago-2026) ═══
 *
 * Ya existe: acción con nombre propio, motivo obligatorio, que devuelve el
 * dinero a las cuotas y deja las dos filas en la historia. Su permiso es
 * `Anular:Recibo`, nombrado uno por uno como manda el §9.E3 — no heredado de
 * un `Update` genérico, justamente para que el receptor NO lo reciba: quien
 * cobra no debería poder borrar su propio cobro del estado de cuenta.
 */
class ReciboPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Recibo');
    }

    public function view(AuthUser $authUser, Recibo $recibo): bool
    {
        return $authUser->can('View:Recibo');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Recibo');
    }

    public function update(AuthUser $authUser, Recibo $recibo): bool
    {
        return false;
    }

    /**
     * Anular un recibo mal emitido.
     *
     * El `estaAnulado()` va acá y no solo en el Service: sin él, la acción
     * seguiría ofreciéndose sobre un recibo ya anulado y quien atiende
     * descubriría que no se puede recién después de escribir el motivo.
     */
    public function anular(AuthUser $authUser, Recibo $recibo): bool
    {
        return $authUser->can('Anular:Recibo') && ! $recibo->estaAnulado();
    }

    public function delete(AuthUser $authUser, Recibo $recibo): bool
    {
        return false;
    }

    public function restore(AuthUser $authUser, Recibo $recibo): bool
    {
        return false;
    }

    public function forceDelete(AuthUser $authUser, Recibo $recibo): bool
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

    public function replicate(AuthUser $authUser, Recibo $recibo): bool
    {
        return false;
    }

    public function reorder(AuthUser $authUser): bool
    {
        return false;
    }
}
