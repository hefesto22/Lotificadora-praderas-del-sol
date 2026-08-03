<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * Como se numeran los lotes que emite el generador.
 *
 * SERPENTINA es el default porque es como numeran los planos reales: se
 * recorre una fila de ida y la siguiente de vuelta, de modo que el lote
 * 12 y el 13 quedan pegados en el terreno. Con numeracion por filas, el
 * 12 y el 13 caen en esquinas opuestas del bloque, y quien camina el
 * terreno con la lista en la mano se pierde.
 */
enum Numeracion: string
{
    case Serpentina = 'serpentina';
    case PorFilas = 'por_filas';

    /**
     * @return list<string>
     */
    public static function valores(): array
    {
        return array_map(static fn (self $caso): string => $caso->value, self::cases());
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::Serpentina => 'Serpentina (ida y vuelta)',
            self::PorFilas   => 'Por filas (siempre izquierda a derecha)',
        };
    }
}
