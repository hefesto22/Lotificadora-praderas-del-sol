<?php

declare(strict_types=1);

namespace App\Domain\ValueObjects;

use App\Domain\Exceptions\ValueObjectInvalidoException;
use DateTimeImmutable;
use Stringable;

/**
 * Código de Autorización de Impresión (CAI) emitido por SAR Honduras.
 *
 * Formato: 32 caracteres hexadecimales en mayúsculas, agrupados en
 * 6 bloques separados por guiones:
 *   XXXXXX-XXXXXX-XXXXXX-XXXXXX-XXXXXX-XX
 *
 * Cada CAI tiene una fecha de vigencia (típicamente 12 meses según
 * config('honduras.sar.cai_dias_validez')).
 */
final readonly class CAI implements Stringable
{
    private const REGEX_FORMATO = '/^[A-F0-9]{6}-[A-F0-9]{6}-[A-F0-9]{6}-[A-F0-9]{6}-[A-F0-9]{6}-[A-F0-9]{2}$/';

    public function __construct(
        public string $valor,
        public DateTimeImmutable $vigenteDesde,
        public DateTimeImmutable $vigenteHasta,
    ) {
        $valorNormalizado = strtoupper(trim($valor));

        if (! preg_match(self::REGEX_FORMATO, $valorNormalizado)) {
            throw ValueObjectInvalidoException::paraCampo(
                campo: 'cai',
                valor: $valor,
                razon: 'Formato inválido. Esperado: XXXXXX-XXXXXX-XXXXXX-XXXXXX-XXXXXX-XX (hex mayúsculas).'
            );
        }

        if ($vigenteHasta <= $vigenteDesde) {
            throw ValueObjectInvalidoException::paraCampo(
                campo: 'vigencia',
                valor: $vigenteDesde->format('Y-m-d').' → '.$vigenteHasta->format('Y-m-d'),
                razon: 'La fecha de fin debe ser posterior a la fecha de inicio.'
            );
        }
    }

    public function estaVigente(?DateTimeImmutable $referencia = null): bool
    {
        $hoy = $referencia ?? new DateTimeImmutable;

        return $hoy >= $this->vigenteDesde && $hoy <= $this->vigenteHasta;
    }

    public function diasParaVencer(?DateTimeImmutable $referencia = null): int
    {
        $hoy = $referencia ?? new DateTimeImmutable;

        $diff = $hoy->diff($this->vigenteHasta);

        return $hoy > $this->vigenteHasta
            ? -1 * (int) $diff->days
            : (int) $diff->days;
    }

    public function __toString(): string
    {
        return $this->valor;
    }
}
