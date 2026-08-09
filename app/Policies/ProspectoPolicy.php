<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Prospecto;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Quién puede ver los datos de quienes escribieron por el plano público.
 *
 * ⚠️ Esta clase no es opcional. Filament resuelve la autorización con
 * `get_authorization_response()`, y cuando **no encuentra política** para un
 * modelo devuelve `Response::allow()`. Sin este archivo, el nombre y el
 * teléfono de cada persona que llenó el formulario los ve cualquiera que
 * entre al panel, y sin que nada se vea roto.
 *
 * ═══ POR QUE NO SE CREAN NI SE BORRAN ═══
 *
 * `create()` y `delete()` devuelven `false` para todos, incluida la
 * administradora. Un prospecto es la traza de por dónde llegó un cliente:
 * inventarlos falsea la única medida que dice si el plano público sirve, y
 * borrar el que no gustó deja esa medida optimista para siempre. Si hace
 * falta sacarlos —una solicitud de baja de datos personales, por ejemplo—
 * eso será su propia acción, con motivo y auditada.
 *
 * `update()` sí: es como se marca «ya lo llamé» y se deja la nota.
 */
class ProspectoPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Prospecto');
    }

    public function view(AuthUser $authUser, Prospecto $prospecto): bool
    {
        return $authUser->can('View:Prospecto');
    }

    /**
     * Nadie. Un prospecto nace en el formulario del plano público.
     */
    public function create(AuthUser $authUser): bool
    {
        return false;
    }

    /**
     * Marcar atendido y dejar la nota de la llamada.
     */
    public function update(AuthUser $authUser, Prospecto $prospecto): bool
    {
        return $authUser->can('Update:Prospecto');
    }

    /**
     * Nadie. Ver el docblock: borrar el prospecto que no gustó deja la
     * medición del plano público optimista para siempre.
     */
    public function delete(AuthUser $authUser, Prospecto $prospecto): bool
    {
        return false;
    }

    public function restore(AuthUser $authUser, Prospecto $prospecto): bool
    {
        return false;
    }

    public function forceDelete(AuthUser $authUser, Prospecto $prospecto): bool
    {
        return false;
    }
}
