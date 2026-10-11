<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Reportes\EstadoGeneral;
use App\Http\Controllers\Concerns\MembreteDelProyecto;
use App\Models\Gasto;
use App\Models\Proyecto;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;

/**
 * El estado general del proyecto, listo para el papel (10-oct-2026).
 *
 *     /documentos/estado-general/1
 *
 * Toda la vida del proyecto —lo que entró, lo que salió, lo que se le entregó
 * a cada socio— y hacia dónde va: lo que falta cobrar de lo vendido y lo que
 * falta vender. El cálculo está en `EstadoGeneral`; esto solo autoriza y arma
 * la hoja.
 *
 * ═══ QUIEN PUEDE VERLO ═══
 *
 * Las mismas dos llaves del estado mensual, y por la misma razón: dice cuánto
 * se gastó y cuánto se lleva cada dueño. Ver ESTE proyecto y ver gastos. El
 * receptor ve el proyecto pero no los gastos, así que recibe un 403.
 */
final class EstadoGeneralController
{
    use MembreteDelProyecto;

    public function __invoke(Proyecto $proyecto): View
    {
        Gate::authorize('view', $proyecto);
        Gate::authorize('viewAny', Gasto::class);

        return view('documentos.estado-general', [
            'general'         => EstadoGeneral::de($proyecto),
            'emisor'          => $this->emisorDe($proyecto),
            'logo'            => $this->logo(),
            'logoDelProyecto' => $proyecto->logoUrl(),
        ]);
    }
}
