<?php

declare(strict_types=1);

namespace App\Domain\Plano;

use App\Domain\Exceptions\ValueObjectInvalidoException;

/**
 * Validacion compartida de las medidas del plano.
 *
 * Vive en un trait y no copiada en cada value object porque el mensaje de
 * error tambien es contrato: explica POR QUE la medida va como string, y
 * dos copias de esa explicacion terminan diciendo cosas distintas.
 */
trait ValidaMedidas
{
    /**
     * @return numeric-string
     */
    private function medidaPositiva(string $campo, string $valor): string
    {
        $normalizado = $this->numerica($campo, $valor);

        if (bccomp($normalizado, '0', 6) <= 0) {
            throw ValueObjectInvalidoException::paraCampo(
                campo: $campo,
                valor: $valor,
                razon: 'Debe ser mayor que cero: un lote sin frente o sin fondo no es un lote.'
            );
        }

        return $normalizado;
    }

    /**
     * @return numeric-string
     */
    private function medidaNoNegativa(string $campo, string $valor): string
    {
        $normalizado = $this->numerica($campo, $valor);

        if (bccomp($normalizado, '0', 6) < 0) {
            throw ValueObjectInvalidoException::paraCampo(
                campo: $campo,
                valor: $valor,
                razon: 'No puede ser negativo.'
            );
        }

        return $normalizado;
    }

    /**
     * @return numeric-string
     */
    private function numerica(string $campo, string $valor): string
    {
        if (! is_numeric($valor)) {
            throw ValueObjectInvalidoException::paraCampo(
                campo: $campo,
                valor: $valor,
                razon: 'Debe ser un numero en formato string, por ejemplo "10.5000". '.
                       'El §8.3.1 prohibe float en las medidas que despues multiplican dinero.'
            );
        }

        return $valor;
    }

    private function enteroPositivo(string $campo, int $valor): void
    {
        if ($valor < 1) {
            throw ValueObjectInvalidoException::paraCampo(
                campo: $campo,
                valor: (string) $valor,
                razon: 'Debe ser mayor o igual a 1.'
            );
        }
    }
}
