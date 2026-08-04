<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Cuota;
use App\Models\Venta;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Cuota>
 */
class CuotaFactory extends Factory
{
    /**
     * Fechas relativas, nunca fijas en el pasado (§9.C2): una cuota
     * hardcodeada en 2026 se vuelve "vencida" sola con el paso del tiempo y
     * rompe tests que no tienen nada que ver.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'venta_id'          => Venta::factory(),
            'numero'            => 1,
            'fecha_vencimiento' => today()->addMonth(),
            'monto'             => '3472.22',
            'monto_pagado'      => '0.00',
        ];
    }

    public function deLaVenta(Venta $venta): static
    {
        return $this->state(fn (array $atributos): array => [
            'venta_id' => $venta->getKey(),
        ]);
    }

    /**
     * Cuota ya vencida y sin pagar.
     */
    public function vencida(int $dias = 30): static
    {
        return $this->state(fn (array $atributos): array => [
            'fecha_vencimiento' => today()->subDays($dias),
            'monto_pagado'      => '0.00',
        ]);
    }

    /**
     * Cuota saldada. El CHECK de la base impide que `monto_pagado` supere
     * al monto, asi que se copia el mismo valor.
     */
    public function pagada(): static
    {
        return $this->state(fn (array $atributos): array => [
            'monto_pagado' => $atributos['monto'],
        ]);
    }

    public function conAbonoParcial(string $abonado): static
    {
        return $this->state(fn (array $atributos): array => [
            'monto_pagado' => $abonado,
        ]);
    }
}
