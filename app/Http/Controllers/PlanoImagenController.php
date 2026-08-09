<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Enums\EstadoLote;
use App\Domain\Plano\PlanoPublico;
use App\Domain\Plano\SelloDelPlano;
use App\Models\Proyecto;
use GdImage;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * El dibujo que WhatsApp muestra cuando alguien pega el link.
 *
 * ═══ POR QUE ESTO EXISTE ═══
 *
 * El plano publico se manda por WhatsApp: ese es el canal, no una idea. Y en
 * WhatsApp un link sin `og:image` llega como una linea de texto azul que nadie
 * abre. Con imagen llega una tarjeta con el plano del proyecto adentro, y ahi
 * el cliente ya vio de que se trata antes de decidir si toca.
 *
 * Es la unica pieza de todo el sistema cuyo trabajo es que alguien haga clic.
 *
 * ═══ 🔴 SE DIBUJA DESDE `PlanoPublico`, NO DESDE EL PLANO DEL PANEL ═══
 *
 * Misma lista blanca que la pagina. Parece obvio hasta que se piensa que una
 * imagen «no muestra texto»: el arreglo del panel trae el nombre del comprador
 * y el valor pactado, y el dia que alguien quiera escribir algo encima —«68
 * lotes disponibles»— tendria esos campos a mano. No los tiene.
 *
 * ═══ SIN TEXTO ADENTRO, Y ES A PROPOSITO ═══
 *
 * GD sin tipografia embebida solo sabe escribir con una fuente de mapa de bits
 * de nueve pixeles de ancho: agrandada a 1200 se ve como un fax. Meter un .ttf
 * al repositorio para escribir dos palabras es peso y licencia para algo que
 * WhatsApp YA muestra al lado de la imagen, sacado de `og:title` y
 * `og:description`. La imagen dibuja; las palabras las pone el HTML.
 *
 * ═══ SE DIBUJA AL DOBLE Y SE ACHICA ═══
 *
 * GD no antialiasea ni poligonos rellenos ni lineas gruesas. Dibujar a 2400 y
 * bajar a 1200 con `imagecopyresampled` suaviza todos los bordes de una, que
 * en un plano de 301 lotes pegados es la diferencia entre un dibujo y una
 * escalera de pixeles.
 *
 * ═══ SEIS HORAS DE CACHE ═══
 *
 * Mas que los cinco minutos de la pagina, y no por descuido: WhatsApp cachea la
 * vista previa de un link por su cuenta y por bastante mas que eso. Regenerar
 * el PNG cada cinco minutos seria trabajo que nadie llega a ver.
 */
final class PlanoImagenController
{
    /** Lo que WhatsApp, Facebook y Telegram esperan de una tarjeta grande. */
    private const int ANCHO = 1200;

    private const int ALTO = 630;

    /** Aire alrededor del dibujo, en pixeles de la imagen final. */
    private const int MARGEN = 26;

    /** Cuanto se dibuja de mas antes de achicar. Ver el docblock. */
    private const int SUPERMUESTREO = 2;

    private const int HORAS = 6;

    /**
     * ¿Se puede generar la imagen en este servidor?
     *
     * Se pregunta en dos lados: aca, para contestar 404 en vez de reventar, y
     * en `PlanoPublicoController`, para no publicar un `og:image` que apunta a
     * un 404 — una tarjeta rota se ve peor que una sin imagen.
     */
    public static function disponible(): bool
    {
        return extension_loaded('gd') && function_exists('imagecreatetruecolor');
    }

