<?php

declare(strict_types=1);

namespace App\Domain\Plano;

use App\Domain\Enums\Numeracion;

/**
 * Como acomodar en el plano los lotes que YA existen.
 *
 * La diferencia con ParametrosDeGeneracion es de que lado viene el area:
 *
 *  - Al GENERAR, el rectangulo manda: frente x fondo produce el area del
 *    lote nuevo, que todavia no existia.
 *  - Al ACOMODAR, el area manda. Cada lote ya tiene la suya, cargada del
 *    documento legal, y el dibujo se le adapta: se fija el fondo y el
 *    frente sale de dividir area / fondo.
 *
 * Por eso aca no hay `frenteVaras`. Ponerlo obligaria a elegir entre
 * dibujar prolijo y decir la verdad, y en esa disyuntiva el dibujo pierde
 * siempre. Con el fondo fijo, cada rectangulo encierra exactamente el
 * area que el lote tiene cargada y ninguno nace desalineado.
 */
final readonly class ParametrosDeAcomodo
{
    use ValidaMedidas;

    /** @var numeric-string */
    public string $fondoVaras;

    /** @var numeric-string */
    public string $separacionFilasVaras;

    /** @var numeric-string */
    public string $separacionBloquesVaras;

    public function __construct(
        string $fondoVaras,
        public int $filas = 1,
        public float $origenX = 0.0,
        public float $origenY = 0.0,
        string $separacionFilasVaras = '0',
        string $separacionBloquesVaras = '10',
        public Numeracion $numeracion = Numeracion::Serpentina,
    ) {
        $this->fondoVaras = $this->medidaPositiva('fondoVaras', $fondoVaras);
        $this->separacionFilasVaras = $this->medidaNoNegativa('separacionFilasVaras', $separacionFilasVaras);
        $this->separacionBloquesVaras = $this->medidaNoNegativa('separacionBloquesVaras', $separacionBloquesVaras);

        $this->enteroPositivo('filas', $filas);
    }

    /**
     * Alto que ocupa un bloque completo, en varas.
     */
    public function altoDeBloqueVaras(): float
    {
        return $this->filas * (float) $this->fondoVaras
            + ($this->filas - 1) * (float) $this->separacionFilasVaras;
    }

    /**
     * Los mismos parametros, corridos hacia abajo.
     *
     * Es lo que usa el acomodador del proyecto para apilar un bloque
     * debajo del anterior sin que el usuario tenga que calcular offsets.
     */
    public function conOrigenY(float $origenY): self
    {
        return new self(
            fondoVaras: $this->fondoVaras,
            filas: $this->filas,
            origenX: $this->origenX,
            origenY: $origenY,
            separacionFilasVaras: $this->separacionFilasVaras,
            separacionBloquesVaras: $this->separacionBloquesVaras,
            numeracion: $this->numeracion,
        );
    }
}
