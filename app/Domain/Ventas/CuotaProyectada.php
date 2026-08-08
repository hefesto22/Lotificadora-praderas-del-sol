<?php

declare(strict_types=1);

namespace App\Domain\Ventas;

use App\Domain\Exceptions\PlanDeCuotasInvalidoException;
use App\Domain\ValueObjects\Monto;
use Carbon\CarbonImmutable;

/**
 * Una cuota del plan, todavia sin tocar la base de datos.
 *
 * Es el resultado del calculo, no la fila de `cuotas`. Existe para que el
 * formulario de venta pueda MOSTRAR el plan antes de guardarlo (§10.8: "el
 * usuario debe ver el numero de cuota antes de confirmar, no despues") sin
 * escribir nada.
 *
 * No lleva `monto_pagado` ni `estado`: eso nace cuando la venta se activa y
 * el plan se congela. Y `vencida` no se guarda nunca — es derivada de la
 * fecha (§9.D5).
 *
 * ═══ DESDE EL 8-AGO-2026 LA CUOTA VIENE PARTIDA ═══
 *
 * `monto` es lo que el cliente paga ese mes y sigue siendo el numero que
 * manda. `capital` e `interes` son en que se descompone, y **suman exacto**:
 * el constructor lo verifica y no admite una cuota que no cierre contra sus
 * dos partes.
 *
 * Con tasa 0 —el caso de Praderas del Sol, R1— `capital` es la cuota entera
 * e `interes` es cero, que es exactamente lo que el sistema hacia antes de
 * que estas dos propiedades existieran. Por eso el camino viejo usa
 * `sinInteres()` y no toca la aritmetica: el golden test del §9.C9 sigue
 * comparando los mismos numeros.
 */
final readonly class CuotaProyectada
{
    /**
     * @throws PlanDeCuotasInvalidoException si las partes no suman la cuota
     */
    public function __construct(
        public int $numero,
        public CarbonImmutable $vencimiento,
        public Monto $monto,
        public Monto $capital,
        public Monto $interes,
    ) {
        /*
         * La verificacion vive en el constructor y no en un test: esta clase
         * la construyen tres constructores de `PlanDeCuotas` y el dia que uno
         * reparta mal el residuo, el estado de cuenta mostraria un capital y
         * un interes que no suman lo que se cobra. Eso no se descubre hasta
         * que un cliente saca la calculadora.
         */
        if (! $capital->sumar($interes)->igualA($monto)) {
            throw PlanDeCuotasInvalidoException::porCuotaQueNoCuadraConSusPartes(
                $monto,
                $capital,
                $interes,
                $numero,
            );
        }
    }

    /**
     * La cuota de un plan sin interes (R1): todo es capital.
     *
     * Es el camino de Praderas del Sol y el que corria antes del 8-ago-2026.
     */
    public static function sinInteres(int $numero, CarbonImmutable $vencimiento, Monto $monto): self
    {
        return new self($numero, $vencimiento, $monto, $monto, Monto::cero());
    }

    /**
     * La cuota de una tabla francesa: las partes mandan y la suma es la cuota.
     */
    public static function conInteres(
        int $numero,
        CarbonImmutable $vencimiento,
        Monto $capital,
        Monto $interes,
    ): self {
        return new self($numero, $vencimiento, $capital->sumar($interes), $capital, $interes);
    }

    public function llevaInteres(): bool
    {
        return ! $this->interes->esCero();
    }

    /**
     * El monto tal como va a la columna NUMERIC(14,2).
     */
    public function montoParaBase(): string
    {
        return $this->monto->redondeado();
    }

    public function capitalParaBase(): string
    {
        return $this->capital->redondeado();
    }

    public function interesParaBase(): string
    {
        return $this->interes->redondeado();
    }

    /**
     * La fecha tal como va a la columna DATE.
     *
     * §7.5.3: los vencimientos son DATE, no timestamp. La hora de un
     * vencimiento no significa nada y arrastra la zona horaria a un lugar
     * donde solo puede hacer dano.
     */
    public function vencimientoParaBase(): string
    {
        return $this->vencimiento->toDateString();
    }
}
