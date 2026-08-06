<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Ventas\EstadoDeCuenta;
use App\Models\BrandingSetting;
use App\Models\Venta;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

/**
 * El estado de cuenta del expediente, listo para el papel.
 *
 * ═══ POR QUE ESTE NO REGISTRA LA IMPRESION Y EL RECIBO SI ═══
 *
 * Un recibo lleva correlativo y ACREDITA un pago: dos papeles con el mismo
 * número pueden hacerse pasar por dos cobros, y por eso cada salida queda
 * anotada y de la segunda en adelante dice COPIA. Un estado de cuenta no
 * acredita nada —es una foto del saldo a una fecha— así que sacar dos no crea
 * ningún riesgo, y anotar cada vez que alguien mira un saldo sería una tabla
 * que crece sin contestar ninguna pregunta.
 *
 * Lo que sí lleva es la FECHA DE CORTE bien visible: el mismo expediente
 * impreso mañana dice otra cosa, y sin la fecha al lado parecería que el
 * documento cambió solo.
 *
 * ═══ QUIEN PUEDE ═══
 *
 * `View:Venta`, el mismo permiso con que se abre el expediente. El receptor lo
 * tiene: el cliente llega al mostrador y lo pide, y hacerlo esperar a la
 * administradora para ver su propio saldo no tiene sentido.
 */
final class EstadoDeCuentaController
{
    public function __invoke(Request $request, Venta $venta): View
    {
        Gate::authorize('view', $venta);

        return view('documentos.estado-de-cuenta', [
            'cuenta' => EstadoDeCuenta::de($venta),
            'emisor' => $this->emisor(),
            'logo'   => $this->logo(),
        ]);
    }

    // ─── Interno ──────────────────────────────────────────────────────

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
