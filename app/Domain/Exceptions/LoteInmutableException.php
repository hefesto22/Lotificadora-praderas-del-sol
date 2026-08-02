<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

/**
 * Se intentó modificar área, precio o valor de un lote ya vendido (§8.2).
 *
 * El valor que vale para una venta es el congelado en `venta_lote`. Si el
 * lote se pudiera reeditar, el histórico de una venta cerrada cambiaría
 * retroactivamente y el estado de cuenta del cliente dejaría de cuadrar.
 */
final class LoteInmutableException extends GrupoOlympoException
{
    public static function porEstadoVendido(string $identificador): self
    {
        return new self(
            "El lote {$identificador} está vendido: no se pueden modificar área, precio ni valor. ".
            'El valor vigente para la venta es el congelado en venta_lote (§8.2).'
        );
    }
}
