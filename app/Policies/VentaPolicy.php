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

    /**
     * Pasarle el expediente a otra persona: la cesión de derechos.
     *
     * ═══ POR QUE NO ALCANZA CON `Update:Venta` ═══
     *
     * Porque `Update:Venta` es el permiso de corregir un dato del
     * expediente, y esto no corrige nada: **cambia de quién es el
     * contrato**. Los pagos no se mueven, pero de aquí en adelante los
     * recibos salen a otro nombre y el estado de cuenta se dirige a otra
     * persona. Decidido con Mauricio el 22-ago-2026: la llave la tiene solo
     * la administradora.
     *
     * §9.E3: se nombra uno por uno en `RoleSeeder`, nunca por patrón.
     *
     * Sin argumento de modelo, igual que `reprogramar()`: se consulta con
     * `can('cambiarTitular', Venta::class)`.
     */
    public function cambiarTitular(AuthUser $authUser): bool
    {
        return $authUser->can('CambiarTitular:Venta');
    }

    /**
     * Saldar un lote con un descuento: el pronto pago (23-ago-2026).
     *
     * ═══ POR QUE NO ALCANZA CON `Reprogramar:Venta` ═══
     *
     * Porque reprogramar no le cuesta plata a nadie: reescribe un plan con la
     * misma deuda repartida distinto. Un pronto pago **perdona saldo** — es
     * dinero que la lotificadora decide no cobrar, sin tope. Son dos llaves
     * distintas y quien tiene una no tiene por qué tener la otra.
     *
     * Mauricio, 23-ago-2026, eligiendo entre tres caminos: «un permiso propio,
     * para quien vos decidas». Nace para super_admin y administradora, y se
     * mueve desde Roles sin tocar código.
     *
     * §9.E3: se nombra uno por uno en `RoleSeeder`, nunca por patrón.
     *
     * Sin argumento de modelo, igual que los dos de arriba: se consulta con
     * `can('prontoPago', Venta::class)`.
     */
    public function prontoPago(AuthUser $authUser): bool
    {
        return $authUser->can('ProntoPago:Venta');
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
