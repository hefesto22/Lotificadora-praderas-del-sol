<?php

declare(strict_types=1);

namespace App\Domain\Ventas;

use App\Domain\ValueObjects\Monto;
use Carbon\CarbonImmutable;

/**
 * Una cuota del plan, todavia sin tocar la base de datos.
 *
 * Es el resultado del calculo, no la fila de `cuotas`. Existe para que el
 * formulario de venta pueda MOSTRAR el plan antes de guardarlo (§10.8: "el
 * usuario debe ver el numero de cuota antes de confirmar, no despues") sin
 * escribir nada.
 *
 * No lleva `monto_pagado` ni `estado`: eso nace cuando la venta se activa y
 * el plan se congela. Y `vencida` no se guarda nunca — es derivada de la
 * fecha (§9.D5).
 */
final readonly class CuotaProyectada
{
    public function __construct(
        public int $numero,
        public CarbonImmutable $vencimiento,
        public Monto $monto,
    ) {}

    /**
     * El monto tal como va a la columna NUMERIC(14,2).
     */
    public function montoParaBase(): string
    {
        return $this->monto->redondeado();
    }

    /**
     * La fecha tal como va a la columna DATE.
     *
     * §7.5.3: los vencimientos son DATE, no timestamp. La hora de un
     * vencimiento no significa nada y arrastra la zona horaria a un lugar
     * donde solo puede hacer dano.
     */
    public function vencimientoParaBase(): string
    {
        return $this->vencimiento->toDateString();
    }
}
