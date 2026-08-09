<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Plano\PlanoPublico;
use App\Domain\Plano\SelloDelPlano;
use App\Models\BrandingSetting;
use App\Models\Proyecto;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * El plano que el vendedor manda por WhatsApp. Sin login.
 *
 * ═══ TRES CONDICIONES, Y LAS TRES DAN 404 ═══
 *
 * El proyecto tiene que existir, estar activo y tener el plano publico
 * encendido. Las tres fallan igual —404, «no existe»— y no con un 403 que
 * diria «existe pero no podes verlo»: en una pagina abierta a internet, la
 * diferencia entre las dos respuestas le confirma a cualquiera que el
 * proyecto existe.
 *
 * ═══ SE CACHEA, Y NO POR VELOCIDAD ═══
 *
 * Es la unica URL de este sistema que puede recibir tráfico de gente que no
 * conocemos: un link que circula en un grupo de WhatsApp son cien aperturas
 * en un minuto. Sin caché, cada una arma el plano de 301 lotes y cotiza cada
 * medida contra cada plan.
 *
 * Cinco minutos, pero como red y no como demora: la clave la arma
 * `SelloDelPlano` con el proyecto, sus lotes y sus planes de pago, asi que
 * vender un lote o cambiar un precio se ve **al instante**. Los cinco minutos
 * quedan por si algun dia cambia algo que ninguna de esas tres tablas registra.
 *
 * ⚠️ Lo que se cachea es SOLO el arreglo de `PlanoPublico`, que ya pasó por
 * la lista blanca. Nunca modelos: el §Redis del catálogo — un modelo Eloquent
 * guardado en caché vuelve como `__PHP_Incomplete_Class` y tumba la página.
 */
final class PlanoPublicoController
{
    /** Lo que dura el plano en caché. Ver el docblock. */
    private const int MINUTOS = 5;

    public function __invoke(PlanoPublico $plano, SelloDelPlano $sello, string $slug): View
    {
        $proyecto = Proyecto::query()
            ->where('slug', $slug)
            ->where('activo', true)
            ->where('plano_publico', true)
            ->first();

        if (! $proyecto instanceof Proyecto) {
            abort(404);
        }

        /*
         * La huella de todo lo que la página muestra — ver `SelloDelPlano`.
         *
         * La clave era solo `proyectos.updated_at`, y **vender un lote no toca
         * esa fila**: la administradora vendía, abría el link para comprobar,
         * y seguía viendo el lote verde hasta cinco minutos después.
         */
        $marca = $sello->para($proyecto);

        /** @var array<string, mixed> $datos */
        $datos = Cache::remember(
            'plano-publico:'.$proyecto->getKey().':'.$marca,
            now()->addMinutes(self::MINUTOS),
            fn (): array => $plano->para($proyecto),
        );

        return view('publico.plano', [
            'plano'    => $datos,
            'imagen'   => $this->imagen($proyecto, $datos),
            'proyecto' => $proyecto,
            'whatsapp' => $this->whatsapp($proyecto),
            'empresa'  => $this->empresa(),
            'logo'     => $this->logo(),
        ]);
    }

    // ─── Interno ──────────────────────────────────────────────────────

    /**
     * La miniatura que WhatsApp muestra al lado del link, o null.
     *
     * Null en dos casos, y en los dos es mejor que nada: cuando el servidor no
     * tiene GD —un `og:image` que da 404 deja una tarjeta rota, que se ve peor
     * que una tarjeta sin imagen— y cuando el proyecto todavia no esta
     * dibujado, porque un rectangulo vacio no invita a nadie a tocar.
     *
     * @param array<string, mixed> $datos
     */
    private function imagen(Proyecto $proyecto, array $datos): ?string
    {
        if (! PlanoImagenController::disponible() || ($datos['hayGeometria'] ?? false) !== true) {
            return null;
        }

        return route('plano.imagen', ['slug' => $proyecto->getAttribute('slug')]);
    }

    /**
     * El número en el formato que espera `wa.me`: solo dígitos, con el país.
     *
     * Honduras es +504 y sus números son de ocho dígitos, así que un número
     * de ocho se completa solo. Si ya viene con código de país —porque el
     * vendedor lo guardó así, o porque la lotificadora es de otro lado— se
     * respeta tal cual: este producto no se vende únicamente acá.
     *
     * Null cuando no hay número cargado. La página no muestra el botón, que
     * es preferible a mandar al cliente a un chat que nadie lee.
     */
    private function whatsapp(Proyecto $proyecto): ?string
    {
        $crudo = $proyecto->getAttribute('whatsapp');

        if (! is_string($crudo)) {
            return null;
        }

        $digitos = preg_replace('/\D+/', '', $crudo) ?? '';

        if ($digitos === '') {
            return null;
        }

        return strlen($digitos) === 8 ? '504'.$digitos : $digitos;
    }

    /**
     * Quién vende, para el encabezado y el pie.
     *
     * Del mismo `config('lotificadora.emisor')` que firma el recibo y el
     * estado de cuenta: si el papel y la página dijeran nombres distintos, el
     * cliente tendría razón en desconfiar.
     *
     * @return array<string, string|null>
     */
    private function empresa(): array
    {
        $datos = config('lotificadora.emisor');

        if (! is_array($datos)) {
            return [];
        }

        $limpio = [];

        foreach (['nombre', 'residencial', 'direccion', 'telefono'] as $clave) {
            $valor = $datos[$clave] ?? null;
            $limpio[$clave] = is_string($valor) && trim($valor) !== '' ? trim($valor) : null;
        }

        return $limpio;
    }

    /**
     * El mismo logo del panel y de los documentos.
     *
     * `exists()` antes de la URL: un logo borrado del disco dejaría una
     * imagen rota en la cara del cliente, y acá no hay nadie del equipo
     * mirando para avisarlo.
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
