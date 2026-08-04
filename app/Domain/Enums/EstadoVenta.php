<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * La maquina de estados del expediente (§8.2).
 *
 *     borrador → vigente → liquidada
 *                       ↘ rescindida
 *     borrador → anulada
 *
 * BORRADOR es la venta que se esta armando: tiene lotes y numeros, pero
 * todavia no consumio el correlativo de contrato ni genero cuotas. Es el
 * unico estado desde el que se puede anular sin dejar un hueco en la serie.
 *
 * VIGENTE nace cuando la prima se paga COMPLETA (R5, respuesta de la
 * contratante del 3-ago-2026). Ahi y solo ahi: se consume el correlativo,
 * los lotes pasan a vendido y se congela el plan de cuotas. Todo en la
 * misma transaccion.
 *
 * LIQUIDADA es la venta pagada hasta el final. RESCINDIDA es la que se
 * cayo; lo que se le devuelve al cliente se negocia caso por caso (R6) y
 * el sistema solo registra la liquidacion, no la calcula.
 */
enum EstadoVenta: string
{
    case Borrador = 'borrador';
    case Vigente = 'vigente';
    case Liquidada = 'liquidada';
    case Rescindida = 'rescindida';
    case Anulada = 'anulada';

    /**
     * @return list<string>
     */
    public static function valores(): array
    {
        return array_map(static fn (self $estado): string => $estado->value, self::cases());
    }

    /**
     * Los estados en los que la venta ya consumio su numero de contrato.
     *
     * Se usa para generar el CHECK de la base: un borrador NO puede tener
     * numero, y una venta que dejo de ser borrador NO puede no tenerlo.
     *
     * @return list<string>
     */
    public static function valoresConNumero(): array
    {
        return array_values(array_map(
            static fn (self $estado): string => $estado->value,
            array_filter(self::cases(), static fn (self $estado): bool => $estado !== self::Borrador),
        ));
    }

    /**
     * Los estados terminales: la venta ya no se mueve mas.
     *
     * @return list<string>
     */
    public static function valoresCerrados(): array
    {
        return [self::Liquidada->value, self::Rescindida->value, self::Anulada->value];
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::Borrador   => 'Borrador',
            self::Vigente    => 'Vigente',
            self::Liquidada  => 'Liquidada',
            self::Rescindida => 'Rescindida',
            self::Anulada    => 'Anulada',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Borrador   => 'gray',
            self::Vigente    => 'success',
            self::Liquidada  => 'info',
            self::Rescindida => 'danger',
            self::Anulada    => 'warning',
        };
    }

    /**
     * ¿Ya consumio numero de contrato y de expediente?
     */
    public function tieneNumero(): bool
    {
        return $this !== self::Borrador;
    }

    /**
     * ¿La venta sigue viva y cobrandose?
     */
    public function estaAbierta(): bool
    {
        return $this === self::Borrador || $this === self::Vigente;
    }

    /**
     * ¿Los lotes de esta venta siguen ocupados?
     *
     * Una venta rescindida o anulada suelta sus lotes; una liquidada NO
     * —el cliente termino de pagar y el lote es suyo—.
     */
    public function ocupaLosLotes(): bool
    {
        return $this === self::Vigente || $this === self::Liquidada;
    }
}
