<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * Cómo entra el dinero (R11).
 *
 * Son EXACTAMENTE estas tres. **Cheque no se recibe** — la contratante lo dejó
 * sin marcar a propósito y no se agrega «por si acaso»: una forma de pago que
 * el sistema ofrece es una forma de pago que alguien va a usar.
 *
 * En transferencia y depósito el **número de referencia es obligatorio**: sin
 * él no hay cómo cruzar el recibo contra el estado de cuenta del banco. En
 * efectivo no aplica, porque no hay nada que cruzar.
 *
 * La lista es la fuente de verdad: la migración arma su CHECK a partir de
 * `valores()`, así que la base y el código no pueden divergir.
 */
enum FormaDePago: string
{
    case Efectivo = 'efectivo';
    case Transferencia = 'transferencia';
    case Deposito = 'deposito';

    /**
     * @return list<string>
     */
    public static function valores(): array
    {
        return array_map(static fn (self $forma): string => $forma->value, self::cases());
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::Efectivo      => 'Efectivo',
            self::Transferencia => 'Transferencia',
            self::Deposito      => 'Depósito bancario',
        };
    }

    /**
     * ¿Hace falta el número de referencia?
     */
    public function exigeReferencia(): bool
    {
        return $this !== self::Efectivo;
    }

    public function color(): string
    {
        return match ($this) {
            self::Efectivo      => 'success',
            self::Transferencia => 'info',
            self::Deposito      => 'warning',
        };
    }
}
