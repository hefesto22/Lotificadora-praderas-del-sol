<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

/**
 * Se intentó borrar un proyecto que ya tiene movimiento comercial.
 *
 * Borrar un proyecto se lleva puestos sus bloques, sus lotes, sus calles y
 * sus compromisos. Eso está bien para un proyecto de prueba y está mal para
 * uno donde alguien ya apartó o compró: ahí hay un cliente, un recibo y un
 * histórico que no se tiran por un clic.
 *
 * La regla es la misma que usa el seeder del plano para no pisar geometría
 * (§8.2): si un solo lote dejó de estar DISPONIBLE, el proyecto no se borra.
 * Primero se libera, y ahí se decide.
 */
final class ProyectoConMovimientoException extends GrupoOlympoException
{
    public static function porLotesNoDisponibles(string $codigo, int $lotes): self
    {
        return new self(
            "El proyecto {$codigo} tiene {$lotes} lote(s) que no están disponibles: ".
            'no se puede borrar. Liberalos primero si de verdad querés eliminarlo.'
        );
    }
}
