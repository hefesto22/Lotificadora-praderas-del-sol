<?php

declare(strict_types=1);

namespace App\Domain\Plano\Dxf;

use App\Domain\Exceptions\GrupoOlympoException;

final class ArchivoDxfInvalidoException extends GrupoOlympoException
{
    public static function porParDesalineado(int $linea, string $encontrado): self
    {
        $muestra = mb_substr($encontrado, 0, 40);

        return new self(
            "El archivo no respeta el formato DXF a partir de la linea {$linea}: se esperaba un ".
            "codigo de grupo numerico y se encontro \"{$muestra}\". ".
            'Si el archivo es DWG hay que exportarlo a DXF ASCII desde AutoCAD; '.
            'el DXF binario tampoco se admite.'
        );
    }

    public static function porArchivoVacio(): self
    {
        return new self('El archivo esta vacio o no contiene un solo par de codigo/valor legible.');
    }

    public static function porFaltarEntidades(): self
    {
        return new self(
            'El archivo se leyo bien pero no tiene seccion ENTITIES con geometria. '.
            'Puede ser un DXF de solo definiciones, o el dibujo puede estar completo '.
            'dentro de bloques que este importador todavia no expande.'
        );
    }
}
