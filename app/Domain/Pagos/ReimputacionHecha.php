<?php

declare(strict_types=1);

namespace App\Domain\Pagos;

use App\Domain\ValueObjects\Monto;

/**
 * Lo que hizo —o haría— una re-imputación. No escribe nada.
 *
 * `escrita` en falso es un ENSAYO: todo lo que dice este retrato ocurrió de
 * verdad adentro de una transacción que después se deshizo, así que los
 * números son los que van a quedar, no una estimación. Lo único que puede
 * cambiar entre el ensayo y la corrida real es el número del recibo nuevo, si
 * en el medio alguien emite otro de la misma serie.
 */
final readonly class ReimputacionHecha
{
    /**
     * @param list<string> $foliosNuevos uno por cada titular de recibo de los lotes que reciben
     * @param list<LoteReimputado> $lotes todos los del expediente, reciban o no
     */
    public function __construct(
        public bool $escrita,
        public string $folioViejo,
        public array $foliosNuevos,
        public Monto $monto,
        public array $lotes,
    ) {}
}
