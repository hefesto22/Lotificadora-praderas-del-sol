<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Cliente;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Cliente>
 */
class ClienteFactory extends Factory
{
    /**
     * El correlativo único es lo que garantiza que el DNI y el RTN
     * completos no choquen: van al final de ambos, así que aunque el
     * departamento y el año se repitan, la cadena entera es distinta.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $correlativo = (string) fake()->unique()->numberBetween(10000, 99999);
        $anio = (string) fake()->numberBetween(1950, 2006);

        return [
            'nombre'    => fake()->name(),
            'dni'       => '0801'.$anio.$correlativo,
            'rtn'       => '0801'.$anio.'0'.$correlativo,
            'telefono'  => '9'.fake()->unique()->numerify('#######'),
            'correo'    => fake()->unique()->safeEmail(),
            'direccion' => fake()->address(),
            'activo'    => true,
        ];
    }

    /**
     * Al apartar un lote a veces solo se tiene el nombre y un teléfono.
     * El índice único es parcial justamente para permitir esto.
     */
    public function sinIdentificacion(): static
    {
        return $this->state(fn (array $atributos): array => [
            'dni' => null,
            'rtn' => null,
        ]);
    }

    public function inactivo(): static
    {
        return $this->state(fn (array $atributos): array => ['activo' => false]);
    }
}
