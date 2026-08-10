<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

/**
 * El archivo que subieron no sirve como foto 360 del lote.
 *
 * Los mensajes son para quien está de pie en el panel con la foto recién
 * exportada de la cámara, no para un log: dicen QUÉ pasa y QUÉ hacer. Una
 * foto que no es equirectangular, envuelta en la esfera, sale estirada y sin
 * ningún error — y nadie se entera hasta que la ve un cliente.
 */
final class Foto360InvalidaException extends GrupoOlympoException
{
    public static function porqueNoSePuedeLeer(): self
    {
        return new self('El archivo no es una imagen que se pueda leer. Probá con un JPEG o un PNG.');
    }

    public static function porDemasiadoAncha(int $ancho, int $maximo): self
    {
        return new self(
            "La foto es de {$ancho} píxeles de ancho y el máximo es {$maximo}. ".
            'Exportala desde la cámara en una medida menor.'
        );
    }

    /**
     * Un equirectangular es exactamente el doble de ancho que de alto.
     */
    public static function porqueNoEsEquirectangular(int $ancho, int $alto): self
    {
        return new self(
            "Esta foto mide {$ancho}×{$alto}, y una foto 360 tiene que ser el doble de ancha ".
            'que de alta (por ejemplo 6000×3000). Subí la que exporta la cámara 360, sin recortar.'
        );
    }

    public static function porqueEsteServidorNoPuede(): self
    {
        return new self('Este servidor no puede procesar imágenes: falta la extensión GD.');
    }
}
