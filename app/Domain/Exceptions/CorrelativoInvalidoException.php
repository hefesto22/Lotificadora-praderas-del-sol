<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

use App\Domain\Enums\TipoCorrelativo;

/**
 * Algo anda mal con la serie de numeros.
 *
 * Estos mensajes son distintos a los del resto del dominio: no los va a
 * leer quien atiende en ventanilla, porque ninguno de estos casos deberia
 * llegarle a un usuario. Son errores de programacion, y el mensaje tiene
 * que decirle a quien programa exactamente que hizo mal.
 */
final class CorrelativoInvalidoException extends GrupoOlympoException
{
    /**
     * El error que este proyecto no se puede dar el lujo de cometer.
     *
     * `lockForUpdate()` fuera de una transaccion **no bloquea nada**:
     * Postgres suelta el lock al terminar la sentencia. El codigo parece
     * correcto, los tests de un solo hilo pasan, y el dia que dos
     * receptores cobran al mismo tiempo salen dos recibos con el mismo
     * numero. Por eso el Service se planta en vez de confiar.
     */
    public static function porFaltarTransaccion(TipoCorrelativo $tipo): self
    {
        return new self(
            "El correlativo de {$tipo->etiqueta()} se pidio fuera de una transaccion. ".
            'Sin transaccion, el SELECT ... FOR UPDATE no bloquea nada y dos procesos '.
            'simultaneos sacan el mismo numero (§8.3.6). Envolver la operacion completa '.
            'en DB::transaction() y volver a llamar desde adentro.'
        );
    }

    public static function porSerieQueNoSePudoCrear(TipoCorrelativo $tipo): self
    {
        return new self(
            "No se pudo abrir ni recuperar la serie de {$tipo->etiqueta()}. ".
            'Revisar los CHECK de la tabla correlativos: el alcance del tipo '.
            '(por proyecto o global) tiene que coincidir con el enum TipoCorrelativo.'
        );
    }

    public static function porProyectoSinCodigo(int $proyectoId): self
    {
        return new self(
            "El proyecto {$proyectoId} no tiene codigo, y el codigo es el prefijo del ".
            'numero de contrato (RPS-2026-0001). Asignarselo antes de numerar una venta.'
        );
    }
}
