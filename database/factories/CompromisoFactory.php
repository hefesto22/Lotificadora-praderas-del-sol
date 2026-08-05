<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Enums\EstadoCompromiso;
use App\Domain\Enums\TipoCompromiso;
use App\Domain\ValueObjects\Monto;
use App\Models\Cliente;
use App\Models\Compromiso;
use App\Models\Lote;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Compromiso>
 */
class CompromisoFactory extends Factory
{
    /**
     * Los montos se generan como STRING: el §8.3.1 prohibe float en el
     * camino del dinero y el value object Monto rechaza el tipo.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'lote_id'     => Lote::factory(),
            'proyecto_id' => static fn (array $atributos): int => (int) Lote::query()
                ->whereKey($atributos['lote_id'])
                ->firstOrFail()
                ->getAttribute('proyecto_id'),
            'cliente_id' => Cliente::factory(),
            'tipo'       => TipoCompromiso::Apartado,
            'estado'     => EstadoCompromiso::Vigente,
            'area_varas' => '250.0000',

            // 250.0000 x 1200.00 = 300000.00. El CHECK
            // `valor = ROUND(area_varas * precio_vara, 2)` no perdona un
            // factory que invente los tres numeros por separado.
            'precio_vara'       => '1200.00',
            'precio_vara_lista' => '1200.00',
            'valor'             => '300000.00',

            'fecha' => today(),
        ];
    }

    public function paraLote(Lote $lote): static
    {
        return $this->state(fn (array $atributos): array => [
            'lote_id'     => $lote->getKey(),
            'proyecto_id' => $lote->getAttribute('proyecto_id'),
            'area_varas'  => $lote->getAttribute('area_varas'),
            'precio_vara' => $lote->getAttribute('precio_vara'),
            // Sin descuento: lo pactado ES lo de lista.
            'precio_vara_lista' => $lote->getAttribute('precio_vara'),
            'valor'             => $lote->getAttribute('valor'),
        ]);
    }

    public function deTipo(TipoCompromiso $tipo): static
    {
        return $this->state(fn (array $atributos): array => ['tipo' => $tipo]);
    }

    /**
     * Un compromiso vendido por debajo del precio de lista.
     *
     * El motivo NO es decorativo: sin el, el CHECK
     * `compromisos_descuento_con_motivo_chk` rechaza la fila (R4).
     */
    public function conDescuento(string $precioPactado, string $motivo): static
    {
        return $this->state(fn (array $atributos): array => [
            'precio_vara' => $precioPactado,
            'valor'       => new Monto($precioPactado)
                ->multiplicarPor((string) $atributos['area_varas'])
                ->redondeado(),
            'motivo_descuento' => $motivo,
        ]);
    }

    public function cerrado(EstadoCompromiso $estado): static
    {
        return $this->state(fn (array $atributos): array => [
            'estado'     => $estado,
            'cerrado_el' => today(),
        ]);
    }
}
