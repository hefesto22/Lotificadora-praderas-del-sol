<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Proyecto;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Proyecto>
 */
class ProyectoFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $nombre = 'Residencial '.fake()->unique()->city();

        return [
            'nombre'       => $nombre,
            'codigo'       => Str::upper(Str::substr(Str::slug($nombre, ''), 0, 6)).fake()->unique()->numberBetween(10, 99),
            'municipio'    => fake()->city(),
            'departamento' => 'CP',
            'direccion'    => fake()->address(),
            'activo'       => true,
        ];
    }

    public function inactivo(): static
    {
        return $this->state(fn (array $attributes): array => ['activo' => false]);
    }

    /**
     * El proyecto real del contrato.
     */
    public function praderasDelSol(): static
    {
        return $this->state(fn (array $attributes): array => [
            'nombre'       => 'Residencial Praderas del Sol',
            'codigo'       => 'RPS',
            'municipio'    => 'Cucuyagua',
            'departamento' => 'CP',
        ]);
    }
}
