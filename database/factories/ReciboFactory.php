<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Enums\ConceptoDeRecibo;
use App\Domain\Enums\FormaDePago;
use App\Models\Cliente;
use App\Models\Recibo;
use App\Models\Venta;
use Illuminate\Database\Eloquent\Factories\Factory;
use Override;

/**
 * @extends Factory<Recibo>
 */
class ReciboFactory extends Factory
{
    #[Override]
    protected $model = Recibo::class;

    /**
     * `numero` se pone acá y no se deja nulo porque la columna es única y NOT
     * NULL. En la aplicación lo consume `RegistroDePagos` dentro de una
     * transacción (R12); un test que quiera probar ESA parte tiene que llamar al
     * Service, no a la factory.
     *
     * `venta_id` viene puesto porque el CHECK
     * `recibos_cuelgan_de_un_compromiso_chk` no admite un recibo que no cuelgue
     * de nada (R13): todo pago cuelga de algo.
     *
     * El monto va como STRING (§8.3.1).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'numero'     => fake()->unique()->numberBetween(1, 999_999),
            'venta_id'   => Venta::factory(),
            'cliente_id' => Cliente::factory(),
            'concepto'   => ConceptoDeRecibo::Cuota,
            'forma_pago' => FormaDePago::Efectivo,
            'monto'      => '5000.00',
            'fecha'      => today()->toDateString(),
        ];
    }

    public function deConcepto(ConceptoDeRecibo $concepto): static
    {
        return $this->state(fn (): array => ['concepto' => $concepto]);
    }

    public function anulado(string $motivo = 'Error de carga.'): static
    {
        return $this->state(fn (): array => [
            'anulado_el'       => today()->toDateString(),
            'motivo_anulacion' => $motivo,
        ]);
    }
}
