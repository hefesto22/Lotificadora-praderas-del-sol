<?php

declare(strict_types=1);

namespace App\Domain\Archivos;

use Filament\Forms\Components\BaseFileUpload;
use GdImage;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Lo que se sube se guarda EN WEBP, y pesando lo que tiene que pesar.
 *
 * ═══ POR QUE ═══
 *
 * Una foto de una factura tomada con el teléfono llega en 2 a 5 MB y con
 * 4000 × 3000 píxeles. Para leer lo que dice el papel sobra con una fracción
 * de eso: convertida a WebP y con el lado largo en 2,400 px, la misma foto
 * queda en 250–400 KB. **Es entre seis y diez veces menos**, y no se pierde
 * una letra.
 *
 * El disco no es la razón principal —el VPS tiene 200 GB y el expediente
 * entero de Praderas no llega a uno—. La razón es la pantalla: quien abre el
 * expediente de un cliente en Cucuyagua está esperando que carguen fotos por
 * una conexión que no es la de la oficina. Seis veces menos peso es seis veces
 * menos espera.
 *
 * ═══ LA REGLA QUE MANDA SOBRE TODAS ═══
 *
 * 🔴 **Un comprobante NUNCA se pierde por optimizarlo.** Si GD no está
 * compilado con WebP, si la imagen viene corrupta, si es tan grande que no
 * entra en memoria, o si el WebP terminara pesando MÁS que el original, este
 * servicio **se hace a un lado** y deja que Filament guarde el archivo tal
 * como llegó. Un expediente con una foto pesada sirve; un expediente con una
 * foto que no se pudo guardar, no.
 *
 * Por eso todo el camino de conversión devuelve `null` ante cualquier duda, y
 * `null` significa «guardalo como siempre».
 *
 * ═══ LO QUE NO TOCA ═══
 *
 * - **Los PDF.** No son imágenes; se guardan intactos. Es la mitad de lo que
 *   entra al expediente y no hay nada que optimizar ahí.
 * - **Lo que ya viene en WebP.** Recomprimir un WebP pierde calidad y no gana
 *   peso: es la peor combinación posible.
 * - **Las imágenes chicas.** Si ya mide menos que el lado máximo, no se
 *   agranda: estirar una foto no le agrega información, solo bytes.
 */
final class GuardadoDeArchivos
{
    /**
     * Calidad del WebP, de 0 a 100.
     *
     * 82 es el punto donde una factura fotografiada sigue siendo legible sin
     * artefactos visibles, que es el criterio que importa acá — no el de una
     * foto de producto. Bajar a 70 ahorra poco y empieza a ensuciar la letra
     * chica de un RTN.
     */
    public const int CALIDAD = 82;

    /**
     * Lado largo máximo, en píxeles.
     *
     * 2,400 px sobre una hoja carta dan unos 280 DPI: más resolución de la que
     * usa un escáner de oficina, y muy por encima de lo necesario para leer
     * una identidad o una factura. Se eligió con margen a propósito, porque
     * acá se está tocando un documento legal y lo barato es equivocarse hacia
     * arriba.
     */
    public const int LADO_MAXIMO = 2400;

    /**
     * Tope de píxeles que se intenta procesar.
     *
     * GD descomprime la imagen entera en memoria a 4 bytes por píxel: 40
     * millones de píxeles son ~160 MB, y de ahí para arriba el riesgo de
     * tumbar el proceso es real. Una foto de teléfono actual anda por los 12
     * millones, así que esto solo frena panorámicas y escaneos gigantes — que
     * se guardan tal cual, sin drama.
     */
    private const int PIXELES_MAXIMOS = 40_000_000;

    /**
     * Guarda el archivo subido, en WebP si se puede y tal cual si no.
     *
     * Se engancha en `FileUpload::saveUploadedFileUsing()`. Devuelve la ruta
     * relativa dentro del disco, que es lo que se guarda en la columna.
     */
    public function guardar(BaseFileUpload $componente, TemporaryUploadedFile $archivo): ?string
    {
        $webp = $this->convertirAWebp($archivo);

        // Cualquier duda: el camino de siempre, con el archivo original.
        if ($webp === null) {
            return $componente->saveUploadedFile($archivo);
        }

        $ruta = trim($componente->getDirectory().'/'.Str::ulid()->toString().'.webp', '/');

        $componente->getDisk()->put($ruta, $webp);

        if ($componente->getVisibility() === 'public') {
            rescue(fn (): bool => $componente->getDisk()->setVisibility($ruta, 'public'), report: false);
        }

        // El temporal de Livewire no se borra solo cuando el guardado no pasó
        // por `saveUploadedFile()`.
        rescue(fn (): mixed => $archivo->delete(), report: false);

        return $ruta;
    }

