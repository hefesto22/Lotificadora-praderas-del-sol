<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\ValueObjects\Monto;
use App\Models\Cuota;
use App\Models\Venta;
use Illuminate\Database\Eloquent\Factories\Factory;
use Override;

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

    /**
     * ═══ EL CAPITAL SE DERIVA, NO SE ESCRIBE ═══
     *
     * Desde el 8-ago-2026 `monto_capital` es NOT NULL y el CHECK
     * `cuotas_partes_suman_el_monto_chk` exige que capital + interes den
     * EXACTO el monto. Esta factory no lo llenaba, asi que cualquier
     * `create()` moria con un «null value in column "monto_capital"» que no
     * nombra ni la cuota ni el plan de pago.
     *
     * 🔴 Nadie se entero durante dos semanas porque NINGUN test usaba esta
     * factory: se rompio el 8-ago y salio a la luz el 22, al escribir los
     * primeros que la usan. Una factory sin usar no esta bien: esta sin
     * probar. Por eso `CuotaTest` ahora la ejerce sola, sin depender de que
     * alguna pantalla se acuerde de ella.
     *
     * Va en `afterMaking` y no en `definition()` a proposito: aca ya se
     * aplicaron los overrides del test, asi que un `create(['monto' => ...])`
     * arrastra el capital solo en vez de rebotar contra el CHECK. Y quien
     * reparta las dos partes a mano manda: no se le toca nada.
     */
    #[Override]
    public function configure(): static
    {
        return $this->afterMaking(static function (Cuota $cuota): void {
            if ($cuota->getAttribute('monto_capital') !== null) {
                return;
            }

            $monto = new Monto((string) $cuota->getAttribute('monto'));
            $interes = new Monto((string) $cuota->getAttribute('monto_interes'));

            $cuota->setAttribute('monto_capital', $monto->restar($interes)->redondeado());
        });
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