    public function __invoke(PlanoPublico $plano, SelloDelPlano $sello, string $slug): Response
    {
        if (! self::disponible()) {
            abort(404);
        }

        $proyecto = Proyecto::query()
            ->where('slug', $slug)
            ->where('activo', true)
            ->where('plano_publico', true)
            ->first();

        if (! $proyecto instanceof Proyecto) {
            abort(404);
        }

        $marca = $sello->para($proyecto);

        $clave = 'plano-publico-imagen:'.$proyecto->getKey().':'.$marca;
        $png = Cache::get($clave);

        if (! is_string($png) || $png === '') {
            $datos = $plano->para($proyecto);

            /*
             * ⚠️ Se pregunta por `hayGeometria` y NO por el encuadre.
             * `PlanoDelProyecto` devuelve «0 0 100 100» cuando el proyecto no
             * tiene un solo lote dibujado, y ese viewBox se lee perfecto: la
             * imagen salia en blanco y con codigo 200.
             */
            $png = $datos['hayGeometria']
                ? $this->dibujar($datos['viewBox'], $datos['lotes'], $datos['calles'])
                : '';

            /*
             * Solo se cachea lo que sirve. Antes era imprescindible: la clave
             * era el `updated_at` del proyecto, dibujar los lotes no lo tocaba,
             * y el vacio se quedaba pegado seis horas. Hoy `SelloDelPlano` mira
             * tambien `lotes` y la clave cambia sola — el guardia se queda
             * igual, porque «no guardar un fracaso» sigue siendo cierto sin
             * depender de como se arme la clave.
             */
            if ($png !== '') {
                Cache::put($clave, $png, now()->addHours(self::HORAS));
            }
        }

        // Proyecto sin dibujar, o un viewBox que no se pudo leer. 404 y no
        // una imagen vacia: una tarjeta con un rectangulo blanco adentro se
        // ve peor que una tarjeta sin imagen.
        if ($png === '') {
            abort(404);
        }

        return response($png, 200, [
            'Content-Type'  => 'image/png',
            'Cache-Control' => 'public, max-age='.(self::HORAS * 3600),
        ]);
    }

    // ─── Interno ──────────────────────────────────────────────────────

    /**
     * @param list<array{estado: string, puntos: string}> $lotes
     * @param list<array{esArea: bool, ancho: float, puntos: string}> $calles
     */
    private function dibujar(string $viewBox, array $lotes, array $calles): string
    {
        $caja = $this->encuadre($viewBox);

        if ($caja === null) {
            return '';
        }

        $escala = self::SUPERMUESTREO;
        $ancho = self::ANCHO * $escala;
        $alto = self::ALTO * $escala;
        $margen = self::MARGEN * $escala;

        $lienzo = imagecreatetruecolor($ancho, $alto);

        if (! $lienzo instanceof GdImage) {
            return '';
        }

        /*
         * El mismo papel cuadriculado del fondo de la pagina. No es adorno:
         * hace que la tarjeta de WhatsApp se reconozca como «el plano» de un
         * vistazo, y le da escala al dibujo cuando el proyecto es chico.
         */
        imagefilledrectangle($lienzo, 0, 0, $ancho, $alto, $this->color($lienzo, '#f8fafc'));

        $cuadricula = $this->color($lienzo, '#e8edf3');
        $paso = 24 * $escala;

        for ($x = 0; $x < $ancho; $x += $paso) {
            imageline($lienzo, $x, 0, $x, $alto, $cuadricula);
        }

        for ($y = 0; $y < $alto; $y += $paso) {
            imageline($lienzo, 0, $y, $ancho, $y, $cuadricula);
        }

        // El zoom que hace entrar el dibujo entero, respetando la proporcion.
        $zoom = min(($ancho - $margen * 2) / $caja[2], ($alto - $margen * 2) / $caja[3]);
        $dx = ($ancho - $caja[2] * $zoom) / 2 - $caja[0] * $zoom;
        $dy = ($alto - $caja[3] * $zoom) / 2 - $caja[1] * $zoom;

        $asfalto = $this->color($lienzo, '#e4e4e7');

        foreach ($calles as $calle) {
            $puntos = $this->enPixeles($calle['puntos'], $zoom, $dx, $dy);

            if ($calle['esArea']) {
                if (count($puntos) >= 6) {
                    imagefilledpolygon($lienzo, $puntos, $asfalto);
                }

                continue;
            }

            /*
             * La calle dibujada a mano es un EJE con ancho, asi que se pinta
             * como trazo grueso — igual que en el SVG de la pagina. Sin el
             * grosor, un boulevard de 16 varas seria una linea de un pixel.
             */
            imagesetthickness($lienzo, max((int) round($calle['ancho'] * $zoom), 1));

            for ($i = 0; $i + 3 < count($puntos); $i += 2) {
                imageline($lienzo, $puntos[$i], $puntos[$i + 1], $puntos[$i + 2], $puntos[$i + 3], $asfalto);
            }
        }

        imagesetthickness($lienzo, max((int) round($escala * 1.5), 1));

        foreach ($lotes as $lote) {
            $puntos = $this->enPixeles($lote['puntos'], $zoom, $dx, $dy);

            if (count($puntos) < 6) {
                continue;
            }

            $estado = EstadoLote::tryFrom($lote['estado']) ?? EstadoLote::Disponible;

            imagefilledpolygon($lienzo, $puntos, $this->color($lienzo, $estado->relleno()));
            imagepolygon($lienzo, $puntos, $this->color($lienzo, $estado->borde()));
        }

        return $this->comoPng($lienzo, $ancho, $alto);
    }

