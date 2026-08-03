<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

/**
 * El generador no pudo emitir los lotes pedidos.
 *
 * Siempre falla ANTES de crear nada. Una tanda a medias dejaria el bloque
 * con lotes numerados salteados y sin forma de saber cuales quedaron
 * afuera, que es peor que no haber generado.
 */
final class GeneracionDeLotesException extends GrupoOlympoException
{
    /**
     * @param list<string> $numeros
     */
    public static function porNumerosDuplicados(string $bloque, array $numeros): self
    {
        $muestra = implode(', ', array_slice($numeros, 0, 10));
        $resto = count($numeros) - 10;
        $sufijo = $resto > 0 ? " y {$resto} mas" : '';

        return new self(
            "El bloque {$bloque} ya tiene los lotes {$muestra}{$sufijo}. ".
            'Cambia el numero inicial o borra los lotes existentes antes de generar: '.
            'no se crea ninguno para no dejar la numeracion incompleta.'
        );
    }

    public static function porTandaDemasiadoGrande(int $pedidos, int $maximo): self
    {
        return new self(
            "Se pidieron {$pedidos} lotes en una sola tanda y el maximo es {$maximo}. ".
            'Casi siempre es un cero de mas en filas o columnas. Si de verdad son '.
            'tantos, genera el plano por bloques.'
        );
    }
}
