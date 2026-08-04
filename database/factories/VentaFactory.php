<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Enums\EstadoVenta;
use App\Models\Proyecto;
use App\Models\Venta;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Venta>
 */
class VentaFactory extends Factory
{
    /**
     * El caso base es el golden test del §9.C9: 250 vr² a L 1,400.00, prima
     * de L 100,000.00, 72 cuotas de L 3,472.22. Asi cualquier test que use
     * la factory sin estados arranca de numeros ya verificados.
     *
     * Los montos van como STRING (§8.3.1) y los defaults se declaran
     * explicitos aunque la migracion los tenga: los defaults de Postgres no
     * llegan al modelo en memoria tras create() (§9.C6).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'proyecto_id' => Proyecto::factory(),
            'estado'      => EstadoVenta::Borrador,

            'area_total'      => '250.0000',
            'valor_total'     => '350000.00',
            'prima'           => '100000.00',
            'saldo_financiar' => '250000.00',
            'cuota_mensual'   => '3472.22',
            'plazo_meses'     => 72,
            'dia_pago'        => 5,
        ];
    }

    /**
     * Venta ya numerada y en marcha.
     *
     * El CHECK `ventas_numeracion_segun_estado_chk` obliga a que los tres
     * campos viajen juntos: dejar uno afuera hace fallar el insert, que es
     * exactamente lo que queremos que pase.
     */
    public function vigente(int $expediente = 1, string $codigo = 'RPS'): static
    {
        return $this->state(function (array $atributos) use ($expediente, $codigo): array {
            $fecha = today();

            return [
                'estado'            => EstadoVenta::Vigente,
                'numero_expediente' => $expediente,
                'numero_contrato'   => sprintf('%s-%d-%04d', $codigo, $fecha->year, $expediente),
                'fecha_contrato'    => $fecha,
            ];
        });
    }

    /**
     * Venta de contado: la prima cubre el valor y no hay plan de cuotas.
     */
    public function deContado(): static
    {
        return $this->state(fn (array $atributos): array => [
            'prima'           => $atributos['valor_total'],
            'saldo_financiar' => '0.00',
            'cuota_mensual'   => null,
            'plazo_meses'     => 0,
        ]);
    }

    public function delProyecto(Proyecto $proyecto): static
    {
        return $this->state(fn (array $atributos): array => [
            'proyecto_id' => $proyecto->getKey(),
        ]);
    }

    /**
     * Valor y prima a medida, manteniendo la igualdad que exige el CHECK
     * `ventas_saldo_cuadra_chk`: saldo = valor − prima.
     */
    public function porMontos(string $valorTotal, string $prima, string $saldo, ?string $cuota, int $plazo): static
    {
        return $this->state(fn (array $atributos): array => [
            'valor_total'     => $valorTotal,
            'prima'           => $prima,
            'saldo_financiar' => $saldo,
            'cuota_mensual'   => $cuota,
            'plazo_meses'     => $plazo,
        ]);
    }
}
