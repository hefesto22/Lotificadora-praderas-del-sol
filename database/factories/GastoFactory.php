<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Enums\CategoriaDeGasto;
use App\Domain\Enums\FormaDePago;
use App\Models\Gasto;
use App\Models\Proyecto;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Gasto>
 */
class GastoFactory extends Factory
{
    /**
     * El monto va como STRING: el §8.3.1 prohíbe float en el camino del dinero
     * y `Monto` rechaza el tipo.
     *
     * `numero` se pone acá y no se deja nulo porque la columna es única y NOT
     * NULL. En la aplicacion lo consume `RegistroDeGastos` dentro de una
     * transaccion; un test que quiera probar ESA parte tiene que llamar al
     * Service, no a la factory.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'numero'       => fake()->unique()->numberBetween(1, 999_999),
            'proyecto_id'  => Proyecto::factory(),
            'categoria'    => CategoriaDeGasto::Materiales->value,
            'descripcion'  => 'CEMENTO Y VARILLA PARA LAS CUNETAS DEL BLOQUE H',
            'beneficiario' => 'FERRETERIA EL PROGRESO',
            'factura'      => '000-001-01-00012345',
            'monto'        => '4500.00',
            'forma_pago'   => FormaDePago::Efectivo->value,
            'referencia'   => null,
            'fecha'        => today(),
        ];
    }

    public function delProyecto(Proyecto $proyecto): static
    {
        return $this->state(fn (array $atributos): array => [
            'proyecto_id' => $proyecto->getKey(),
        ]);
    }

    public function de(string $monto): static
    {
        return $this->state(fn (array $atributos): array => ['monto' => $monto]);
    }

    public function enCategoria(CategoriaDeGasto $categoria): static
    {
        return $this->state(fn (array $atributos): array => ['categoria' => $categoria->value]);
    }

    /**
     * Pagado por banco. La referencia va junto con la forma porque el CHECK
     * `gastos_referencia_segun_forma_chk` no admite una sin la otra.
     */
    public function porTransferencia(string $referencia = 'TRF-99120'): static
    {
        return $this->state(fn (array $atributos): array => [
            'forma_pago' => FormaDePago::Transferencia->value,
            'referencia' => $referencia,
        ]);
    }

    public function conFecha(string $fecha): static
    {
        return $this->state(fn (array $atributos): array => ['fecha' => $fecha]);
    }
}
