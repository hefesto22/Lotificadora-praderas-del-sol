<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Lote;
use App\Models\LoteConsultado;
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
            'nombre'      => fake()->name(),
            // Un número hondureño de ocho dígitos, que es lo que va a llegar.
            'telefono' => '9'.fake()->numerify('#######'),
            'ip'       => fake()->ipv4(),
        ];
    }

    /**
     * El prospecto que preguntó por ese lote.
     *
     * ⚠️ Desde el 23-ago el lote NO vive en `prospectos`: la consulta es una
     * fila aparte. Por eso esto va en `afterCreating` y no en un `state` —
     * la fila hija necesita que el prospecto ya tenga id.
     */
    public function delLote(Lote $lote, ?int $plazo = 12): static
    {
        return $this
            ->state(fn (array $atributos): array => [
                'proyecto_id' => $lote->getAttribute('proyecto_id'),
            ])
            ->afterCreating(function (Prospecto $prospecto) use ($lote, $plazo): void {
                LoteConsultado::query()->create([
                    'prospecto_id' => $prospecto->getKey(),
                    'lote_id'      => $lote->getKey(),
                    'plazo_meses'  => $plazo,
                    'veces'        => 1,
                    'primera_vez'  => now(),
                    'ultima_vez'   => now(),
                ]);
            });
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
