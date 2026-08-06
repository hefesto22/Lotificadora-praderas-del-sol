<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ImpresionDeRecibo;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Quien puede ver el historial de impresion — y nadie puede tocarlo.
 *
 * ⚠️ Filament PERMITE lo que no tiene politica. Hoy este modelo no aparece en
 * ninguna tabla del panel, pero la politica va igual: el dia que alguien lo
 * agregue a una pestaña, va a salir con botones de editar y borrar si esto no
 * existe. Esa es la trampa del §9.E, y ya mordio antes.
 *
 * ═══ NO HAY PERMISOS PROPIOS ═══
 *
 * Quien puede ver el recibo ve cuantas veces se imprimio. Inventar un
 * `ViewAny:ImpresionDeRecibo` seria un permiso mas que alguien tendria que
 * acordarse de dar, para exactamente la misma decision.
 *
 * ═══ NI CREAR DESDE UNA PANTALLA ═══
 *
 * Una impresion se registra sola, dentro de la transaccion que arma el papel.
 * Un formulario que la creara suelta produciria una fila que dice que un
 * recibo se imprimio sin que nadie lo haya impreso.
 */
class ImpresionDeReciboPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Recibo');
    }

    public function view(AuthUser $authUser, ImpresionDeRecibo $impresion): bool
    {
        return $authUser->can('View:Recibo');
    }

    public function create(AuthUser $authUser): bool
    {
        return false;
    }

    public function update(AuthUser $authUser, ImpresionDeRecibo $impresion): bool
    {
        return false;
    }

    public function delete(AuthUser $authUser, ImpresionDeRecibo $impresion): bool
    {
        return false;
    }

    public function restore(AuthUser $authUser, ImpresionDeRecibo $impresion): bool
    {
        return false;
    }

    public function forceDelete(AuthUser $authUser, ImpresionDeRecibo $impresion): bool
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

    public function replicate(AuthUser $authUser, ImpresionDeRecibo $impresion): bool
    {
        return false;
    }

    public function reorder(AuthUser $authUser): bool
    {
        return false;
    }
}
