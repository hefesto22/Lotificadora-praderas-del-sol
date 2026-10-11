<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Models\BrandingSetting;
use App\Models\Facturacion;
use App\Models\Proyecto;
use Illuminate\Support\Facades\Storage;

/**
 * Quién emite una hoja del proyecto, y su logo.
 *
 * Vivía adentro de `EstadoMensualController`. El 10-oct-2026 nació el estado
 * general del proyecto, con el mismo membrete, y dos copias de la escalera
 * del emisor son dos lugares donde un día cambia una sola.
 */
trait MembreteDelProyecto
{
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
