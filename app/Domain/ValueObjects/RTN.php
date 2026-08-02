<?php

declare(strict_types=1);

namespace App\Domain\ValueObjects;

use App\Domain\Exceptions\ValueObjectInvalidoException;
use Stringable;

/**
 * Registro Tributario Nacional (Honduras).
 *
 * Formato según SAR: 14 dígitos numéricos.
 * Estructura:
 *   - Posiciones 1-4:  Código de departamento + municipio
 *   - Posiciones 5-8:  Año de emisión
 *   - Posiciones 9-13: Correlativo
 *   - Posición  14:    Dígito verificador
 *
 * Esta clase valida formato pero NO el dígito verificador (requeriría
 * algoritmo del SAR no público). Para validación oficial, integrar
 * con el web service del SAR.
 */
final readonly class RTN implements Stringable
{
    /**
     * Formato canónico del RTN según SAR: 14 dígitos numéricos.
     * Es constante porque (a) no cambia entre proyectos del grupo,
     * (b) los Value Objects deben ser auto-contenidos sin depender
     * del Service Container de Laravel (§7.5).
     */
    public const REGEX = '/^\d{14}$/';

    public const LONGITUD = 14;

    public function __construct(public string $valor)
    {
        if (! preg_match(self::REGEX, $valor)) {
            throw ValueObjectInvalidoException::paraCampo(
                campo: 'rtn',
                valor: $valor,
                razon: 'Debe tener exactamente 14 dígitos numéricos sin guiones ni espacios.'
            );
        }
    }

    public function departamento(): string
    {
        return substr($this->valor, 0, 4);
    }

    public function anioEmision(): int
    {
        return (int) substr($this->valor, 4, 4);
    }

    public function correlativo(): string
    {
        return substr($this->valor, 8, 5);
    }

    public function digitoVerificador(): string
    {
        return substr($this->valor, 13, 1);
    }

    /** Formato visual para reportes: 0801-1985-012345 */
    public function formateado(): string
    {
        return sprintf(
            '%s-%s-%s%s',
            substr($this->valor, 0, 4),
            substr($this->valor, 4, 4),
            substr($this->valor, 8, 5),
            substr($this->valor, 13, 1)
        );
    }

    public function igualA(self $otro): bool
    {
        return $this->valor === $otro->valor;
    }

    public function __toString(): string
    {
        return $this->valor;
    }
}
