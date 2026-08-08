<?php

declare(strict_types=1);

namespace App\Domain\Pagos;

use App\Domain\ValueObjects\Monto;
use App\Domain\Ventas\CondicionesDeMora;
use App\Models\Cuota;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Cuanta mora debe un LOTE entero al dia del cobro. No escribe nada.
 *
 * ═══ POR QUE ES UN OBJETO Y NO CODIGO ADENTRO DEL SERVICE ═══
 *
 * Es la misma razon que `EfectoDelAbono`: el §10.8 manda mostrar el numero
 * ANTES de confirmar. Quien atiende tiene un cliente enfrente preguntando
 * «¿cuanto es con la mora?», y esa respuesta no puede llegar despues de
 * apretar el boton. Si la pantalla calculara por su lado y el Service por el
 * suyo, el dia que uno de los dos cambie el cliente firma un numero y la base
 * guarda otro.
 *
 * ═══ LO YA COBRADO SE DESCUENTA, Y POR ESO NO SE COBRA DOS VECES ═══
 *
 * `CalculoDeMora` dice la mora ACUMULADA de una cuota desde su vencimiento.
 * Si el cliente ya pago mora de esa misma cuota en un recibo anterior y
 * siguio atrasado, hoy debe la diferencia y no el total otra vez.
 *
 * Por eso `cuotas` guarda `mora_pagada` y `mora_condonada`. Son valores
 * derivables —la suma de las aplicaciones— y se guardan igual, por la misma
 * razon documentada para `monto_pagado`: el estado de cuenta los consulta
 * lote por lote y hacerlo con un JOIN por cuota es pagar una consulta cara
 * por un numero que no cambia solo.
 *
 * ═══ CONDONAR ES PARA SIEMPRE, PARA ESOS DIAS ═══
 *
 * Perdonar la mora de una cuota que sigue vencida no congela el reloj: lo que
 * se condono queda descontado, y los dias que pasen despues vuelven a generar
 * mora. Es lo correcto —el atraso siguio— y es lo que quien condona espera:
 * perdono lo de hoy, no lo de todo el año que viene.
 */
final readonly class MoraDelLote
{
    /**
     * @param list<array{cuota: Cuota, calculo: CalculoDeMora, debida: Monto}> $renglones
     */
    private function __construct(
        public array $renglones,
        public Monto $total,
        public CarbonImmutable $alDia,
    ) {}

    /**
     * @param iterable<int, Cuota> $pendientes las cuotas que todavia deben algo
     */
    public static function calcular(
        iterable $pendientes,
        CondicionesDeMora $condiciones,
        CarbonImmutable $alDia,
    ): self {
        $renglones = [];
        $total = Monto::cero();

        foreach ($pendientes as $cuota) {
            $vence = self::vencimientoDe($cuota);

            if (! $vence instanceof CarbonImmutable) {
                continue;
            }

            $calculo = CalculoDeMora::sobre($cuota->saldo(), $vence, $alDia, $condiciones);

            if (! $calculo->hayMora()) {
                continue;
            }

            $yaResuelta = $cuota->moraPagada()->sumar($cuota->moraCondonada());

            // Lo ya cobrado o perdonado puede superar lo calculado de hoy si
            // el cliente abono capital y bajo la base. No se devuelve nada:
            // se debe cero.
            $debida = $calculo->monto->mayorQue($yaResuelta)
                ? $calculo->monto->restar($yaResuelta)
                : Monto::cero();

            if ($debida->esCero()) {
                continue;
            }

            $renglones[] = ['cuota' => $cuota, 'calculo' => $calculo, 'debida' => $debida];
            $total = $total->sumar($debida);
        }

        return new self($renglones, $total, $alDia);
    }

    public static function ninguna(CarbonImmutable $alDia): self
    {
        return new self([], Monto::cero(), $alDia);
    }

    public function hayMora(): bool
    {
        return ! $this->total->esCero();
    }

    /**
     * Lo que debe de mora una cuota en particular.
     */
    public function deLaCuota(Cuota $cuota): Monto
    {
        foreach ($this->renglones as $renglon) {
            if ((int) $renglon['cuota']->getKey() === (int) $cuota->getKey()) {
                return $renglon['debida'];
            }
        }

        return Monto::cero();
    }

    /**
     * Los dias de atraso de la cuota vencida mas vieja con mora.
     */
    public function diasDeAtraso(): int
    {
        $maximo = 0;

        foreach ($this->renglones as $renglon) {
            $maximo = max($maximo, $renglon['calculo']->diasDeAtraso);
        }

        return $maximo;
    }

    // ─── Interno ──────────────────────────────────────────────────────

    /**
     * El cast `date` de Cuota devuelve un Carbon MUTABLE y el dominio trabaja
     * con `CarbonImmutable` a proposito. Se convierte en el borde, una sola
     * vez — igual que en `EfectoDelAbono`.
     */
    private static function vencimientoDe(Cuota $cuota): ?CarbonImmutable
    {
        $fecha = $cuota->getAttribute('fecha_vencimiento');

        return $fecha instanceof DateTimeInterface
            ? CarbonImmutable::parse($fecha->format('Y-m-d'))
            : null;
    }
}
