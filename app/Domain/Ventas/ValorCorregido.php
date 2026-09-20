<?php

declare(strict_types=1);

namespace App\Domain\Ventas;

use App\Domain\ValueObjects\Monto;

/**
 * El antes y el después de UN lote en una corrección de valor. No escribe nada.
 *
 * Es lo que `CorreccionDeValor` calcula ANTES de tocar la base, y por eso es
 * lo mismo que imprime el ensayo y lo que después se escribe: si la pantalla
 * calculara por su lado y el Service por el suyo, el día que uno de los dos
 * cambie se aprueba un número y se guarda otro. Es el mismo trato que
 * `EfectoDelAbono` tiene con el modal de abono.
 *
 * ⚠️ `Monto` no admite negativos, así que la diferencia va SIEMPRE en positivo
 * y el sentido lo dice `sube()`.
 */
final readonly class ValorCorregido
{
    /**
     * @param string $precioAntes el precio por unidad de área congelado hoy, con seis decimales
     * @param string $precioDespues el que resulta de dividir el valor nuevo entre el área
     * @param string $listaDespues el de lista que queda escrito: acompaña al pactado si eran el mismo
     * @param int $ultimaCuota el número de la cuota que absorbe la diferencia; 0 si el lote no cambia
     */
    public function __construct(
        public int $compromisoId,
        public string $codigo,
        public Monto $valorAntes,
        public Monto $valorDespues,
        public string $precioAntes,
        public string $precioDespues,
        public string $listaDespues,
        public Monto $saldoAntes,
        public Monto $saldoDespues,
        public int $ultimaCuota,
        public Monto $ultimaAntes,
        public Monto $ultimaDespues,
    ) {}

    public function cambia(): bool
    {
        return ! $this->valorAntes->igualA($this->valorDespues);
    }

    public function sube(): bool
    {
        return $this->valorDespues->mayorQue($this->valorAntes);
    }

    public function diferencia(): Monto
    {
        return $this->sube()
            ? $this->valorDespues->restar($this->valorAntes)
            : $this->valorAntes->restar($this->valorDespues);
    }

    /**
     * Le aplica a un monto la MISMA diferencia, en el mismo sentido.
     *
     * Es lo que mantiene todo atado: el valor del lote, su última cuota, los
     * saldos de sus constancias y el resumen de la venta se mueven con esta
     * única función, así que no pueden moverse distinto.
     */
    public function mover(Monto $monto): Monto
    {
        return $this->sube()
            ? $monto->sumar($this->diferencia())
            : $monto->restar($this->diferencia());
    }
}
