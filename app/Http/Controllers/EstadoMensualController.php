<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Reportes\CierreDelMes;
use App\Models\BrandingSetting;
use App\Models\Facturacion;
use App\Models\Gasto;
use App\Models\Proyecto;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

/**
 * El estado mensual del proyecto, listo para el papel.
 *
 *     /documentos/estado-mensual/1?mes=2026-08
 *
 * ═══ HTML Y NO PDF, POR PEDIDO ═══
 *
 * Mauricio, 24-ago-2026: «que lo genere en html, ya él decide si lo imprime o
 * no, nada de generar pdf». Es el mismo molde del recibo y del estado de
 * cuenta: una hoja tamaño carta con su CSS adentro y un botón de imprimir que
 * usa la del navegador. Sin dependencias nuevas y sin un archivo que alguien
 * tenga que buscar en Descargas.
 *
 * ═══ 🔴 QUIEN PUEDE VERLO ═══
 *
 * Esta hoja dice cuánto se gastó y cuánto le toca a cada dueño del proyecto:
 * **no es del mostrador.** Se piden dos permisos que ya reparte `RoleSeeder`:
 * ver ESTE proyecto y ver gastos. La administradora tiene los dos; el receptor
 * ve el proyecto pero no los gastos, así que recibe un 403.
 *
 * ⚠️ **NO se pide `ViewAny:Socio`, y ahí me equivoqué la primera vez.** Ese
 * permiso existe en `SocioPolicy` pero **`RoleSeeder` no se lo da a nadie**:
 * los socios no son un Resource, son una pestaña del proyecto, y se
 * administran bajo el permiso del proyecto. Pedirlo era pedir una llave que no
 * está en el llavero de nadie — la administradora entraba y recibía 403 sin
 * ninguna explicación posible.
 *
 * La lección, que vale para todo permiso que uno vaya a exigir: **que exista
 * en la policy no quiere decir que alguien lo tenga.** Se comprueba en
 * `RoleSeeder`, que es quien los reparte.
 */
final class EstadoMensualController
{
    public function __invoke(Request $request, Proyecto $proyecto): View
    {
        Gate::authorize('view', $proyecto);
        Gate::authorize('viewAny', Gasto::class);

        return view('documentos.estado-mensual', [
            'cierre'          => CierreDelMes::de($proyecto, $this->mes($request)),
            'emisor'          => $this->emisorDe($proyecto),
            'logo'            => $this->logo(),
            'logoDelProyecto' => $proyecto->logoUrl(),
        ]);
    }

    // ─── Interno ──────────────────────────────────────────────────────

    /**
     * El mes que se pidió, o el corriente.
     *
     * ⚠️ `today()` de PHP y no una fecha de la base (§7.5.1). Y si el
     * parámetro viene con cualquier cosa, se cae al mes corriente en vez de
     * reventar: es una URL que alguien puede teclear a mano.
     */
    private function mes(Request $request): CarbonImmutable
    {
        $pedido = $request->query('mes');

        if (is_string($pedido) && preg_match('/^\d{4}-\d{2}$/', $pedido) === 1) {
            $mes = CarbonImmutable::createFromFormat('Y-m-d', $pedido.'-01');

            if ($mes instanceof CarbonImmutable) {
                return $mes->startOfMonth();
            }
        }

        return CarbonImmutable::parse(today()->toDateString())->startOfMonth();
    }

    /**
     * Quién emite, tal como sale impreso arriba de la hoja.
     *
     * Es la misma escalera del recibo y del estado de cuenta —facturación,
     * proyecto, config— y por la misma razón: con dos urbanizaciones, cada una
     * tiene su nombre y su dirección, y un solo membrete de config las
     * imprimiría iguales.
     *
     * @return array<string, string|null>
     */
    private function emisorDe(Proyecto $proyecto): array
    {
        $facturacion = $proyecto->facturacion;

        if ($facturacion instanceof Facturacion) {
            return $facturacion->comoEmisor();
        }

        $propio = $proyecto->comoEmisor();

        if ($propio['residencial'] !== null) {
            return $propio;
        }

        return $this->emisor();
    }

    /**
     * @return array<string, string|null>
     */
    private function emisor(): array
    {
        $datos = config('lotificadora.emisor');

        if (! is_array($datos)) {
            return [];
        }

        $limpio = [];

        foreach (['nombre', 'rtn', 'residencial', 'direccion', 'telefono'] as $clave) {
            $valor = $datos[$clave] ?? null;
            $limpio[$clave] = is_string($valor) && trim($valor) !== '' ? trim($valor) : null;
        }

        return $limpio;
    }

    /**
     * El logo de la lotificadora, copiado tal cual del estado de cuenta: se
     * comprueba que el archivo EXISTA antes de imprimir su URL, porque una
     * imagen rota arriba de un papel que va a una reunión de socios se nota
     * más que la falta de logo.
     */
    private function logo(): ?string
    {
        $ruta = BrandingSetting::current()->getAttribute('logo_path');

        if (! is_string($ruta) || trim($ruta) === '') {
            return null;
        }

        $disco = Storage::disk('public');

        return $disco->exists($ruta) ? $disco->url($ruta) : null;
    }
}
