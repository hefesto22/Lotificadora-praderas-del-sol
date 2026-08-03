<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Enums\TipoCalle;
use App\Models\Calle;
use App\Models\Proyecto;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Calle>
 */
class CalleFactory extends Factory
{
    /**
     * El ancho se genera como STRING, igual que las áreas: el §8.3.1
     * prohíbe float en las medidas que después se suman con bcmath.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        /** @var TipoCalle $tipo */
        $tipo = fake()->randomElement(TipoCalle::cases());

        return [
            'proyecto_id' => Proyecto::factory(),
            'nombre'      => null,
            'tipo'        => $tipo,
            'ancho_varas' => $tipo->anchoSugeridoVaras(),
            'trazo'       => [[0.0, 0.0], [100.0, 0.0]],
            'orden'       => 0,
        ];
    }

    public function enProyecto(Proyecto $proyecto): static
    {
        return $this->state(fn (array $attributes): array => [
            'proyecto_id' => $proyecto->getKey(),
        ]);
    }

    public function deTipo(TipoCalle $tipo): static
    {
        return $this->state(fn (array $attributes): array => [
            'tipo'        => $tipo,
            'ancho_varas' => $tipo->anchoSugeridoVaras(),
        ]);
    }

    /**
     * @param list<array{float, float}> $puntos
     */
    public function conTrazo(array $puntos): static
    {
        return $this->state(fn (array $attributes): array => ['trazo' => $puntos]);
    }
}
