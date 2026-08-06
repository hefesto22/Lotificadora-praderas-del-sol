<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * Por qué entró ese dinero.
 *
 * No es una etiqueta de presentación: cada concepto se APLICA distinto, y de
 * eso depende qué le pasa al plan de cuotas.
 *
 * - `senia` — el dinero del apartado (R14). Cuelga de un compromiso de tipo
 *   apartado; al vender cuenta como parte de la prima.
 * - `prima` — lo que se paga al firmar (R5). No toca cuotas: la prima ya está
 *   descontada del saldo que las generó.
 * - `cuota` — el pago mensual. Se aplica FIFO contra las cuotas pendientes del
 *   lote, y puede cubrir media cuota o tres (R19).
 * - `abono_capital` — el extraordinario (R21). NO se aplica a cuotas: baja el
 *   saldo y **reescribe el plan pendiente** del lote, en una de dos formas que
 *   elige el cliente.
 *
 * La lista es la fuente de verdad: la migración arma su CHECK a partir de
 * `valores()`.
 */
enum ConceptoDeRecibo: string
{
    case Senia = 'senia';
    case Prima = 'prima';
    case Cuota = 'cuota';
    case AbonoCapital = 'abono_capital';

    /**
     * @return list<string>
     */
    public static function valores(): array
    {
        return array_map(static fn (self $concepto): string => $concepto->value, self::cases());
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::Senia        => 'Seña del apartado',
            self::Prima        => 'Prima',
            self::Cuota        => 'Cuota',
            self::AbonoCapital => 'Abono a capital',
        };
    }

    /**
     * ¿Este dinero se reparte entre las cuotas pendientes?
     *
     * La seña y la prima no: nacen antes del plan o ya están descontadas de
     * él. El abono a capital tampoco — ese lo REESCRIBE.
     */
    public function seAplicaACuotas(): bool
    {
        return $this === self::Cuota;
    }

    public function color(): string
    {
        return match ($this) {
            self::Senia        => 'warning',
            self::Prima        => 'info',
            self::Cuota        => 'success',
            self::AbonoCapital => 'primary',
        };
    }
}
