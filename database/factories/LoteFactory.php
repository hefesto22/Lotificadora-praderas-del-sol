<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Enums\EstadoLote;
use App\Models\Bloque;
use App\Models\Lote;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lote>
 */
class LoteFactory extends Factory
{
    /**
     * Áreas y precios se generan como STRING, nunca como float: es la
     * regla del §8.3.1 y el modelo rechaza float explícitamente.
     *
     * `proyecto_id` se deriva del bloque para que la FK compuesta
     * (bloque_id, proyecto_id) siempre resuelva.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'bloque_id' => Bloque::factory(),
            // firstOrFail() y no findOrFail(): este ultimo acepta un array
            // de ids y por eso su tipo de retorno es Model|Collection, que
            // PHPStan no puede estrechar. whereKey()->firstOrFail() devuelve
            // siempre un Bloque.
            'proyecto_id' => static fn (array $atributos): int => (int) Bloque::query()
                ->whereKey($atributos['bloque_id'])
                ->firstOrFail()
                ->getAttribute('proyecto_id'),
            'numero'      => (string) fake()->unique()->numberBetween(1, 9999),
            'area_varas'  => fake()->numberBetween(50, 999).'.'.str_pad((string) fake()->numberBetween(0, 9999), 4, '0', STR_PAD_LEFT),
            'precio_vara' => fake()->numberBetween(200, 5000).'.00',
            'estado'      => EstadoLote::Disponible,
        ];
    }

    public function enBloque(Bloque $bloque): static
    {
        return $this->state(fn (array $attributes): array => [
            'bloque_id'   => $bloque->getKey(),
            'proyecto_id' => $bloque->getAttribute('proyecto_id'),
        ]);
    }

    public function conMedidas(string $areaVaras, string $precioVara): static
    {
        return $this->state(fn (array $attributes): array => [
            'area_varas'  => $areaVaras,
            'precio_vara' => $precioVara,
        ]);
    }

    public function conEstado(EstadoLote $estado): static
    {
        return $this->state(fn (array $attributes): array => ['estado' => $estado]);
    }
}
