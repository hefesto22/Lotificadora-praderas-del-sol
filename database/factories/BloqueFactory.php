<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Bloque;
use App\Models\Proyecto;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Bloque>
 */
class BloqueFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'proyecto_id' => Proyecto::factory(),
            'nombre'      => fake()->unique()->randomLetter(),
            // Dato declarado del plano, como string: entra a bcmath.
            'area_total_varas'   => fake()->numberBetween(2000, 20000).'.0000',
            'lotes_planificados' => fake()->numberBetween(10, 60),
            'orden'              => 0,
        ];
    }

    public function delProyecto(Proyecto $proyecto): static
    {
        return $this->state(fn (array $attributes): array => [
            'proyecto_id' => $proyecto->getKey(),
        ]);
    }
}
