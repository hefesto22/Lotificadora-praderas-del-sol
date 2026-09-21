<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

use App\Domain\ValueObjects\Monto;

/**
 * Ese recibo no se puede re-imputar así.
 *
 * Los mensajes le hablan a quien está corrigiendo un expediente contra el
 * cuaderno: dicen qué está mal en lo que pidió y qué camino sí sirve, no que
 * «falló». Ver `ReimputacionDeRecibo`.
 */
final class ReimputacionInvalidaException extends GrupoOlympoException
{
    public static function porFaltarElMotivo(): self
    {
        return new self(
            'Falta el motivo. Re-imputar un recibo le cambia el saldo a cada lote del cliente: '
            .'tiene que quedar escrito por qué, con las palabras de quien lo pidió.'
        );
    }

    public static function porNoNombrarLotes(): self
    {
        return new self(
            'No se nombró ningún lote. Va uno por cada lote que recibe dinero de este recibo, con '
            .'cuánto le toca. Ejemplo: --lote=ABC-T-001:32500.00'
        );
    }

    public static function porReciboDeOtroExpediente(string $folio, string $contrato): self
    {
        return new self(
            "El recibo {$folio} no es del expediente {$contrato}. Revisar el número del recibo y el "
            .'del expediente: un recibo mal elegido le mueve el dinero a otro cliente.'
        );
    }

    public static function porReciboYaAnulado(string $folio, string $motivo): self
    {
        return new self(
            "El recibo {$folio} ya está anulado («{$motivo}»). Si fue por una re-imputación, ya está "
            .'hecha: el recibo que lo reemplaza está en el expediente. No se escribió nada.'
        );
    }

    /**
     * Los papeles que imprimió el sistema dicen a qué lote fue cada lempira.
     */
    public static function porNoSerDeLaCarteraVieja(string $folio): self
    {
        return new self(
            "El recibo {$folio} lo imprimió el sistema, y ese papel —el que el cliente tiene en la "
            .'mano— dice a qué lote fue cada lempira. No se re-imputa por acá: se anula desde el '
            .'expediente y se vuelve a cobrar bien repartido, con su recibo nuevo para el cliente. '
            .'Esta corrección es para los recibos de la cartera anterior al sistema, donde el '
            .'reparto lo decidió la carga y no un papel.'
        );
    }

    public static function porSerUnProntoPago(string $folio): self
    {
        return new self(
            "El recibo {$folio} es un pronto pago: dio por terminado un lote con un descuento que se "
            .'acordó para ESE lote. Moverlo a otro lote es otro trato con el cliente, no una '
            .'corrección: se anula y se hace de nuevo.'
        );
    }

    public static function porHaberSalidoConOtros(string $folio): self
    {
        return new self(
            "El recibo {$folio} salió junto con otros papeles del mismo cobro. Re-imputar uno solo "
            .'dejaría el cobro a medias: se anula el cobro entero desde el expediente y se vuelve a '
            .'registrar.'
        );
    }

    public static function porConceptoQueNoSeReimputa(string $folio, string $concepto): self
    {
        return new self(
            "El recibo {$folio} es de {$concepto}. Solo se re-imputan los cobros de cuotas y los "
            .'abonos a capital: una prima o una seña no se reparten entre cuotas, y moverlas es '
            .'deshacer la venta o el apartado del que salieron.'
        );
    }

    public static function porLlevarInteresOMora(string $codigo): self
    {
        return new self(
            "El lote {$codigo} cobra interés o mora. Mover un pago de lote cambia cuánto interés y "
            .'cuánta mora corrió en cada uno, y eso ya no es un reparto sino un recálculo: se decide '
            .'caso por caso, no por acá.'
        );
    }

    public static function porLoteQueNoEsDeLaVenta(string $codigo): self
    {
        return new self("El lote {$codigo} no es de este expediente. Revisar el código del lote.");
    }

    public static function porLoteQueYaNoEstaVivo(string $codigo): self
    {
        return new self(
            "El lote {$codigo} ya no está vigente en este expediente (se rescindió o se liberó): "
            .'no puede recibir un pago.'
        );
    }

    public static function porLoteRepetido(string $codigo): self
    {
        return new self("El lote {$codigo} está dos veces en el pedido.");
    }

    public static function porMontoEnCero(string $codigo): self
    {
        return new self(
            "Al lote {$codigo} se le pidió imputar L 0.00. El lote que no recibe nada de este "
            .'recibo simplemente no se nombra.'
        );
    }

    /**
     * La red contra el dedo mal puesto: el papel dice un monto y no se cambia.
     */
    public static function porNoSumarElRecibo(string $folio, Monto $pedido, Monto $recibo): self
    {
        return new self(
            "Lo pedido suma {$pedido->formateado()} y el recibo {$folio} es de {$recibo->formateado()}. "
            .'Re-imputar reparte el MISMO dinero de otra manera: no puede sobrar ni faltar un centavo.'
        );
    }

    public static function porYaEstarImputadoAsi(string $folio): self
    {
        return new self("El recibo {$folio} ya está imputado exactamente así. No se escribió nada.");
    }

    public static function porReciboSinDatos(string $folio, string $queFalta): self
    {
        return new self(
            "El recibo {$folio} no tiene {$queFalta}, y sin eso no se puede volver a registrar igual "
            .'que como entró. No se escribió nada.'
        );
    }

    public static function porNoQuedarDondeDebia(string $queCosa, Monto $quedo, Monto $esperado): self
    {
        return new self(
            "{$queCosa}: quedó en {$quedo->formateado()} y tenía que quedar en {$esperado->formateado()}. "
            .'No se escribió NADA. Es un error del cálculo, no de los datos: reportarlo.'
        );
    }
}
