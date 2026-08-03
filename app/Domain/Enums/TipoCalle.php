<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * Tipos de vía dentro de una lotificación.
 *
 * A diferencia de EstadoLote, esta lista NO es contractual: ningún reporte
 * de dinero depende de ella y agregar un tipo no requiere aprobación de la
 * contratante. Igual la migración genera su CHECK desde valores(), para
 * que la base no pueda tener un tipo que el código no conoce.
 *
 * Los anchos son SUGERENCIAS que el formulario propone al crear la vía, no
 * reglas: el ancho real lo dice el plano y se edita a mano.
 */
enum TipoCalle: string
{
    case Calle = 'calle';
    case Avenida = 'avenida';
    case Boulevard = 'boulevard';
    case Callejon = 'callejon';
    case Peatonal = 'peatonal';

    /**
     * @return list<string>
     */
    public static function valores(): array
    {
        return array_map(static fn (self $tipo): string => $tipo->value, self::cases());
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::Calle     => 'Calle',
            self::Avenida   => 'Avenida',
            self::Boulevard => 'Boulevard',
            self::Callejon  => 'Callejón',
            self::Peatonal  => 'Paso peatonal',
        };
    }

    /**
     * Color del badge en Filament.
     */
    public function color(): string
    {
        return match ($this) {
            self::Calle     => 'gray',
            self::Avenida   => 'info',
            self::Boulevard => 'primary',
            self::Callejon  => 'warning',
            self::Peatonal  => 'success',
        };
    }

    /**
     * Ancho que el formulario propone, en varas.
     *
     * String y no float: es una medida que se compara y se suma con
     * bcmath junto al resto de las áreas (§8.3.1).
     */
    public function anchoSugeridoVaras(): string
    {
        return match ($this) {
            self::Calle     => '7.0000',
            self::Avenida   => '10.0000',
            self::Boulevard => '16.0000',
            self::Callejon  => '4.0000',
            self::Peatonal  => '2.0000',
        };
    }

    /**
     * ¿Pasan vehículos por acá?
     *
     * Sirve para el plano —los pasos peatonales se dibujan distinto— y
     * más adelante para validar que un lote tenga frente vehicular.
     */
    public function admiteVehiculos(): bool
    {
        return $this !== self::Peatonal;
    }
}
