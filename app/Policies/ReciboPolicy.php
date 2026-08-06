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
 * Lo que sí va a existir es ANULAR: una acción con nombre propio, con su
 * motivo, que devuelve el dinero a las cuotas y deja los dos recibos en la
 * historia. Cuando se construya tendrá su permiso `Anular:Recibo`, nombrado
 * uno por uno como manda el §9.E3 — no heredado de un `Update` genérico.
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
