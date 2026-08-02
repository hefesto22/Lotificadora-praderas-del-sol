<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

/**
 * Lanzada cuando un Value Object recibe un valor que viola
 * sus invariantes (RTN con menos de 14 dígitos, Monto negativo,
 * CAI con formato inválido, etc.).
 *
 * Es la única excepción que pueden lanzar los constructores de
 * Value Objects, lo que mantiene el contrato predecible (§7.5, §7.7).
 */
final class ValueObjectInvalidoException extends GrupoOlympoException
{
    public static function paraCampo(string $campo, string $valor, string $razon): self
    {
        return new self("Valor inválido para {$campo}: '{$valor}'. {$razon}");
    }
}
