<?php

declare(strict_types=1);

namespace App\Support;

use GdImage;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use RuntimeException;

/**
 * Optimizador de imágenes para uploads del panel.
 *
 * Convierte imágenes raster (PNG, JPG, JPEG, GIF, WebP, BMP) a WebP con
 * compresión inteligente. Reduce típicamente el peso 25-35% sin
 * pérdida visual perceptible.
 *
 * NO toca SVG (vectorial, ya óptimo) ni .ico (formato específico
 * de favicon que browsers viejos esperan crudo).
 *
 * Implementado con GD nativo de PHP — no requiere dependencias externas
 * ni Intervention Image. GD viene instalado por defecto en cualquier
 * PHP moderno y soporta todos los formatos que necesitamos.
 *
 * Uso típico en un FileUpload de Filament:
 *
 *   FileUpload::make('logo_path')
 *       ->saveUploadedFileUsing(fn ($file) => ImageOptimizer::toWebp($file, 'branding'))
 */
final class ImageOptimizer
{
    /**
     * Convierte la imagen a WebP optimizado.
     * SVG y WebP se guardan sin tocar.
     *
     * @return string Ruta relativa al disco public (ej: branding/abc123.webp)
     */
    public static function toWebp(
        TemporaryUploadedFile $file,
        string $directory,
        int $quality = 85,
    ): string {
        $extension = strtolower((string) $file->getClientOriginalExtension());

        // SVG: vectorial, no se convierte. Se guarda tal cual.
        if ($extension === 'svg') {
            return self::guardarTalCual($file, $directory, 'svg');
        }

        $rutaRelativa = $directory.'/'.Str::random(20).'.webp';
        $rutaCompleta = Storage::disk('public')->path($rutaRelativa);
        self::asegurarDirectorio($rutaCompleta);

        $imagen = self::cargarImagen($file->getRealPath());
        self::preservarTransparencia($imagen);

        if (! imagewebp($imagen, $rutaCompleta, $quality)) {
            imagedestroy($imagen);

            throw new RuntimeException('No se pudo escribir el archivo WebP.');
        }

        imagedestroy($imagen);

        return $rutaRelativa;
    }

    /**
     * Procesa un favicon. Acepta PNG/JPG/WebP/SVG/ICO y los normaliza:
     *   - .ico y .svg → se guardan tal cual
     *   - Resto → se convierte a PNG cuadrado de 64x64 (compatible con todos
     *     los browsers, incluido el del Apple Touch Icon)
     *
     * @param positive-int $size
     */
    public static function toFavicon(
        TemporaryUploadedFile $file,
        string $directory,
        int $size = 64,
    ): string {
        $extension = strtolower((string) $file->getClientOriginalExtension());

        // ICO y SVG se guardan tal cual.
        if (in_array($extension, ['ico', 'svg'], true)) {
            return self::guardarTalCual($file, $directory, $extension, prefijo: 'favicon');
        }

        $rutaRelativa = $directory.'/favicon-'.Str::random(10).'.png';
        $rutaCompleta = Storage::disk('public')->path($rutaRelativa);
        self::asegurarDirectorio($rutaCompleta);

        $original = self::cargarImagen($file->getRealPath());
        $cuadrado = self::redimensionarCuadrado($original, $size);
        imagedestroy($original);

        if (! imagepng($cuadrado, $rutaCompleta, 9)) {
            imagedestroy($cuadrado);

            throw new RuntimeException('No se pudo escribir el favicon PNG.');
        }

        imagedestroy($cuadrado);

        return $rutaRelativa;
    }

    /**
     * Carga una imagen desde una ruta detectando su tipo MIME.
     */
    private static function cargarImagen(string $ruta): GdImage
    {
        $info = @getimagesize($ruta);

        if ($info === false) {
            throw new RuntimeException('Archivo no es una imagen válida.');
        }

        $imagen = match ($info[2]) {
            IMAGETYPE_PNG  => imagecreatefrompng($ruta),
            IMAGETYPE_JPEG => imagecreatefromjpeg($ruta),
            IMAGETYPE_GIF  => imagecreatefromgif($ruta),
            IMAGETYPE_WEBP => imagecreatefromwebp($ruta),
            IMAGETYPE_BMP  => imagecreatefrombmp($ruta),
            default        => false,
        };

        if ($imagen === false) {
            throw new RuntimeException('Formato de imagen no soportado.');
        }

        return $imagen;
    }

    /**
     * Asegura que la imagen mantiene transparencia (importante para PNG con fondo alfa).
     */
    private static function preservarTransparencia(GdImage $imagen): void
    {
        imagepalettetotruecolor($imagen);
        imagealphablending($imagen, true);
        imagesavealpha($imagen, true);
    }

    /**
     * Redimensiona la imagen a un cuadrado del tamaño dado, recortando los bordes
     * para mantener el centro (cover behavior).
     *
     * @param positive-int $tamano
     */
    private static function redimensionarCuadrado(GdImage $original, int $tamano): GdImage
    {
        if ($tamano < 1) {
            throw new RuntimeException("El tamaño del cuadrado debe ser positivo, recibido: {$tamano}");
        }

        $anchoOriginal = imagesx($original);
        $altoOriginal = imagesy($original);

        // Calcula el cuadrado más grande centrado dentro del original.
        $lado = min($anchoOriginal, $altoOriginal);
        $offsetX = (int) (($anchoOriginal - $lado) / 2);
        $offsetY = (int) (($altoOriginal - $lado) / 2);

        $cuadrado = imagecreatetruecolor($tamano, $tamano);

        if ($cuadrado === false) {
            throw new RuntimeException('No se pudo crear lienzo cuadrado.');
        }

        // Fondo transparente
        imagealphablending($cuadrado, false);
        imagesavealpha($cuadrado, true);
        $transparente = imagecolorallocatealpha($cuadrado, 0, 0, 0, 127);

        if ($transparente !== false) {
            imagefill($cuadrado, 0, 0, $transparente);
        }

        imagecopyresampled(
            $cuadrado,
            $original,
            0,
            0,
            $offsetX,
            $offsetY,
            $tamano,
            $tamano,
            $lado,
            $lado
        );

        return $cuadrado;
    }

    /**
     * Guarda el archivo original sin procesar (para SVG, ICO).
     */
    private static function guardarTalCual(
        TemporaryUploadedFile $file,
        string $directory,
        string $extension,
        string $prefijo = '',
    ): string {
        $nombre = ($prefijo !== '' ? $prefijo.'-' : '').Str::random(20);
        $rutaRelativa = $directory.'/'.$nombre.'.'.$extension;

        Storage::disk('public')->put(
            $rutaRelativa,
            (string) file_get_contents($file->getRealPath())
        );

        return $rutaRelativa;
    }

    private static function asegurarDirectorio(string $rutaCompleta): void
    {
        $dir = dirname($rutaCompleta);

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }
}
