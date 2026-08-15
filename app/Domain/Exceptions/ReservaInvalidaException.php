<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

/**
 * No se puede guardar ese lote para herencia, o no se puede soltar.
 *
 * Vive aparte de `CompromisoInvalidoException` a proposito: una reserva NO
 * es un compromiso. No tiene a nadie del otro lado, no tiene fecha de
 * vencimiento, no tiene seña y no escribe ni una fila en `compromisos`
 * —`EstadoLote::estaComprometido()` devuelve false para el reservado, y no
 * es un descuido—. Meter estos mensajes en la excepcion de los compromisos
 * seria la primera grieta por la que despues se cuela la idea de que una
 * herencia es un apartado sin plata.
 *
 * Los mensajes explican QUE hacer, no solo que fallo: quien los lee es
 * alguien atendiendo a un cliente en ventanilla, no un programador.
 *
 * ⚠️ Adentro se dice «herencia» y afuera «reservado». Estos mensajes son
 * de adentro. Ver EstadoLote::etiquetaInterna().
 */
final class ReservaInvalidaException extends GrupoOlympoException
{
    public static function porGuardarLoQueNoEstaLibre(string $codigo, string $estado): self
    {
        return new self(
            "El lote {$codigo} esta {$estado} y solo se puede guardar para herencia un lote ".
            'disponible. Si esta apartado o vendido, primero hay que deshacer eso —que lleva '.
            'devolucion de seña o rescision— y recien despues guardarlo.'
        );
    }

    /**
     * El cupo de herencia del desarrollo, lleno.
     *
     * El mensaje dice DONDE se cambia el numero porque la respuesta no es
     * «no se puede»: es «esto no estaba decidido, decidilo y volve».
     */
    public static function porCupoDeHerenciaLleno(string $codigo, int $cupo, int $guardados): self
    {
        return new self(
            "El lote {$codigo} no se puede guardar: este desarrollo declaro {$cupo} lotes para ".
            "herencia y ya lleva {$guardados}. Si de verdad se va a guardar otro, primero hay ".
            'que subir el numero en la ficha del proyecto, pestaña Estado, seccion Herencia.'
        );
    }

    /**
     * Sin motivo no se guarda, y es la regla que sostiene todo lo demas.
     *
     * Un lote guardado para herencia no genera ninguna venta, ningun
     * recibo y ninguna cartera. Dentro de un año, el motivo escrito en las
     * observaciones es LO UNICO que va a explicar por que ese lote nunca
     * dio un lempira.
     */
    public static function porGuardarSinMotivo(string $codigo): self
    {
        return new self(
            "Falta decir por que se guarda el lote {$codigo}. Es lo unico que despues explica ".
            'por que ese lote no esta a la venta.'
        );
    }

    public static function porSacarLoQueNoEstaGuardado(string $codigo, string $estado): self
    {
        return new self(
            "El lote {$codigo} esta {$estado}, no guardado para herencia, asi que no hay nada ".
            'que sacar.'
        );
    }

    public static function porSacarSinMotivo(string $codigo): self
    {
        return new self(
            "Falta decir por que el lote {$codigo} vuelve a la venta. Queda anotado con tu ".
            'usuario y la fecha, al lado del motivo por el que se habia guardado.'
        );
    }
}
