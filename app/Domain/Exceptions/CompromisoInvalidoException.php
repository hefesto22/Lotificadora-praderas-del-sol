<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

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
}
