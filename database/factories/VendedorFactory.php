<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Vendedor;
use Illuminate\Database\Eloquent\Factories\Factory;
use Override;

/**
 * @extends Factory<Vendedor>
 */
class VendedorFactory extends Factory
{
    #[Override]
    protected $model = Vendedor::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nombre'   => mb_strtoupper(fake()->unique()->name()),
            'dni'      => fake()->numerify('#############'),
            'telefono' => fake()->numerify('9#######'),
            'activo'   => true,
        ];
    }

    public function inactivo(): static
    {
        return $this->state(fn (): array => ['activo' => false]);
    }
}
