<?php

declare(strict_types=1);

namespace App\Domain\Socios;

use App\Domain\ValueObjects\Monto;
use App\Models\Socio;

/**
 * Lo que le tocó a UN socio en un mes, por los dos caminos.
 *
 * Los dos números y no uno solo porque son dos preguntas distintas: `deLoCobrado`
 * es su parte de lo que entró, `deLoNeto` es su parte de lo que quedó después de
 * pagar los gastos del proyecto. La segunda es la que se lleva; la primera es la
 * que explica de dónde salió.
 */
final readonly class ParteDelSocio
{
    public function __construct(
        public Socio $socio,
        public Monto $deLoCobrado,
        public Monto $deLoNeto,
    ) {}

    public function nombre(): string
    {
        return (string) $this->socio->getAttribute('nombre');
    }

    /**
     * Su parte, sin ceros de relleno: 33.5% y no 33.50%.
     */
    public function porcentaje(): string
    {
        return rtrim(rtrim($this->socio->porcentaje()->redondeado(1), '0'), '.');
    }
}
