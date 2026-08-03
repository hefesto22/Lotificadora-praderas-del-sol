<?php

declare(strict_types=1);

namespace App\Domain\Plano\Dxf;

/**
 * Unidad de dibujo declarada en la variable $INSUNITS del HEADER.
 *
 * OJO con SinUnidad: es MUY frecuente en planos de topografia y NO
 * significa "adimensional". Significa que el dibujante nunca configuro la
 * variable. La unidad real casi siempre es metros, pero adivinarlo en
 * silencio seria exactamente el tipo de suposicion que despues aparece
 * como un area equivocada en un contrato. Cuando llega asi, el importador
 * le pregunta al usuario.
 */
enum UnidadDxf: int
{
    case SinUnidad = 0;
    case Pulgadas = 1;
    case Pies = 2;
    case Millas = 3;
    case Milimetros = 4;
    case Centimetros = 5;
    case Metros = 6;
    case Kilometros = 7;
    case Yardas = 10;
    case Decimetros = 14;
    case PiesTopograficos = 21;

    public static function desde(?int $valor): self
    {
        return $valor === null ? self::SinUnidad : (self::tryFrom($valor) ?? self::SinUnidad);
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::SinUnidad        => 'Sin declarar en el archivo',
            self::Pulgadas         => 'Pulgadas',
            self::Pies             => 'Pies',
            self::Millas           => 'Millas',
            self::Milimetros       => 'Milímetros',
            self::Centimetros      => 'Centímetros',
            self::Metros           => 'Metros',
            self::Kilometros       => 'Kilómetros',
            self::Yardas           => 'Yardas',
            self::Decimetros       => 'Decímetros',
            self::PiesTopograficos => 'Pies topográficos (US Survey Feet)',
        };
    }

    /**
     * Cuantos metros mide una unidad de dibujo.
     *
     * null para SinUnidad: no hay factor honesto que devolver y forzar uno
     * seria inventar. El importador tiene que preguntar.
     *
     * El pie topografico NO es el pie internacional: difiere en 2 partes
     * por millon, que sobre coordenadas UTM de siete digitos son varios
     * metros. Por eso tiene su propio caso.
     */
    public function enMetros(): ?float
    {
        return match ($this) {
            self::SinUnidad        => null,
            self::Pulgadas         => 0.0254,
            self::Pies             => 0.3048,
            self::Millas           => 1609.344,
            self::Milimetros       => 0.001,
            self::Centimetros      => 0.01,
            self::Metros           => 1.0,
            self::Kilometros       => 1000.0,
            self::Yardas           => 0.9144,
            self::Decimetros       => 0.1,
            self::PiesTopograficos => 1200.0 / 3937.0,
        };
    }
}
