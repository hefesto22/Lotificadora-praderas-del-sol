<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

use App\Domain\Enums\FormaDePago;
use App\Domain\ValueObjects\Monto;

/**
 * No se puede comprometer ese lote de esa manera.
 *
 * Los mensajes explican QUE hacer, no solo que fallo: quien los lee es
 * alguien atendiendo a un cliente en ventanilla, no un programador.
 */
final class CompromisoInvalidoException extends GrupoOlympoException
{
    public static function porLoteNoDisponible(string $codigo, string $estado): self
    {
        return new self(
            "El lote {$codigo} esta {$estado} y solo se puede apartar un lote disponible. ".
            'Si el apartado anterior se cayo, primero hay que liberarlo.'
        );
    }

    public static function porLoteYaVendido(string $codigo): self
    {
        return new self(
            "El lote {$codigo} ya esta vendido. Deshacer una venta es una rescision, ".
            'no se hace cambiandole el estado al lote (§8.2).'
        );
    }

    public static function porLoteCancelado(string $codigo): self
    {
        return new self(
            "El lote {$codigo} esta cancelado. Hay que reactivarlo desde su ficha antes ".
            'de comprometerlo con alguien.'
        );
    }

    /**
     * El lote quedo comprometido antes de que existiera esta tabla.
     *
     * Es el caso de los lotes que ya estaban apartados o vendidos cuando
     * se cargo el sistema: el estado dice que estan comprometidos pero no
     * hay registro de con quien.
     */
    public static function porFaltarCompromisoVigente(string $codigo, string $estado): self
    {
        return new self(
            "El lote {$codigo} figura como {$estado} pero no tiene un compromiso registrado. ".
            'Suele pasar con los lotes que ya estaban comprometidos antes de que el sistema '.
            'llevara este registro: hay que cargar el compromiso desde la ficha del lote, '.
            'con el cliente y la fecha que correspondan.'
        );
    }

    public static function porClienteDistinto(string $codigo, string $clienteDelApartado): self
    {
        return new self(
            "El lote {$codigo} esta apartado a nombre de {$clienteDelApartado}. ".
            'Para venderselo a otra persona hay que liberar el apartado primero, y eso '.
            'deberia quedar conversado con quien lo tenia.'
        );
    }

    public static function porVentaNoSeLibera(string $codigo): self
    {
        return new self(
            "El lote {$codigo} tiene una venta registrada, no un apartado. Una venta no se ".
            'libera: se rescinde, y ese tramite todavia no esta en el sistema.'
        );
    }

    /**
     * R4: un descuento sin motivo no se graba.
     *
     * La contratante contesto «se negocia caso por caso», y lo que aporta
     * el sistema es que despues se pueda saber quien autorizo que. Sin
     * motivo escrito, el descuento es indistinguible de un error de tipeo.
     */
    public static function porDescuentoSinMotivo(string $codigo, Monto $lista, Monto $pactado): self
    {
        return new self(
            "El lote {$codigo} se esta vendiendo a {$pactado->formateado()} la vara² cuando ".
            "el precio de lista es {$lista->formateado()}. Un precio menor se puede registrar, ".
            'pero hay que escribir el motivo: queda anotado con el usuario y la fecha.'
        );
    }

    /**
     * R11: la seña es dinero, y el dinero entra de una forma conocida.
     *
     * No se asume efectivo. Un apartado pagado por transferencia y grabado
     * como efectivo es un recibo que nunca va a cruzar contra el banco, y el
     * error se descubre meses despues, cuando ya nadie se acuerda de como
     * fue.
     */
    public static function porSeniaSinFormaDePago(string $codigo, Monto $senia): self
    {
        return new self(
            "La seña de {$senia->formateado()} del lote {$codigo} no dice como entro. Hay que ".
            'elegir efectivo, transferencia o deposito: es lo que va impreso en el recibo que '.
            'se lleva el cliente.'
        );
    }

    /**
     * R11: sin numero de referencia no hay con que cruzarlo contra el banco.
     */
    public static function porSeniaSinReferencia(string $codigo, FormaDePago $forma): self
    {
        return new self(
            "La seña del lote {$codigo} entro por ".mb_strtolower($forma->etiqueta()).' y falta el '.
            'numero de referencia. Es lo unico que despues permite encontrar ese movimiento en '.
            'el estado de cuenta del banco; en efectivo no hace falta.'
        );
    }
}