    /**
     * El viewBox del plano, leido a cuatro numeros.
     *
     * @return array{float, float, float, float}|null
     */
    private function encuadre(string $viewBox): ?array
    {
        $partes = preg_split('/[\s,]+/', trim($viewBox));

        if ($partes === false || count($partes) !== 4) {
            return null;
        }

        $numeros = array_map(static fn (string $parte): float => (float) $parte, $partes);

        // Ancho o alto en cero: el proyecto no tiene nada dibujado y no hay
        // imagen que hacer. Es el `VIEWBOX_VACIO` de PlanoDelProyecto o peor.
        if ($numeros[2] <= 0.0 || $numeros[3] <= 0.0) {
            return null;
        }

        return [$numeros[0], $numeros[1], $numeros[2], $numeros[3]];
    }

    /**
     * «0,0 10,0 10,25» → [x1, y1, x2, y2, ...] en pixeles, que es como GD
     * come los poligonos desde PHP 8.
     *
     * @return list<int>
     */
    private function enPixeles(string $puntos, float $zoom, float $dx, float $dy): array
    {
        $pares = preg_split('/\s+/', trim($puntos));

        if ($pares === false) {
            return [];
        }

        $planos = [];

        foreach ($pares as $par) {
            $xy = explode(',', $par);

            if (count($xy) !== 2) {
                continue;
            }

            $planos[] = (int) round((float) $xy[0] * $zoom + $dx);
            $planos[] = (int) round((float) $xy[1] * $zoom + $dy);
        }

        return $planos;
    }

    /**
     * Achica al tamaño final y devuelve los bytes del PNG.
     *
     * `imagecopyresampled` y no `imagecopyresized`: el primero promedia los
     * pixeles y el segundo los saltea, que es justamente el escalonado que se
     * estaba tratando de evitar.
     */
    private function comoPng(GdImage $grande, int $ancho, int $alto): string
    {
        $final = imagecreatetruecolor(self::ANCHO, self::ALTO);

        if (! $final instanceof GdImage) {
            return '';
        }

        imagecopyresampled($final, $grande, 0, 0, 0, 0, self::ANCHO, self::ALTO, $ancho, $alto);

        ob_start();
        imagepng($final, null, 7);
        $bytes = ob_get_clean();

        return is_string($bytes) ? $bytes : '';
    }

    /**
     * «#b8ead0» → el entero que GD entiende.
     */
    private function color(GdImage $lienzo, string $hex): int
    {
        $limpio = ltrim($hex, '#');

        if (strlen($limpio) !== 6) {
            $limpio = '000000';
        }

        /*
         * El `max(0, min(255, ...))` no es paranoia decorativa: `substr()`
         * puede devolver menos de dos caracteres, y GD exige que cada canal
         * esté entre 0 y 255 — se lo dice a PHPStan y se lo garantiza en
         * runtime con la misma línea.
         */
        $indice = imagecolorallocate(
            $lienzo,
            max(0, min(255, (int) hexdec(substr($limpio, 0, 2)))),
            max(0, min(255, (int) hexdec(substr($limpio, 2, 2)))),
            max(0, min(255, (int) hexdec(substr($limpio, 4, 2)))),
        );

        // La paleta de una imagen truecolor no se llena nunca, pero si
        // alguna vez devolviera false, negro es mejor que un fatal.
        return $indice === false ? 0 : $indice;
    }
}
