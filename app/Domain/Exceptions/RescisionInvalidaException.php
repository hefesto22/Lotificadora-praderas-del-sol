<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

use App\Domain\ValueObjects\Monto;

/**
 * No se puede rescindir ese lote, o no con esos numeros.
 *
 * Vive aparte de `CompromisoInvalidoException` por la misma razon que
 * `ReservaInvalidaException`: una rescision no deshace un apartado, deshace
 * un CONTRATO FIRMADO. Quien lee estos mensajes esta por soltar un lote que
 * el cliente pago durante meses, y merece que el sistema le diga exactamente
 * que esta mal antes de dejarlo seguir.
 */
final class RescisionInvalidaException extends GrupoOlympoException
{
    /**
     * Un apartado no se rescinde: se libera.
     *
     * Son dos tramites distintos y el sistema tiene los dos. Mandar un
     * apartado por acá le quemaria un numero de la serie de devoluciones y
     * lo dejaria rotulado como rescision en el papel del cliente.
     */
    public static function porNoSerUnaVenta(string $codigo): self
    {
        return new self(
            "El lote {$codigo} no esta vendido, asi que no hay contrato que rescindir. Si esta ".
            'apartado, lo que corresponde es liberarlo y devolverle la seña.'
        );
    }

    public static function porNoEstarVigente(string $codigo, string $estado): self
    {
        return new self(
            "El lote {$codigo} figura {$estado} y solo se rescinde un lote vigente. Si ya se ".
            'rescindio antes, el acta con los montos esta en el expediente.'
        );
    }

    public static function porVentaCerrada(string $contrato, string $estado): self
    {
        return new self(
            "El expediente {$contrato} esta {$estado}: un contrato cerrado ya no se mueve. Una ".
            'venta liquidada se pago entera, y deshacerla es otra decision que no toma el sistema.'
        );
    }

    /**
     * El tope de lo que se puede devolver es lo que entro por ESE lote.
     *
     * No lo que entro por el contrato: si el expediente lleva tres lotes y
     * se cae uno, la plata de los otros dos sigue siendo de una venta viva.
     */
    public static function porDevolverDeMas(Monto $devuelto, Monto $recibido, string $codigo): self
    {
        return new self(
            "Por el lote {$codigo} entraron {$recibido->formateado()} entre prima y cuotas, y se ".
            "estan devolviendo {$devuelto->formateado()}. No se puede devolver mas de lo que entro."
        );
    }

    /**
     * Sin motivo escrito no se rescinde.
     *
     * Mismo trato que el descuento de R4, el abono de R21 y la devolucion de
     * seña. Acá pesa mas: dentro de un año, esto es lo unico que va a
     * explicar por que un lote que estaba vendido volvio a estar disponible y
     * por que la lotificadora se quedo con la plata de alguien.
     */
    public static function porFaltarElMotivo(string $codigo): self
    {
        return new self(
            "Falta decir por que se rescinde el lote {$codigo}. Queda impreso en el acta que firma ".
            'el cliente, con tu usuario y la fecha.'
        );
    }

    /**
     * R11, del lado de la salida.
     */
    public static function porFaltarLaReferencia(string $forma): self
    {
        return new self(
            "Una devolucion por {$forma} necesita el numero de referencia: es lo unico que despues ".
            'permite cruzar esta salida contra el estado de cuenta del banco (R11).'
        );
    }

    /**
     * Por un lote que no recibio ni un lempira no se emite acta de
     * liquidacion.
     *
     * No deberia pasar —una venta nace vigente cuando la prima se paga
     * COMPLETA (R5)— pero puede con cartera vieja importada con prima cero.
     * El CHECK `devoluciones_montos_no_negativos_chk` exige
     * `monto_recibido > 0`, asi que sin este mensaje el error que veria la
     * administradora seria el de Postgres.
     */
    public static function porNoHaberRecibidoNada(string $codigo): self
    {
        return new self(
            "Por el lote {$codigo} no hay ni un lempira registrado como recibido, asi que no hay ".
            'nada que liquidar. Revisá si a este expediente le falta cargarle los pagos.'
        );
    }
}
