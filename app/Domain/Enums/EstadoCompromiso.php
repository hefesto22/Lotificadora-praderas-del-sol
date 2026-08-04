<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * En que quedo un compromiso.
 *
 * VIGENTE es el unico estado que ocupa el lote. Los otros tres son
 * historia: quedan para poder contestar "¿a quien mas se le habia
 * apartado este lote?", que es una pregunta que aparece sola el dia que
 * hay un reclamo.
 */
enum EstadoCompromiso: string
{
    case Vigente = 'vigente';
    case Liberado = 'liberado';
    case Convertido = 'convertido';
    case Rescindido = 'rescindido';

    /**
     * @return list<string>
     */
    public static function valores(): array
    {
        return array_map(static fn (self $estado): string => $estado->value, self::cases());
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::Vigente    => 'Vigente',
            self::Liberado   => 'Liberado',
            self::Convertido => 'Convertido en venta',
            self::Rescindido => 'Rescindido',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Vigente    => 'success',
            self::Liberado   => 'gray',
            self::Convertido => 'info',
            self::Rescindido => 'danger',
        };
    }

    public function ocupaElLote(): bool
    {
        return $this === self::Vigente;
    }
}
