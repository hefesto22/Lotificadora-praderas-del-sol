<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Lote;
use App\Models\Prospecto;
use App\Models\Proyecto;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Prospecto>
 */
class ProspectoFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'proyecto_id' => Proyecto::factory(),
            'lote_id'     => null,
            'nombre'      => $this->faker->name(),
            // Un número hondureño de ocho dígitos, que es lo que va a llegar.
            'telefono'    => '9'.$this->faker->numerify('#######'),
            'mensaje'     => null,
            'plazo_meses' => 12,
            'ip'          => $this->faker->ipv4(),
        ];
    }

    public function delLote(Lote $lote): static
    {
        return $this->state(fn (array $atributos): array => [
            'lote_id'     => $lote->getKey(),
            'proyecto_id' => $lote->getAttribute('proyecto_id'),
        ]);
    }

    /**
     * Ya lo llamaron. Los dos campos van juntos: el CHECK
     * `prospectos_atencion_completa_chk` no admite uno sin el otro.
     */
    public function atendido(int $porUsuario): static
    {
        return $this->state(fn (array $atributos): array => [
            'atendido_el'  => now(),
            'atendido_por' => $porUsuario,
        ]);
    }
}
