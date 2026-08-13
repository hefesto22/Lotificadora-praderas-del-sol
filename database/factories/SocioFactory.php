<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Proyecto;
use App\Models\Socio;
use Illuminate\Database\Eloquent\Factories\Factory;
use Override;

/**
 * @extends Factory<Socio>
 */
class SocioFactory extends Factory
{
    #[Override]
    protected $model = Socio::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'proyecto_id' => Proyecto::factory(),
            'nombre'      => mb_strtoupper(fake()->unique()->name()),
            'dni'         => fake()->numerify('#############'),
            'telefono'    => fake()->numerify('9#######'),
            'porcentaje'  => '50.0',
            'activo'      => true,
        ];
    }

    public function delProyecto(Proyecto $proyecto): static
    {
        return $this->state(fn (): array => ['proyecto_id' => $proyecto->getKey()]);
    }

    public function conParte(string $porcentaje): static
    {
        return $this->state(fn (): array => ['porcentaje' => $porcentaje]);
    }

    public function inactivo(): static
    {
        return $this->state(fn (): array => ['activo' => false]);
    }
}
