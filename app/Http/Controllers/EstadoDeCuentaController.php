<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Ventas\EstadoDeCuenta;
use App\Models\BrandingSetting;
use App\Models\Facturacion;
use App\Models\Proyecto;
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
            'cuenta'          => EstadoDeCuenta::de($venta),
            'emisor'          => $this->emisorDe($this->proyectoDe($venta)),
            'logo'            => $this->logo(),
            'logoDelProyecto' => $this->proyectoDe($venta)?->logoUrl(),
        ]);
    }

    // ─── Interno ──────────────────────────────────────────────────────

    /**
     * El desarrollo al que pertenece esta venta: de ahí salen su logo y su
     * membrete.
     *
     * Se llega por el primer compromiso: una venta puede llevar varios
     * lotes, pero todos son del MISMO proyecto —lo verifica
     * `RegistroDeVentas` al activarla—, así que el primero alcanza.
     */
    private function proyectoDe(Venta $venta): ?Proyecto
    {
        $proyecto = $venta->compromisos()->with('proyecto')->first()?->proyecto;

        return $proyecto instanceof Proyecto ? $proyecto : null;
    }

    /**
     * Quién emite, tal como sale impreso arriba del papel.
     *
     * PRIMERO la facturación del desarrollo, la config solo de respaldo.
     * Hasta el 14-ago-2026 esto era únicamente `config/lotificadora.php`,
     * que es UNO para toda la instalación: con dos urbanizaciones —cada una
     * con su nombre, sus teléfonos y su dirección impresos en su propio
     * talonario— el mismo membrete salía en los dos papeles. Lo pidió
     * Mauricio mandando la foto del talonario de Praderas.
     *
     * El respaldo no es adorno: un proyecto sin facturación elegida sigue
     * imprimiendo igual que ayer.
     *
     * @return array<string, string|null>
     */
    private function emisorDe(?Proyecto $proyecto): array
    {
        $facturacion = $proyecto?->facturacion;

        /*
         * Tres fuentes, en este orden y por esta razon:
         *
         *  1. La FACTURACION, cuando el desarrollo factura con CAI. Ahi la
         *     direccion impresa es la del establecimiento, que es la del
         *     lugar desde donde se emite — no siempre donde esta el terreno.
         *  2. El PROYECTO, cuando emite recibo interno. Su nombre, su
         *     direccion de la pestaña Ubicacion y los telefonos que se le
         *     cargaron. Lo enderezo Mauricio el 14-ago-2026: un recibo de
         *     caja no necesita pasar por una facturacion.
         *  3. La CONFIG, de respaldo. Un proyecto al que todavia no le
         *     cargaron nada sigue imprimiendo como hasta ayer.
         */
        if ($facturacion instanceof Facturacion) {
            return $facturacion->comoEmisor();
        }

        if ($proyecto instanceof Proyecto) {
            $propio = $proyecto->comoEmisor();

            // Si el proyecto no tiene ni nombre util, no vale la pena: cae
            // en la config, que al menos trae el RTN de la lotificadora.
            if ($propio['residencial'] !== null) {
                return $propio;
            }
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
