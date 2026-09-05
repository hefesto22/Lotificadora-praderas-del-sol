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
 *
 * ═══ 🔴 CORREGIR LO QUE NO ES DINERO — 4-sep-2026 ═══
 *
 * «Que pueda editar los recibos la administradora, solo los recibos»
 * — Mauricio, después de arreglar a mano por SSH un recibo que había salido
 * sin `recibido_por`.
 *
 * `update()` sigue devolviendo `false` y eso NO es una contradicción: son dos
 * cosas distintas con el mismo nombre. Un `Update` genérico abre el monto, el
 * concepto y la fecha —el papel del cliente y el plan de pagos—, y esa puerta
 * queda cerrada. `Corregir:Recibo` abre cuatro campos que no mueven un
 * centavo: quién recibió el dinero, la forma de pago, la referencia y las
 * observaciones. La lista vive en `CorreccionDeRecibo::CAMPOS`, con el porqué
 * de cada exclusión.
 *
 * Permiso propio y nombrado uno por uno (§9.E3), otra vez para que el receptor
 * no lo herede: corregir a nombre de quién quedó un cobro es justamente lo que
 * quien cobró no debería poder cambiar solo.
 *
 * Y NO se corrige un recibo anulado: ese papel ya está muerto, y lo que hay
 * que corregir es el que lo reemplazó.
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

    /**
     * ⚠️ `false` a propósito, y NO es lo mismo que `corregir()`. Ver el
     * docblock de arriba antes de cambiar esto por un `can('Update:Recibo')`:
     * el `Update` genérico de Filament abre TODO el formulario, monto
     * incluido, y ese es el cambio que descuadra el plan de pagos.
     */
    public function update(AuthUser $authUser, Recibo $recibo): bool
    {
        return false;
    }

    /**
     * Corregir los datos que no son dinero (4-sep-2026).
     *
     * El `estaAnulado()` va acá y no solo en el Service por lo mismo que en
     * `anular()`: sin él la acción se seguiría ofreciendo sobre un recibo
     * muerto, y quien atiende lo descubriría recién después de escribir el
     * motivo.
     */
    public function corregir(AuthUser $authUser, Recibo $recibo): bool
    {
        return $authUser->can('Corregir:Recibo') && ! $recibo->estaAnulado();
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