    /**
     * Cuánto pesa de verdad un archivo ya guardado.
     *
     * Se pregunta al disco DESPUÉS de convertir, que es la única forma de que
     * la columna diga la verdad: el tamaño del archivo que subió el navegador
     * es el del original, y después de la conversión ese número sobra por seis.
     *
     * Devuelve `null` cuando el archivo no está —ruta vacía, disco falso de un
     * test, archivo borrado a mano—. Quien llama decide qué hacer con eso; lo
     * que no hace es escribir un cero que después nadie sabe interpretar.
     */
    public static function pesoEnDisco(string $disco, mixed $ruta): ?int
    {
        if (! is_string($ruta) || trim($ruta) === '') {
            return null;
        }

        $tamanio = rescue(fn (): int => Storage::disk($disco)->size($ruta), null, report: false);

        return is_int($tamanio) ? $tamanio : null;
    }

    // ─── Interno ──────────────────────────────────────────────────────

    /**
     * Los bytes del WebP, o `null` si no hay que convertir o no se pudo.
     */
    private function convertirAWebp(TemporaryUploadedFile $archivo): ?string
    {
        // GD puede estar sin el soporte compilado. Se pregunta y punto: no es
        // un error, es una instalación distinta.
        if (! function_exists('imagewebp') || ! function_exists('imagecreatefromjpeg')) {
            return null;
        }

        // Sin `|false` en el tipo del closure: `getRealPath()` de Livewire
        // siempre devuelve string, y declarar un false que nunca ocurre es un
        // error de PHPStan (`return.unusedType`). El false sigue siendo el
        // valor de RESCATE, que es otra cosa.
        $ruta = rescue(fn (): string => $archivo->getRealPath(), false, report: false);

        if (! is_string($ruta) || $ruta === '') {
            return null;
        }

        $medidas = rescue(fn (): array|false => getimagesize($ruta), false, report: false);

        // `getimagesize` devuelve false para un PDF: ese es el camino normal.
        if (! is_array($medidas)) {
            return null;
        }

        $ancho = (int) ($medidas[0] ?? 0);
        $alto = (int) ($medidas[1] ?? 0);
        $tipo = (int) ($medidas[2] ?? 0);

        if ($ancho < 1 || $alto < 1 || $ancho * $alto > self::PIXELES_MAXIMOS) {
            return null;
        }

        $imagen = $this->abrir($ruta, $tipo);

        if (! $imagen instanceof GdImage) {
            return null;
        }

        $imagen = $this->achicar($imagen, $ancho, $alto);

        $bytes = $this->aBytes($imagen);

        if ($bytes === null) {
            return null;
        }

        /*
         * Si el WebP pesa MÁS que el original, se descarta. Pasa de verdad:
         * una captura de pantalla o un PNG chico ya vienen bien comprimidos, y
         * convertirlos los engorda. Optimizar para atrás no es optimizar.
         */
        $original = rescue(fn (): int|false => filesize($ruta), false, report: false);

        if (is_int($original) && $original > 0 && strlen($bytes) >= $original) {
            return null;
        }

        return $bytes;
    }

    private function abrir(string $ruta, int $tipo): ?GdImage
    {
        /*
         * WEBP no está en la lista a propósito: recomprimir un WebP pierde
         * calidad sin ganar peso. GIF tampoco — puede estar animado, y GD se
         * quedaría con el primer cuadro, que es perder información sin avisar.
         */
        $imagen = match ($tipo) {
            IMAGETYPE_JPEG => rescue(fn (): GdImage|false => imagecreatefromjpeg($ruta), false, report: false),
            IMAGETYPE_PNG  => rescue(fn (): GdImage|false => imagecreatefrompng($ruta), false, report: false),
            default        => false,
        };

        return $imagen instanceof GdImage ? $imagen : null;
    }

    private function achicar(GdImage $imagen, int $ancho, int $alto): GdImage
    {
        $lado = max($ancho, $alto);

        // Ya mide menos: agrandarla no le agrega información, solo bytes.
        if ($lado <= self::LADO_MAXIMO) {
            return $imagen;
        }

        $factor = self::LADO_MAXIMO / $lado;

        $escalada = imagescale(
            $imagen,
            max(1, (int) round($ancho * $factor)),
            max(1, (int) round($alto * $factor)),
        );

        if (! $escalada instanceof GdImage) {
            return $imagen;
        }

        return $escalada;
    }

    private function aBytes(GdImage $imagen): ?string
    {
        /*
         * Un PNG con transparencia la conserva —WebP la soporta— pero hay que
         * pedirlo: sin estas dos líneas el fondo transparente sale negro.
         */
        imagepalettetotruecolor($imagen);
        imagealphablending($imagen, false);
        imagesavealpha($imagen, true);

        ob_start();
        $ok = rescue(fn (): bool => imagewebp($imagen, null, self::CALIDAD), false, report: false);
        $bytes = ob_get_clean();

        if ($ok !== true || ! is_string($bytes) || $bytes === '') {
            return null;
        }

        return $bytes;
    }
}
