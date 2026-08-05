<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PlanDePago;
use App\Models\Proyecto;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlanDePago>
 */
class PlanDePagoFactory extends Factory
{
    /**
     * El precio va como STRING: el §8.3.1 prohíbe float en el camino del
     * dinero y Monto rechaza el tipo.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'proyecto_id' => Proyecto::factory(),
            'meses'       => 12,
            'precio_vara' => '1500.00',
            'activo'      => true,
        ];
    }

    public function delProyecto(Proyecto $proyecto): static
    {
        return $this->state(fn (array $atributos): array => [
            'proyecto_id' => $proyecto->getKey(),
        ]);
    }

    public function aPlazo(int $meses, string $precioVara): static
    {
        return $this->state(fn (array $atributos): array => [
            'meses'       => $meses,
            'precio_vara' => $precioVara,
        ]);
    }

    public function inactivo(): static
    {
        return $this->state(fn (array $atributos): array => ['activo' => false]);
    }
}
