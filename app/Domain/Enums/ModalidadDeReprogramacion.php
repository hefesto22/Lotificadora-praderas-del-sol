<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * Que hace un abono a capital con lo que falta pagar (R21).
 *
 * ═══ LOS DOS CAMINOS LOS ELIGE EL CLIENTE, NO EL SISTEMA ═══
 *
 * La contratante lo dijo en la reunion del 6-ago-2026: el que abona decide
 * si quiere terminar antes o pagar menos por mes. No hay un calculo que lo
 * deduzca —los dos son correctos— y por eso es una pregunta del formulario y
 * no una regla escondida en una funcion.
 *
 * `AcortarPlazo` es el default: es lo que la contratante contesto en el
 * cuestionario original (R3), o sea lo que se venia haciendo en el cuaderno.
 *
 * La lista es la fuente de verdad: la migracion arma su CHECK a partir de
 * `valores()`, asi que la base y el codigo no pueden divergir.
 */
enum ModalidadDeReprogramacion: string
{
    case AcortarPlazo = 'acortar_plazo';
    case BajarCuota = 'bajar_cuota';

    /**
     * @return list<string>
     */
    public static function valores(): array
    {
        return array_map(static fn (self $modalidad): string => $modalidad->value, self::cases());
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::AcortarPlazo => 'Misma cuota, menos meses',
            self::BajarCuota   => 'Mismos meses, cuota más baja',
        };
    }

    /**
     * Como se lo explica quien atiende, con el cliente enfrente.
     */
    public function explicacion(): string
    {
        return match ($this) {
            self::AcortarPlazo => 'Sigue pagando lo mismo cada mes y termina antes.',
            self::BajarCuota   => 'Termina el mismo mes que tenía pactado, pagando menos cada mes.',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::AcortarPlazo => 'success',
            self::BajarCuota   => 'info',
        };
    }
}
